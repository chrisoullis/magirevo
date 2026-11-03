<?php

namespace Tests\Feature;

use App\Actions\StoreRecipeMedia;
use App\Livewire\EditRecipeVariation;
use App\Livewire\MakeRecipeVariation;
use App\Livewire\PersonalRecipes;
use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\User;
use App\Services\Recipes\RecipeForkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\CreatesVariationSource;
use Tests\TestCase;

class RecipeVariationEditorTest extends TestCase
{
    use CreatesVariationSource;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['recipe-variations.enabled' => true]);
    }

    private function ownVariation(): array
    {
        $source = $this->source();
        $owner = User::factory()->create();
        $recipe = app(RecipeForkService::class)->fork($owner, $source, ['idempotency_key' => (string) Str::uuid()]);
        $this->actingAs($owner);

        return [$owner, $recipe, $source];
    }

    public function test_confirmation_creates_a_variation_and_redirects_to_the_member_editor(): void
    {
        $source = $this->source();
        $this->actingAs(User::factory()->create());
        Livewire::test(MakeRecipeVariation::class, ['recipeId' => $source->id, 'locale' => 'en'])
            ->call('create')->assertHasErrors('confirmed')
            ->set('confirmed', true)->call('create')->assertHasNoErrors()->assertRedirect();
        $this->assertSame(1, Recipe::query()->where('author_id', auth()->id())->whereNotNull('derived_from_recipe_id')->count());
    }

    public function test_editor_controls_have_accessible_labels_in_both_languages(): void
    {
        [, $recipe] = $this->ownVariation();
        foreach (['en', 'el'] as $locale) {
            $response = $this->get(route('recipes.variations.edit', ['locale' => $locale, 'recipe' => $recipe->id]))->assertOk();
            $document = new \DOMDocument;
            $document->loadHTML($response->getContent(), LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            $xpath = new \DOMXPath($document);
            $labels = [];
            foreach ($xpath->query('//label[@for]') as $label) {
                $labels[$label->getAttribute('for')] = trim($label->textContent);
            }
            $controls = $xpath->query("//section[contains(@class, 'variation-shell')]//*[self::input or self::select or self::textarea][not(@type='hidden')]");
            $this->assertGreaterThan(0, $controls->length);
            foreach ($controls as $control) {
                $accessibleName = trim($control->getAttribute('aria-label')) ?: ($labels[$control->getAttribute('id')] ?? '');
                $parent = $control->parentNode;
                while ($accessibleName === '' && $parent !== null) {
                    if ($parent->nodeName === 'label') {
                        $accessibleName = trim($parent->textContent);
                    }
                    $parent = $parent->parentNode;
                }
                $this->assertNotSame('', $accessibleName, 'Missing '.$locale.' label for '.$control->getAttribute('wire:model'));
            }
        }
    }

    public function test_owner_can_save_one_language_draft_and_source_remains_unchanged(): void
    {
        [, $recipe, $source] = $this->ownVariation();
        $sourceTitle = $source->translate('en')->title;
        Livewire::test(EditRecipeVariation::class, ['recipeId' => $recipe->id, 'locale' => 'en'])
            ->set('draft.translations.en.title', 'My oat cream soup')
            ->set('draft.translations.el.title', '')
            ->set('draft.recipe.base_servings', '3.50')
            ->set('summaries.en', 'Changed the cream.')
            ->call('save')->assertHasNoErrors()->assertSee(__('variations.saved'));
        $this->assertSame('My oat cream soup', $recipe->fresh()->translate('en')->title);
        $this->assertSame(1, $recipe->translations()->count());
        $this->assertSame($sourceTitle, $source->fresh()->translate('en')->title);
        $this->assertSame('3.50', $recipe->fresh()->base_servings);
    }

    public function test_ingredient_replacement_is_permanent_and_independent(): void
    {
        [, $recipe, $source] = $this->ownVariation();
        $new = Ingredient::factory()->create();
        $oldSource = $source->recipeIngredients()->first()->ingredient_id;
        Livewire::test(EditRecipeVariation::class, ['recipeId' => $recipe->id, 'locale' => 'el'])
            ->set('draft.ingredients.0.ingredient_id', $new->id)->call('save')->assertHasNoErrors();
        $this->assertSame($new->id, $recipe->recipeIngredients()->first()->ingredient_id);
        $this->assertSame($oldSource, $source->recipeIngredients()->first()->ingredient_id);
    }

    public function test_others_cannot_access_or_mutate_the_editor(): void
    {
        [, $recipe] = $this->ownVariation();
        $this->actingAs(User::factory()->create());
        $this->get(route('recipes.variations.edit', ['locale' => 'en', 'recipe' => $recipe->id]))->assertForbidden();
        Livewire::test(EditRecipeVariation::class, ['recipeId' => $recipe->id, 'locale' => 'en'])->assertForbidden();
    }

    public function test_editor_rejects_forged_line_ids_and_ownership_fields(): void
    {
        [, $recipe, $source] = $this->ownVariation();
        Livewire::test(EditRecipeVariation::class, ['recipeId' => $recipe->id, 'locale' => 'en'])
            ->set('draft.ingredients.0.id', $source->recipeIngredients()->first()->id)
            ->call('save')->assertHasErrors('ingredients.0.id');
        Livewire::test(EditRecipeVariation::class, ['recipeId' => $recipe->id, 'locale' => 'en'])
            ->set('draft.recipe.author_id', $source->author_id)->call('save')->assertHasErrors('recipe');
    }

    public function test_invalid_ingredient_values_and_cross_recipe_step_ids_are_rejected(): void
    {
        [, $recipe, $source] = $this->ownVariation();
        Livewire::test(EditRecipeVariation::class, ['recipeId' => $recipe->id, 'locale' => 'en'])
            ->set('draft.ingredients.0.ingredient_id', 'not-an-id')->call('save')->assertHasErrors('ingredients.0.ingredient_id');
        Livewire::test(EditRecipeVariation::class, ['recipeId' => $recipe->id, 'locale' => 'en'])
            ->set('draft.steps.0.id', $source->steps()->first()->id)->call('save')->assertHasErrors('steps.0.id');
    }

    public function test_stale_editor_cannot_overwrite_another_save(): void
    {
        [, $recipe] = $this->ownVariation();
        $first = Livewire::test(EditRecipeVariation::class, ['recipeId' => $recipe->id, 'locale' => 'en']);
        Livewire::test(EditRecipeVariation::class, ['recipeId' => $recipe->id, 'locale' => 'en'])
            ->set('draft.translations.en.title', 'Saved elsewhere')->call('save')->assertHasNoErrors();
        $first->set('draft.translations.en.title', 'Stale')->call('save')->assertHasErrors('draft');
        $this->assertSame('Saved elsewhere', $recipe->fresh()->translate('en')->title);
    }

    public function test_private_routes_have_no_store_and_greek_editor_strings(): void
    {
        [, $recipe] = $this->ownVariation();
        $this->get(route('recipes.variations.edit', ['locale' => 'el', 'recipe' => $recipe->id]))
            ->assertOk()->assertSee('Η δική μου εκδοχή της συνταγής')->assertHeader('Cache-Control');
        $response = $this->get(route('recipes.variations.edit', ['locale' => 'en', 'recipe' => $recipe->id]));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->get(route('recipes.personal', ['locale' => 'en']))->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }

    public function test_collection_filters_owned_variations_without_exposing_another_users_recipe(): void
    {
        [, $recipe] = $this->ownVariation();
        Livewire::test(PersonalRecipes::class, ['locale' => 'en'])->set('filter', 'variation')->assertSee($recipe->translate('en')->title)->assertSee('My Variation');
        $this->actingAs(User::factory()->create());
        Livewire::test(PersonalRecipes::class, ['locale' => 'en'])->assertDontSee($recipe->translate('en')->title);
    }

    public function test_photo_requires_rights_confirmation_and_is_stored_as_new_private_media(): void
    {
        [, $recipe, $source] = $this->ownVariation();
        Storage::fake('local');
        $test = Livewire::test(EditRecipeVariation::class, ['recipeId' => $recipe->id, 'locale' => 'en'])
            ->set('photo', UploadedFile::fake()->image('my-dish.png', 800, 600))
            ->set('alt.en', 'My dish')->set('alt.el', 'Το πιάτο μου')
            ->call('uploadPhoto')->assertHasErrors('confirmed')
            ->set('mediaRightsConfirmed', true)->call('uploadPhoto')->assertHasNoErrors();
        $media = $recipe->media()->firstOrFail();
        $this->assertSame('local', $media->disk);
        $this->assertFalse($source->media()->whereKey($media->id)->exists());
        Storage::disk('local')->assertExists($media->file_path);
        $this->actingAs(User::factory()->create())->get($media->publicUrl())->assertNotFound();
    }

    public function test_original_video_and_cross_recipe_step_references_are_rejected(): void
    {
        [, $recipe, $source] = $this->ownVariation();
        Livewire::test(EditRecipeVariation::class, ['recipeId' => $recipe->id, 'locale' => 'en'])
            ->set('mediaStepId', $recipe->steps()->first()->id)
            ->set('videoUrl', 'https://youtu.be/dQw4w9WgXcQ')
            ->set('mediaRightsConfirmed', true)->call('addVideo')->assertHasErrors('videoUrl');
        Livewire::test(EditRecipeVariation::class, ['recipeId' => $recipe->id, 'locale' => 'en'])
            ->set('mediaStepId', $source->steps()->first()->id)
            ->set('videoUrl', 'https://youtu.be/abcdefghijk')
            ->set('mediaRightsConfirmed', true)->call('addVideo')->assertNotFound();
        $this->assertSame(0, $recipe->steps()->first()->media()->count());
    }

    public function test_steps_groups_and_ingredient_order_can_be_edited(): void
    {
        [, $recipe] = $this->ownVariation();
        $new = Ingredient::factory()->create();
        Livewire::test(EditRecipeVariation::class, ['recipeId' => $recipe->id, 'locale' => 'en'])
            ->call('addGroup')->set('draft.ingredient_groups.0.names.en', 'Sauce')
            ->call('addIngredient', $new->id)->set('draft.ingredients.1.group_index', 0)
            ->set('draft.ingredients.1.quantity', '150')->set('draft.ingredients.1.quantity_max', '')
            ->call('moveRow', 'ingredients', 1, -1)
            ->call('addStep')->set('draft.steps.1.translations.en.instruction', 'Add oat cream.')
            ->call('save')->assertHasNoErrors();
        $line = $recipe->recipeIngredients()->orderBy('sort_order')->first();
        $this->assertSame($new->id, $line->ingredient_id);
        $this->assertSame('Sauce', $line->ingredientGroup->translate('en')->name);
        $this->assertSame(2, $recipe->steps()->count());
    }

    public function test_plain_text_introduction_is_escaped_and_provenance_is_not_editable(): void
    {
        [, $recipe] = $this->ownVariation();
        Livewire::test(EditRecipeVariation::class, ['recipeId' => $recipe->id, 'locale' => 'en'])
            ->set('draft.translations.en.introduction', '<script>alert(1)</script>My notes')
            ->call('save')->assertHasNoErrors();
        $this->assertStringNotContainsString('<script>', $recipe->fresh()->translate('en')->introduction);
        $this->get(route('recipes.variations.edit', ['locale' => 'en', 'recipe' => $recipe->id]))
            ->assertDontSee('Private signed consent reference')->assertDontSee('source_permission_evidence_id');
    }

    public function test_an_original_photo_cannot_be_reuploaded_and_new_media_can_be_removed(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $photo = UploadedFile::fake()->image('original.png', 800, 600);
        $source = $this->source(function ($recipe) use ($photo): void {
            $recipe->media()->detach();
            app(StoreRecipeMedia::class)->handle($recipe, $photo, ['en' => ['alt_text' => 'Original'], 'el' => ['alt_text' => 'Αρχική']], 'cover');
        });
        $owner = User::factory()->create();
        $recipe = app(RecipeForkService::class)->fork($owner, $source, ['idempotency_key' => (string) Str::uuid()]);
        $this->actingAs($owner);
        $test = Livewire::test(EditRecipeVariation::class, ['recipeId' => $recipe->id, 'locale' => 'en'])
            ->set('photo', $photo)->set('alt.en', 'Copy')->set('alt.el', 'Αντίγραφο')
            ->set('mediaRightsConfirmed', true)->call('uploadPhoto')->assertHasErrors('photo');
        $this->assertSame(0, $recipe->media()->count());
        $test->set('photo', UploadedFile::fake()->image('new.png', 400, 300))
            ->call('uploadPhoto')->assertHasNoErrors();
        $media = $recipe->media()->firstOrFail();
        $test->call('removeMedia', $source->media()->first()->id)->assertNotFound();
        Livewire::test(EditRecipeVariation::class, ['recipeId' => $recipe->id, 'locale' => 'en'])
            ->call('removeMedia', $media->id)->assertHasNoErrors();
        $this->assertSame(0, $recipe->media()->count());
        $this->assertSame(1, $source->media()->count());
    }

    public function test_group_removal_reassigns_lines_and_keeps_translations_consistent(): void
    {
        [, $recipe] = $this->ownVariation();
        $test = Livewire::test(EditRecipeVariation::class, ['recipeId' => $recipe->id, 'locale' => 'en']);
        $test->call('addGroup')->set('draft.ingredient_groups.0.names.en', 'Sauce')
            ->set('draft.ingredients.0.group_index', 0)->call('save')->assertHasNoErrors()
            ->call('removeGroup', 0)->call('save')->assertHasNoErrors();
        $this->assertSame(0, $recipe->ingredientGroups()->count());
        $this->assertNull($recipe->recipeIngredients()->first()->ingredient_group_id);
    }

    public function test_source_rights_are_rechecked_after_confirmation_is_rendered(): void
    {
        $source = $this->source();
        $this->actingAs(User::factory()->create());
        $test = Livewire::test(MakeRecipeVariation::class, ['recipeId' => $source->id, 'locale' => 'en'])->set('confirmed', true);
        $permission = $source->reusePermissions()->first();
        $permission->forceFill(['suspended_at' => now(), 'suspended_by' => $permission->created_by, 'suspension_reason' => 'Dispute'])->save();
        $test->call('create')->assertForbidden();
    }

    public function test_feature_switch_blocks_editor_and_hides_fork_button(): void
    {
        [, $recipe, $source] = $this->ownVariation();
        config(['recipe-variations.enabled' => false]);
        $this->get(route('recipes.variations.edit', ['locale' => 'en', 'recipe' => $recipe->id]))->assertNotFound();
        $this->get(route('recipes.show', ['locale' => 'en', 'slug' => $source->translate('en')->slug]))->assertOk()->assertDontSee('Make It Your Own');
    }
}
