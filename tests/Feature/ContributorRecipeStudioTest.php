<?php

namespace Tests\Feature;

use App\Filament\Resources\Recipes\Pages\CreateRecipe;
use App\Livewire\ContributorRecipeStudio;
use App\Models\Category;
use App\Models\Ingredient;
use App\Models\Media;
use App\Models\Recipe;
use App\Models\Tag;
use App\Models\Unit;
use App\Models\User;
use App\Services\RecipeAccessService;
use App\Services\RecipePublishingService;
use App\Services\RecipeRevisionService;
use App\Services\RecipeRevisionSnapshotter;
use App\Services\Recipes\RecipeEditorService;
use App\Support\AppPermissions;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class ContributorRecipeStudioTest extends TestCase
{
    use RefreshDatabase;

    private function data(string $suffix = ''): array
    {
        return [
            'en' => ['title' => 'Lemon soup', 'slug' => 'lemon-soup'.$suffix, 'summary' => 'A simple soup'],
            'el' => ['title' => 'Σούπα λεμονιού', 'slug' => 'soupa-lemoniou'.$suffix, 'summary' => 'Μια απλή σούπα'],
            'prep_minutes' => 10, 'cook_minutes' => 20, 'rest_minutes' => 0, 'base_servings' => 4, 'difficulty' => 'Easy',
            'categories' => [Category::factory()->create()->id], 'tags' => [],
            'ingredient_groups_data' => [['names' => ['en' => 'Soup', 'el' => 'Σούπα']]],
            'ingredients_data' => [['ingredient_id' => Ingredient::factory()->create()->id, 'group_index' => 0, 'quantity' => '1.2500', 'quantity_max' => '2.5000', 'unit_id' => Unit::factory()->create()->id, 'scaling_mode' => 'linear', 'is_optional' => false]],
            'steps_data' => [['instruction_en' => 'Stir.', 'instruction_el' => 'Ανακατέψτε.', 'duration_minutes' => 5]],
        ];
    }

    public function test_only_authorized_contributors_can_open_their_own_studio(): void
    {
        $this->get('/en/recipe-studio/create')->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get('/en/recipe-studio/create')->assertForbidden();
        $actor = User::factory()->contributor()->create();
        $this->actingAs($actor)->get('/en/recipe-studio/create')->assertOk()->assertSee('Your Recipe Studio')->assertSee('Create private draft')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')->assertDontSee('Feature on homepage');
        $this->get('/el/recipe-studio/create')->assertOk()->assertSee('Το εργαστήρι συνταγών σου');
        $this->get('/en/recipe-studio/'.Recipe::factory()->create()->id)->assertNotFound();
        $this->get('/admin/recipes/create')->assertForbidden();
        $this->assertDatabaseCount('recipes', 1);
    }

    public function test_actual_frontend_and_filament_forms_persist_equivalent_recipes(): void
    {
        config(['cooking.step_groups_enabled' => true]);
        $data = $this->data('-admin');
        $data['step_groups_data'] = [['key' => 'preparation', 'translations' => [
            'en' => ['title' => 'Preparation'], 'el' => ['title' => 'Προετοιμασία'],
        ]]];
        $data['steps_data'][0]['step_group_key'] = 'preparation';
        Filament::setCurrentPanel('admin');
        Livewire::actingAs(User::factory()->admin()->create())->test(CreateRecipe::class)->fillForm($data)->call('create')->assertHasNoFormErrors();
        $panel = Recipe::query()->firstOrFail();
        $data['en']['slug'] = 'lemon-soup-front';
        $data['el']['slug'] = 'soupa-lemoniou-front';
        Filament::setCurrentPanel(null);
        $actor = User::factory()->contributor()->create();
        $component = Livewire::actingAs($actor)->test(ContributorRecipeStudio::class, ['locale' => 'en'])->fillForm($data)->call('save')->assertHasNoErrors();
        $front = Recipe::findOrFail($component->get('recipeId'));
        $snapshotter = app(RecipeRevisionSnapshotter::class);
        $first = $snapshotter->serialize($panel->id);
        $second = $snapshotter->serialize($front->id);
        foreach (['en', 'el'] as $locale) {
            $first['translations'][$locale]['slug'] = $second['translations'][$locale]['slug'];
        }
        $this->assertNotSame($first['ingredients'][0]['line_uuid'], $second['ingredients'][0]['line_uuid']);
        foreach ($first['ingredients'] as $index => &$line) {
            $line['line_uuid'] = $second['ingredients'][$index]['line_uuid'];
        }
        unset($line);
        $this->assertSame($snapshotter->hash($first), $snapshotter->hash($second));
        $this->assertSame('Preparation', $front->stepGroups()->first()->translate('en')->title);
        $this->assertSame($front->stepGroups()->first()->id, $front->steps()->first()->recipe_step_group_id);
        $this->assertSame('private', $front->visibility->value);
        $this->assertFalse($front->is_featured);
    }

    public function test_autosave_rejects_stale_versions_and_cannot_publish(): void
    {
        $actor = User::factory()->contributor()->create();
        $recipe = Recipe::factory()->complete()->create(['author_id' => $actor->id]);
        $a = Livewire::actingAs($actor)->test(ContributorRecipeStudio::class, ['locale' => 'en', 'recipeId' => $recipe->id]);
        $b = Livewire::test(ContributorRecipeStudio::class, ['locale' => 'en', 'recipeId' => $recipe->id]);
        $a->set('data.en.title', 'Newest title')->call('autosave')->assertHasNoErrors();
        $b->set('data.en.title', 'Stale title')->call('autosave')->assertHasErrors('draft');
        $this->assertSame('Newest title', $recipe->fresh()->getTranslation('en', false)->title);
        $this->assertSame('draft', $recipe->fresh()->status);
    }

    public function test_public_edits_use_a_revision_and_leave_published_content_unchanged(): void
    {
        $actor = User::factory()->contributor()->create();
        $recipe = app(RecipePublishingService::class)->publish(Recipe::factory()->complete()->create(['author_id' => $actor->id]))->fresh();
        $title = $recipe->getTranslation('en', false)->title;
        $component = Livewire::actingAs($actor)->test(ContributorRecipeStudio::class, ['locale' => 'en', 'recipeId' => $recipe->id]);
        $this->assertDatabaseCount('recipe_revisions', 1);
        $component->call('startRevision')->set('data.en.title', 'Proposed title')->call('save')->assertHasNoErrors();
        $this->assertSame($title, $recipe->fresh()->getTranslation('en', false)->title);
        $this->assertSame('Proposed title', app(RecipeRevisionService::class)->openRevision($recipe)->payload['translations']['en']['title']);
        $component->call('submit')->assertHasNoErrors();
        $this->assertSame('in_review', app(RecipeRevisionService::class)->openRevision($recipe)->status->value);
        $component->call('save')->assertForbidden();
    }

    public function test_draft_uploads_are_private_and_first_submission_freezes_the_recipe(): void
    {
        Storage::fake('local');
        $actor = User::factory()->contributor()->create();
        $recipe = Recipe::factory()->complete()->create(['author_id' => $actor->id]);
        $component = Livewire::actingAs($actor)->test(ContributorRecipeStudio::class, ['locale' => 'en', 'recipeId' => $recipe->id]);
        $component->callAction('uploadImage', ['file' => UploadedFile::fake()->image('soup.jpg'), 'role' => 'cover', 'alt_text_en' => 'Soup', 'alt_text_el' => 'Σούπα', 'rights_confirmed' => true])->assertHasNoActionErrors();
        $cover = $recipe->media()->wherePivot('role', 'cover')->firstOrFail();
        $this->assertSame('local', $cover->disk);
        $component->call('submit')->assertHasNoErrors();
        $this->assertSame('pending', $recipe->fresh()->status);
        $component->call('save')->assertForbidden();
        $this->actingAs(User::factory()->create())->get('/media/'.$cover->id)->assertNotFound();
    }

    public function test_step_upload_and_video_survive_later_autosaves(): void
    {
        Storage::fake('local');
        $actor = User::factory()->contributor()->create();
        $recipe = Recipe::factory()->complete()->create(['author_id' => $actor->id]);
        $component = Livewire::actingAs($actor)->test(ContributorRecipeStudio::class, ['locale' => 'en', 'recipeId' => $recipe->id]);
        $component->callAction('uploadImage', ['file' => UploadedFile::fake()->image('step.jpg'), 'role' => 'step', 'step_index' => 0, 'alt_text_en' => 'Stir', 'alt_text_el' => 'Ανακάτεμα', 'rights_confirmed' => true])->assertHasNoActionErrors();
        $component->callAction('video', ['step_index' => 0, 'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'title_en' => 'Cooking', 'title_el' => 'Μαγείρεμα'])->assertHasNoActionErrors();
        $component->set('data.en.title', 'Another title')->call('autosave')->assertHasNoErrors();
        $step = $recipe->steps()->firstOrFail();
        $this->assertSame(2, $step->media()->count());
        $this->assertSame('image/webp', $step->media()->wherePivot('kind', 'image')->first()->mime_type);
        $this->assertSame('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', $step->media()->wherePivot('kind', 'video')->first()->url);
        $imageId = $step->media()->wherePivot('kind', 'image')->first()->id;
        $component->callAction('removeMedia', ['media_id' => $imageId])->assertHasNoActionErrors();
        $this->assertSame(1, $step->media()->count());
    }

    public function test_revision_media_and_video_are_not_attached_to_live_steps(): void
    {
        Storage::fake('local');
        $actor = User::factory()->contributor()->create();
        $recipe = app(RecipePublishingService::class)->publish(Recipe::factory()->complete()->create(['author_id' => $actor->id]))->fresh();
        $component = Livewire::actingAs($actor)->test(ContributorRecipeStudio::class, ['locale' => 'en', 'recipeId' => $recipe->id])->call('startRevision');
        $component->callAction('uploadImage', ['file' => UploadedFile::fake()->image('step.jpg'), 'role' => 'step', 'step_index' => 0, 'alt_text_en' => 'Stir', 'alt_text_el' => 'Ανακάτεμα', 'rights_confirmed' => true])->assertHasNoActionErrors();
        $component->callAction('video', ['step_index' => 0, 'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'title_en' => 'Cooking', 'title_el' => 'Μαγείρεμα'])->assertHasNoActionErrors();
        $component->call('autosave')->assertHasNoErrors();
        $this->assertSame(0, $recipe->steps()->first()->media()->count());
        $revision = app(RecipeRevisionService::class)->openRevision($recipe);
        $this->assertCount(2, $revision->payload['steps'][0]['media']);
        $image = Media::findOrFail($revision->payload['steps'][0]['media'][0]['media_id']);
        $this->assertSame('local', $image->disk);
        $component->callAction('removeMedia', ['media_id' => $image->id])->assertHasNoActionErrors();
        $this->assertCount(1, app(RecipeRevisionService::class)->openRevision($recipe)->payload['steps'][0]['media']);
        $this->actingAs(User::factory()->create())->get('/media/'.$image->id)->assertNotFound();
    }

    public function test_upload_rights_and_trusted_video_hosts_are_required(): void
    {
        Storage::fake('local');
        $actor = User::factory()->contributor()->create();
        $recipe = Recipe::factory()->complete()->create(['author_id' => $actor->id]);
        $component = Livewire::actingAs($actor)->test(ContributorRecipeStudio::class, ['locale' => 'en', 'recipeId' => $recipe->id]);
        $component->callAction('uploadImage', ['file' => UploadedFile::fake()->image('soup.jpg'), 'role' => 'cover', 'alt_text_en' => 'Soup', 'alt_text_el' => 'Σούπα', 'rights_confirmed' => false])->assertHasActionErrors(['rights_confirmed']);
        $this->assertSame([], Storage::disk('local')->allFiles());
        $component->unmountAction();
        $component->callAction('video', ['step_index' => 0, 'url' => 'https://example.com/video', 'title_en' => 'Cooking', 'title_el' => 'Μαγείρεμα'])->assertHasErrors('url');
        $this->assertSame(0, $recipe->steps()->first()->media()->count());
    }

    public function test_suggesting_a_dietary_claim_does_not_approve_or_attach_a_public_tag(): void
    {
        $actor = User::factory()->contributor()->create();
        $recipe = Recipe::factory()->complete()->create(['author_id' => $actor->id]);
        $tag = Tag::factory()->dietary()->create(['code' => 'vegetarian']);
        Livewire::actingAs($actor)->test(ContributorRecipeStudio::class, ['locale' => 'en', 'recipeId' => $recipe->id])
            ->callAction('suggestDietary', ['tag_id' => $tag->id])->assertHasNoActionErrors();
        $this->assertDatabaseHas('recipe_dietary_claims', ['recipe_id' => $recipe->id, 'tag_id' => $tag->id, 'status' => 'suggested']);
        $this->assertSame(0, $recipe->tags()->whereKey($tag->id)->count());
    }

    public function test_a_partial_bilingual_draft_can_be_started_without_publication_readiness(): void
    {
        $actor = User::factory()->contributor()->create();
        $component = Livewire::actingAs($actor)->test(ContributorRecipeStudio::class, ['locale' => 'el'])
            ->set('data.en.title', 'A work in progress')->call('save')->assertHasNoErrors();
        $recipe = Recipe::findOrFail($component->get('recipeId'));
        $this->assertSame('private', $recipe->visibility->value);
        $this->assertSame('draft', $recipe->status);
        $this->assertDatabaseCount('recipes', 1);
        $this->assertNotEmpty(app(RecipePublishingService::class)->publicationProblems($recipe));
    }

    public function test_forged_revision_media_ids_neither_reveal_metadata_nor_persist(): void
    {
        $actor = User::factory()->contributor()->create();
        $recipe = app(RecipePublishingService::class)->publish(Recipe::factory()->complete()->create(['author_id' => $actor->id]))->fresh();
        $foreign = Recipe::factory()->complete()->create();
        $media = $foreign->media()->first();
        $media->update(['en' => ['alt_text' => 'Foreign secret photograph']]);
        Livewire::actingAs($actor)->test(ContributorRecipeStudio::class, ['locale' => 'en', 'recipeId' => $recipe->id])->call('startRevision')
            ->set('data.media_data', [['media_id' => $media->id, 'role' => 'cover']])->assertDontSee('Foreign secret photograph')
            ->call('save')->assertHasErrors('media_data');
        $this->assertNotContains($media->id, array_column(app(RecipeRevisionService::class)->openRevision($recipe)->payload['media'], 'media_id'));
    }

    public function test_original_recipe_studio_does_not_depend_on_the_variation_feature_flag(): void
    {
        config(['recipe-variations.enabled' => false]);
        $actor = User::factory()->contributor()->create();
        $this->actingAs($actor)->get('/en/my-recipes?filter=original')->assertOk()->assertSee('/en/recipe-studio/create');
        $this->get('/en/recipe-studio/create')->assertOk();
        $this->get('/en/my-recipes?filter=variation')->assertNotFound();
    }

    public function test_translation_text_without_a_title_is_kept_in_the_form_instead_of_silently_dropped(): void
    {
        Livewire::actingAs(User::factory()->contributor()->create())->test(ContributorRecipeStudio::class, ['locale' => 'el'])
            ->set('data.en.title', 'Work in progress')->set('data.el.summary', 'Σημαντικές σημειώσεις')
            ->call('save')->assertHasErrors('el.title')->assertSet('data.el.summary', 'Σημαντικές σημειώσεις');
        $this->assertDatabaseCount('recipes', 0);
    }

    public function test_owner_can_open_studio_to_repair_a_revoked_component_without_seeing_child_content(): void
    {
        $actor = User::factory()->contributor()->create();
        $actor->givePermissionTo(AppPermissions::RECIPES_VIEW_SHARED);
        $sourceOwner = User::factory()->contributor()->create();
        $source = Recipe::factory()->complete()->create([
            'author_id' => $sourceOwner->id, 'visibility' => 'shared',
            'en' => ['title' => 'Revoked secret sauce', 'slug' => 'revoked-secret-sauce'],
        ]);
        $source->translateOrNew('en')->title = 'Revoked secret sauce';
        $source->save();
        $share = $source->shares()->create([
            'user_id' => $actor->id, 'permission' => 'view', 'shared_by' => $sourceOwner->id,
        ]);
        $this->actingAs($actor);
        $data = $this->data('-repair');
        $data['ingredients_data'][] = [
            'line_type' => 'recipe', 'component_recipe_id' => $source->id,
            'quantity' => 1, 'quantity_basis' => 'whole_batch', 'shopping_mode' => 'scratch', 'scaling_mode' => 'linear',
        ];
        $parent = app(RecipeEditorService::class)->create($actor, $data);
        $sourceTitle = $source->fresh()->getTranslation('en', false)->title;
        $share->update(['revoked_at' => now()]);
        $this->assertFalse(app(RecipeAccessService::class)->canView($actor, $parent->fresh()));
        $this->get(route('recipes.show', ['locale' => 'en', 'slug' => $parent->getTranslation('en', false)->slug]))->assertNotFound();
        $this->get(route('recipes.studio.edit', ['locale' => 'en', 'recipe' => $parent->id]))
            ->assertOk()->assertDontSee($sourceTitle)->assertSee(__('nested.unavailable'));
        $component = Livewire::actingAs($actor)->test(ContributorRecipeStudio::class, ['locale' => 'en', 'recipeId' => $parent->id])
            ->assertOk()->assertDontSee($sourceTitle)->assertSee(__('nested.unavailable'));
        $this->assertSame([], $component->instance()->reviewData()['payload']);
        $lines = array_filter($component->get('data.ingredients_data'), fn (array $line): bool => ($line['line_type'] ?? 'ingredient') !== 'recipe');
        $component->set('data.ingredients_data', $lines)->call('save')->assertHasNoErrors();
        $this->assertSame(0, $parent->components()->count());
        $this->assertTrue(app(RecipeAccessService::class)->canView($actor, $parent->fresh()));
        $this->actingAs(User::factory()->contributor()->create())
            ->get(route('recipes.studio.edit', ['locale' => 'en', 'recipe' => $parent->id]))->assertNotFound();
    }
}
