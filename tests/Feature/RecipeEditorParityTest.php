<?php

namespace Tests\Feature;

use App\Filament\Resources\RecipeRevisions\Pages\EditRecipeRevision;
use App\Filament\Resources\Recipes\Pages\CreateRecipe;
use App\Filament\Resources\Recipes\Pages\EditRecipe;
use App\Models\Category;
use App\Models\Ingredient;
use App\Models\Media;
use App\Models\Recipe;
use App\Models\Tag;
use App\Models\Unit;
use App\Models\User;
use App\Services\RecipePublishingService;
use App\Services\RecipeRevisionService;
use App\Services\RecipeRevisionSnapshotter;
use App\Services\RecipeRevisionValidator;
use App\Services\Recipes\RecipeEditorData;
use App\Services\Recipes\RecipeEditorMedia;
use App\Services\Recipes\RecipeEditorService;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\ContributorRecipeEditorHarness;
use Tests\TestCase;

class RecipeEditorParityTest extends TestCase
{
    use RefreshDatabase;

    private function data(string $suffix = ''): array
    {
        return [
            'en' => ['title' => 'Lemon soup', 'slug' => 'lemon-soup'.$suffix, 'summary' => 'A simple soup'],
            'el' => ['title' => 'Σούπα λεμονιού', 'slug' => 'soupa-lemoniou'.$suffix, 'summary' => 'Μια απλή σούπα'],
            'prep_minutes' => 10, 'cook_minutes' => 20, 'rest_minutes' => 0,
            'base_servings' => 4, 'difficulty' => 'Easy',
            'categories' => [Category::factory()->create()->id], 'tags' => [],
            'ingredient_groups_data' => [['names' => ['en' => 'Soup', 'el' => 'Σούπα']]],
            'ingredients_data' => [[
                'ingredient_id' => Ingredient::factory()->create()->id,
                'group_index' => 0, 'quantity' => '1.2500', 'quantity_max' => '2.5000',
                'unit_id' => Unit::factory()->create()->id, 'scaling_mode' => 'linear', 'is_optional' => false,
                'preparation_note_en' => 'chopped', 'preparation_note_el' => 'ψιλοκομμένο',
            ]],
            'steps_data' => [['instruction_en' => 'Stir.', 'instruction_el' => 'Ανακατέψτε.', 'duration_minutes' => 5]],
        ];
    }

    public function test_panel_and_standalone_contributor_forms_persist_equivalent_content(): void
    {
        $admin = User::factory()->admin()->create();
        $contributor = User::factory()->contributor()->create();
        config(['cooking.step_groups_enabled' => true]);
        $data = $this->data('-admin');
        $data['step_groups_data'] = [['key' => 'preparation', 'translations' => [
            'en' => ['title' => 'Preparation', 'description' => 'Prepare the soup.'],
            'el' => ['title' => 'Προετοιμασία', 'description' => 'Ετοιμάστε τη σούπα.'],
        ]]];
        $data['steps_data'][0]['step_group_key'] = 'preparation';
        Filament::setCurrentPanel('admin');
        Livewire::actingAs($admin)->test(CreateRecipe::class)->fillForm($data)->call('create')->assertHasNoFormErrors();
        $panelRecipe = Recipe::query()->where('author_id', $admin->id)->firstOrFail();
        $data['en']['slug'] = 'lemon-soup-contributor';
        $data['el']['slug'] = 'soupa-lemoniou-contributor';
        Filament::setCurrentPanel(null);
        $component = Livewire::actingAs($contributor)->test(ContributorRecipeEditorHarness::class)
            ->fillForm($data)->call('save')->assertHasNoFormErrors();
        $publicRecipe = Recipe::findOrFail($component->get('savedRecipeId'));
        $snapshotter = app(RecipeRevisionSnapshotter::class);
        $panel = $snapshotter->serialize($panelRecipe->id);
        $public = $snapshotter->serialize($publicRecipe->id);
        foreach (['en', 'el'] as $locale) {
            $panel['translations'][$locale]['slug'] = $public['translations'][$locale]['slug'];
        }
        // Independently created recipes have distinct durable ingredient identities.
        $this->assertNotSame($panel['ingredients'][0]['line_uuid'], $public['ingredients'][0]['line_uuid']);
        foreach ($panel['ingredients'] as $index => &$line) {
            $line['line_uuid'] = $public['ingredients'][$index]['line_uuid'];
        }
        unset($line);
        $this->assertSame($snapshotter->hash($panel), $snapshotter->hash($public));
        $this->assertSame('Preparation', $panelRecipe->stepGroups()->first()->translate('en')->title);
        $this->assertNotSame($panelRecipe->stepGroups()->first()->id, $publicRecipe->stepGroups()->first()->id);
        $this->assertSame($publicRecipe->stepGroups()->first()->id, $publicRecipe->steps()->first()->recipe_step_group_id);
        $this->assertSame('private', $publicRecipe->visibility->value);
        $this->assertSame('draft', $publicRecipe->status);
        $this->assertNotNull($publicRecipe->recipeIngredients()->first()->ingredient_group_id);
        $this->assertFalse($publicRecipe->is_featured);
    }

    public function test_service_rejects_invalid_units_and_rolls_back_creation(): void
    {
        $actor = User::factory()->contributor()->create();
        $this->actingAs($actor);
        $data = $this->data();
        $data['ingredients_data'][0]['unit_id'] = 999999;
        try {
            app(RecipeEditorService::class)->create($actor, $data);
            $this->fail('Invalid units must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('ingredients_data.0.unit_id', $exception->errors());
        }
        $this->assertDatabaseCount('recipes', 0);
    }

    public function test_drafts_can_be_incomplete_but_cannot_bypass_publication_or_editorial_fields(): void
    {
        $actor = User::factory()->contributor()->create();
        $this->actingAs($actor);
        $data = $this->data();
        $data['steps_data'] = [];
        $data['ingredients_data'] = [];
        unset($data['el']);
        $recipe = app(RecipeEditorService::class)->create($actor, [...$data, 'status' => 'published', 'visibility' => 'public', 'is_featured' => true, 'author_id' => 999999]);
        $this->assertSame($actor->id, $recipe->author_id);
        $this->assertSame('draft', $recipe->status);
        $this->assertFalse($recipe->is_featured);
        $service = app(RecipePublishingService::class);
        $this->assertNotEmpty($service->publicationProblems($recipe));
        $this->expectException(ValidationException::class);
        app(RecipeRevisionValidator::class)->assertSubmittable(app(RecipeRevisionSnapshotter::class)->normalize(app(RecipeRevisionSnapshotter::class)->serialize($recipe->id)), $recipe->id);
    }

    public function test_stale_draft_save_does_not_overwrite_a_newer_save(): void
    {
        $actor = User::factory()->contributor()->create();
        $this->actingAs($actor);
        $service = app(RecipeEditorService::class);
        $recipe = $service->create($actor, $this->data());
        $version = $service->version($recipe);
        $data = RecipeEditorData::fromPayload(app(RecipeRevisionSnapshotter::class)->serialize($recipe->id));
        $data['en']['title'] = 'New title';
        $service->update($actor, $recipe, $data, $version);
        try {
            $service->update($actor, $recipe, $data, $version);
            $this->fail('A stale save must fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('draft', $exception->errors());
        }
        $this->assertSame('New title', $recipe->fresh()->getTranslation('en', false)->title);
    }

    public function test_readers_cannot_use_the_shared_creation_service(): void
    {
        $reader = User::factory()->create();
        $this->actingAs($reader);
        $this->expectException(AuthorizationException::class);
        app(RecipeEditorService::class)->create($reader, $this->data());
    }

    public function test_revision_saves_reject_stale_versions_and_leave_live_content_unchanged(): void
    {
        $actor = User::factory()->contributor()->create();
        $recipe = Recipe::factory()->complete()->create(['author_id' => $actor->id]);
        $this->actingAs(User::factory()->admin()->create());
        app(RecipePublishingService::class)->publish($recipe);
        $recipe->refresh();
        $this->actingAs($actor);
        $service = app(RecipeRevisionService::class);
        $revision = $service->createDraft($actor, $recipe);
        $version = $service->draftVersion($revision);
        $data = RecipeEditorData::fromPayload($revision->payload);
        $data['en']['title'] = 'Revision title';
        app(RecipeEditorService::class)->saveRevision($actor, $revision, $data, $version);
        $this->assertNotSame('Revision title', $recipe->fresh()->getTranslation('en', false)->title);
        $this->expectException(ValidationException::class);
        app(RecipeEditorService::class)->saveRevision($actor, $revision, $data, $version);
    }

    public function test_revision_uploads_are_private_authorized_and_do_not_change_live_media(): void
    {
        Storage::fake('local');
        $actor = User::factory()->contributor()->create();
        $recipe = Recipe::factory()->complete()->create(['author_id' => $actor->id]);
        $this->actingAs(User::factory()->admin()->create());
        app(RecipePublishingService::class)->publish($recipe);
        $recipe->refresh();
        $liveMedia = $recipe->media()->pluck('media.id')->all();
        $this->actingAs($actor);
        $service = app(RecipeRevisionService::class);
        $revision = $service->createDraft($actor, $recipe);
        $version = $service->draftVersion($revision);
        $translations = ['en' => ['alt_text' => 'New soup'], 'el' => ['alt_text' => 'Νέα σούπα']];
        $mediaService = app(RecipeEditorMedia::class);
        $updated = $mediaService->upload($actor, $revision, UploadedFile::fake()->image('soup.jpg'), $translations, 'cover', $version, true);
        $id = collect($updated->payload['media'])->firstWhere('role', 'cover')['media_id'];
        $media = Media::findOrFail($id);
        $this->assertSame('local', $media->disk);
        $this->assertSame($liveMedia, $recipe->media()->pluck('media.id')->all());
        $this->get(route('media.show', $media))->assertOk();
        $this->actingAs(User::factory()->create())->get(route('media.show', $media))->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->get(route('media.show', $media))->assertNotFound();
        $this->actingAs($actor);
        $beforeFiles = Storage::disk('local')->allFiles();
        try {
            $mediaService->upload($actor, $revision, UploadedFile::fake()->image('stale.jpg'), $translations, 'gallery', $version, true);
            $this->fail('A stale media upload must fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('draft', $exception->errors());
        }
        $this->assertSame($beforeFiles, Storage::disk('local')->allFiles());
    }

    public function test_shared_publication_gate_reports_identical_errors_for_live_and_payload_content(): void
    {
        $recipe = Recipe::factory()->complete()->create();
        $recipe->media()->detach();
        $payload = app(RecipeRevisionSnapshotter::class)->normalize(app(RecipeRevisionSnapshotter::class)->serialize($recipe->id));
        $errors = [];
        foreach ([fn () => app(RecipePublishingService::class)->validateCompleteness($recipe), fn () => app(RecipeRevisionValidator::class)->assertSubmittable($payload, $recipe->id)] as $validate) {
            try {
                $validate();
                $this->fail('Missing cover must prevent publication.');
            } catch (ValidationException $exception) {
                $errors[] = $exception->errors();
            }
        }
        $this->assertSame($errors[0], $errors[1]);
        $this->assertArrayHasKey('media', $errors[0]);
    }

    public function test_foreign_step_ids_are_rejected_before_any_content_is_written(): void
    {
        $actor = User::factory()->contributor()->create();
        $this->actingAs($actor);
        $service = app(RecipeEditorService::class);
        $recipe = $service->create($actor, $this->data());
        $foreign = Recipe::factory()->complete()->create();
        $data = RecipeEditorData::fromRecipe($recipe);
        $data['en']['title'] = 'Must not persist';
        $data['steps_data'][0]['id'] = $foreign->steps()->first()->id;
        try {
            $service->update($actor, $recipe, $data, $service->version($recipe));
            $this->fail('Foreign step IDs must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('steps_data.0.id', $exception->errors());
        }
        $this->assertSame('Lemon soup', $recipe->fresh()->getTranslation('en', false)->title);
    }

    public function test_pending_recipes_cannot_be_edited_even_by_staff_through_the_editor(): void
    {
        $recipe = Recipe::factory()->complete()->create();
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        $recipe->update(['status' => 'pending']);
        $service = app(RecipeEditorService::class);
        $this->expectException(ValidationException::class);
        $service->update($admin, $recipe, RecipeEditorData::fromRecipe($recipe), $service->version($recipe));
    }

    public function test_revision_form_upload_keeps_new_media_on_a_subsequent_save(): void
    {
        Storage::fake('local');
        $author = User::factory()->contributor()->create();
        $recipe = Recipe::factory()->complete()->create(['author_id' => $author->id]);
        $this->actingAs(User::factory()->admin()->create());
        app(RecipePublishingService::class)->publish($recipe);
        $revision = app(RecipeRevisionService::class)->createDraft($author, $recipe->fresh());
        Filament::setCurrentPanel('admin');
        $component = Livewire::actingAs($author)->test(EditRecipeRevision::class, ['record' => $revision->id])
            ->callAction('uploadRevisionImage', [
                'file' => UploadedFile::fake()->image('new-cover.jpg'), 'role' => 'cover',
                'alt_text_en' => 'New cover', 'alt_text_el' => 'Νέα φωτογραφία', 'rights_confirmed' => true,
            ])->assertHasNoActionErrors();
        $mediaId = collect($revision->fresh()->payload['media'])->firstWhere('role', 'cover')['media_id'];
        $component->call('save')->assertHasNoFormErrors();
        $this->assertSame($mediaId, collect($revision->fresh()->payload['media'])->firstWhere('role', 'cover')['media_id']);
        $this->assertNotContains($mediaId, $recipe->media()->pluck('media.id')->all());
    }

    public function test_revision_editor_rejects_media_belonging_to_another_recipe(): void
    {
        $actor = User::factory()->contributor()->create();
        $recipe = Recipe::factory()->complete()->create(['author_id' => $actor->id]);
        $foreign = Recipe::factory()->complete()->create();
        $this->actingAs(User::factory()->admin()->create());
        app(RecipePublishingService::class)->publish($recipe);
        $this->actingAs($actor);
        $service = app(RecipeRevisionService::class);
        $revision = $service->createDraft($actor, $recipe->fresh());
        $data = RecipeEditorData::fromPayload($revision->payload);
        $data['media_data'][] = ['media_id' => $foreign->media()->first()->id, 'role' => 'gallery'];
        $this->expectException(ValidationException::class);
        app(RecipeEditorService::class)->saveRevision($actor, $revision, $data, $service->draftVersion($revision));
    }

    public function test_revision_upload_requires_rights_confirmation_before_storing_a_file(): void
    {
        Storage::fake('local');
        $actor = User::factory()->contributor()->create();
        $recipe = Recipe::factory()->complete()->create(['author_id' => $actor->id]);
        $this->actingAs(User::factory()->admin()->create());
        app(RecipePublishingService::class)->publish($recipe);
        $this->actingAs($actor);
        $service = app(RecipeRevisionService::class);
        $revision = $service->createDraft($actor, $recipe->fresh());
        try {
            app(RecipeEditorMedia::class)->upload($actor, $revision, UploadedFile::fake()->image('no-rights.jpg'), [
                'en' => ['alt_text' => 'Soup'], 'el' => ['alt_text' => 'Σούπα'],
            ], 'gallery', $service->draftVersion($revision), false);
            $this->fail('Rights confirmation is required.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('rightsConfirmed', $exception->errors());
        }
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_reordering_revision_media_changes_order_without_changing_live_relationships(): void
    {
        Storage::fake('local');
        $actor = User::factory()->contributor()->create();
        $recipe = Recipe::factory()->complete()->create(['author_id' => $actor->id]);
        $this->actingAs(User::factory()->admin()->create());
        app(RecipePublishingService::class)->publish($recipe);
        $this->actingAs($actor);
        $service = app(RecipeRevisionService::class);
        $revision = $service->createDraft($actor, $recipe->fresh());
        $revision = app(RecipeEditorMedia::class)->upload($actor, $revision, UploadedFile::fake()->image('gallery.jpg'), [
            'en' => ['alt_text' => 'Gallery'], 'el' => ['alt_text' => 'Φωτογραφία'],
        ], 'gallery', $service->draftVersion($revision), true);
        $data = RecipeEditorData::fromPayload($revision->payload);
        $data['media_data'] = array_reverse($data['media_data']);
        $saved = app(RecipeEditorService::class)->saveRevision($actor, $revision, $data, $service->draftVersion($revision));
        $this->assertSame(array_column($data['media_data'], 'media_id'), array_column($saved->payload['media'], 'media_id'));
        $this->assertCount(1, $recipe->media()->get());
    }

    public function test_group_reordering_preserves_the_ingredient_group_selection(): void
    {
        $actor = User::factory()->contributor()->create();
        $data = $this->data();
        $data['ingredient_groups_data'][] = ['names' => ['en' => 'Topping', 'el' => 'Γαρνιτούρα']];
        $component = Livewire::actingAs($actor)->test(ContributorRecipeEditorHarness::class)->fillForm($data);
        $groups = $component->get('data.ingredient_groups_data');
        $component->set('data.ingredient_groups_data', array_reverse($groups, true));
        $line = array_values($component->get('data.ingredients_data'))[0];
        $this->assertSame(1, $line['group_index']);
        $component->call('save')->assertHasNoFormErrors();
        $recipe = Recipe::findOrFail($component->get('savedRecipeId'));
        $this->assertSame('Soup', $recipe->recipeIngredients()->first()->ingredientGroup->getTranslation('en', false)->name);
    }

    public function test_revision_step_uploads_reuse_the_existing_image_optimizer_and_are_cleaned_on_discard(): void
    {
        Storage::fake('local');
        $actor = User::factory()->contributor()->create();
        $recipe = Recipe::factory()->complete()->create(['author_id' => $actor->id]);
        $this->actingAs(User::factory()->admin()->create());
        app(RecipePublishingService::class)->publish($recipe);
        $this->actingAs($actor);
        $service = app(RecipeRevisionService::class);
        $revision = $service->createDraft($actor, $recipe->fresh());
        $revision = app(RecipeEditorMedia::class)->upload($actor, $revision, UploadedFile::fake()->image('step.jpg'), [
            'en' => ['alt_text' => 'Stir'], 'el' => ['alt_text' => 'Ανακατέψτε'],
        ], 'step', $service->draftVersion($revision), true, 0);
        $mediaId = collect($revision->payload['steps'][0]['media'])->firstWhere('kind', 'image')['media_id'];
        $this->assertSame('image/webp', Media::findOrFail($mediaId)->mime_type);
        $this->assertSame(0, $recipe->steps()->first()->media()->count());
        $service->discard($actor, $revision);
        $this->assertDatabaseMissing('media', ['id' => $mediaId]);
        $this->assertDatabaseHas('media', ['id' => $recipe->media()->first()->id]);
    }

    public function test_cloning_an_existing_ingredient_creates_a_new_line_identity(): void
    {
        $actor = User::factory()->contributor()->create();
        $this->actingAs($actor);
        $recipe = app(RecipeEditorService::class)->create($actor, $this->data());
        Filament::setCurrentPanel('admin');
        $component = Livewire::test(EditRecipe::class, ['record' => $recipe->id]);
        $lines = $component->get('data.ingredients_data');
        $component->callAction(TestAction::make('clone')->schemaComponent('ingredients_data')->arguments(['item' => array_key_first($lines)]))
            ->call('save')->assertHasNoFormErrors();
        $this->assertSame(2, $recipe->recipeIngredients()->count());
        $this->assertSame(2, $recipe->recipeIngredients()->pluck('id')->unique()->count());
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_ordinary_tag_edits_preserve_protected_associations_in_drafts_and_revisions(bool $revision): void
    {
        $actor = User::factory()->contributor()->create();
        $this->actingAs($actor);
        $recipe = Recipe::factory()->complete()->create(['author_id' => $actor->id]);
        $old = Tag::factory()->create();
        $new = Tag::factory()->create();
        $dietary = Tag::factory()->dietary()->create();
        $inactive = Tag::factory()->create(['is_active' => false]);
        $recipe->tags()->attach([$old->id, $dietary->id, $inactive->id]);
        $editor = app(RecipeEditorService::class);

        if ($revision) {
            app(RecipePublishingService::class)->publish($recipe);
            $revisions = app(RecipeRevisionService::class);
            $target = $revisions->createDraft($actor, $recipe->fresh());
            $data = RecipeEditorData::fromPayload($target->payload);
        } else {
            $data = RecipeEditorData::fromRecipe($recipe);
        }
        $this->assertSame([$old->id], $data['tags']);
        $data['tags'] = [$new->id];

        if ($revision) {
            $saved = $editor->saveRevision($actor, $target, $data, $revisions->draftVersion($target));
            $this->assertEqualsCanonicalizing([$new->id, $dietary->id, $inactive->id], $saved->payload['tag_ids']);
            $this->assertEqualsCanonicalizing([$old->id, $dietary->id, $inactive->id], $recipe->tags()->pluck('tags.id')->all());
        } else {
            $saved = $editor->update($actor, $recipe, $data, $editor->version($recipe));
            $this->assertEqualsCanonicalizing([$new->id, $dietary->id, $inactive->id], $saved->tags()->pluck('tags.id')->all());
        }
        $this->assertDatabaseCount('recipe_dietary_claims', 0);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_ordinary_tag_fields_cannot_add_new_dietary_associations(bool $revision): void
    {
        $actor = User::factory()->contributor()->create();
        $this->actingAs($actor);
        $recipe = Recipe::factory()->complete()->create(['author_id' => $actor->id]);
        $dietary = Tag::factory()->dietary()->create();
        $editor = app(RecipeEditorService::class);
        if ($revision) {
            app(RecipePublishingService::class)->publish($recipe);
            $revisions = app(RecipeRevisionService::class);
            $target = $revisions->createDraft($actor, $recipe->fresh());
            $data = RecipeEditorData::fromPayload($target->payload);
        } else {
            $data = RecipeEditorData::fromRecipe($recipe);
        }
        $data['tags'] = [$dietary->id];
        try {
            if ($revision) {
                $editor->saveRevision($actor, $target, $data, $revisions->draftVersion($target));
            } else {
                $editor->update($actor, $recipe, $data, $editor->version($recipe));
            }
            $this->fail('Dietary associations require their separate workflow.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('tags.0', $exception->errors());
        }
        $this->assertDatabaseMissing('recipe_tag', ['recipe_id' => $recipe->id, 'tag_id' => $dietary->id]);
        $this->assertDatabaseCount('recipe_dietary_claims', 0);
    }
}
