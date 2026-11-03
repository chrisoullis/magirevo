<?php

namespace Tests\Feature;

use App\Filament\Resources\Ingredients\Pages\ListIngredients;
use App\Models\Ingredient;
use App\Models\IngredientCatalogGroup;
use App\Models\User;
use App\Services\IngredientTextImport;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class IngredientTextImportTest extends TestCase
{
    use DatabaseTransactions;

    public function test_preview_is_read_only_and_apply_creates_bilingual_records_without_dietary_metadata(): void
    {
        $admin = User::factory()->admin()->create();
        $group = IngredientCatalogGroup::factory()->create();
        $json = $this->payload($group->code);
        $service = app(IngredientTextImport::class);

        $preview = $service->preview($admin, $json);
        $this->assertDatabaseCount('ingredients', 0);
        $this->assertSame('create', $preview['changes'][0]['action']);
        $this->assertSame(1, $service->apply($admin, $json, $preview['token']));
        $ingredient = Ingredient::query()->where('code', 'test-import')->firstOrFail();
        $this->assertSame('Test ingredient', $ingredient->translate('en')->name);
        $this->assertSame('Υλικό δοκιμής', $ingredient->translate('el')->name);
        $this->assertSame($group->id, $ingredient->catalog_group_id);
        $this->assertNull($ingredient->default_unit_id);
        $this->assertDatabaseCount('ingredient_dietary_profiles', 0);
        $this->assertDatabaseCount('recipe_dietary_claims', 0);
    }

    public function test_updates_show_differences_and_leave_unspecified_text_units_and_slugs_unchanged(): void
    {
        $admin = User::factory()->admin()->create();
        $group = IngredientCatalogGroup::factory()->create();
        $ingredient = Ingredient::factory()->create(['code' => 'test-import', 'catalog_group_id' => $group->id,
            'en' => ['name' => 'Original', 'description' => 'Retained description', 'slug' => 'existing-url'], 'el' => ['name' => 'Υλικό δοκιμής', 'slug' => 'existing-el']]);
        $unit = $ingredient->default_unit_id;
        $json = $this->payload($group->code);
        $service = app(IngredientTextImport::class);

        $preview = $service->preview($admin, $json);
        $this->assertSame(['from' => 'Original', 'to' => 'Test ingredient'], $preview['changes'][0]['changes']['en.name']);
        $service->apply($admin, $json, $preview['token']);

        $this->assertSame('Test ingredient', $ingredient->fresh()->translate('en')->name);
        $this->assertSame('Retained description', $ingredient->fresh()->translate('en')->description);
        $this->assertSame('existing-url', $ingredient->fresh()->translate('en')->slug);
        $this->assertSame($unit, $ingredient->fresh()->default_unit_id);
    }

    public function test_changed_records_invalidate_the_preview_instead_of_overwriting_concurrent_edits(): void
    {
        $admin = User::factory()->admin()->create();
        $group = IngredientCatalogGroup::factory()->create();
        $ingredient = Ingredient::factory()->create(['code' => 'test-import', 'en' => ['name' => 'Original']]);
        $service = app(IngredientTextImport::class);
        $json = $this->payload($group->code);
        $preview = $service->preview($admin, $json);
        $ingredient->translations()->where('locale', 'en')->update(['name' => 'Changed after preview']);

        try {
            $service->apply($admin, $json, $preview['token']);
            $this->fail('Stale previews must fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('payload', $exception->errors());
        }
        $this->assertSame('Changed after preview', $ingredient->fresh()->translate('en')->name);
    }

    public function test_dietary_payload_keys_are_rejected_before_writing(): void
    {
        $admin = User::factory()->admin()->create();
        $group = IngredientCatalogGroup::factory()->create();
        $rows = json_decode($this->payload($group->code), true);
        $rows[0]['dietaryProfile'] = ['composition_status' => 'complete'];

        $this->expectException(ValidationException::class);
        app(IngredientTextImport::class)->preview($admin, json_encode($rows));
    }

    public function test_duplicate_names_in_a_batch_are_rejected_without_partial_writes(): void
    {
        $admin = User::factory()->admin()->create();
        $group = IngredientCatalogGroup::factory()->create();
        $rows = json_decode($this->payload($group->code), true);
        $rows[] = array_replace($rows[0], ['code' => 'another-code']);
        try {
            app(IngredientTextImport::class)->preview($admin, json_encode($rows));
            $this->fail('Duplicate identities must not be imported.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('payload', $exception->errors());
        }
        $this->assertDatabaseCount('ingredients', 0);
    }

    public function test_changed_input_cannot_reuse_a_token_from_another_preview(): void
    {
        $admin = User::factory()->admin()->create();
        $group = IngredientCatalogGroup::factory()->create();
        $json = $this->payload($group->code);
        $service = app(IngredientTextImport::class);
        $preview = $service->preview($admin, $json);
        $rows = json_decode($json, true);
        $rows[0]['en']['storage_tips'] = 'An unreviewed change';
        try {
            $service->apply($admin, json_encode($rows), $preview['token']);
            $this->fail('The preview token must bind the reviewed input.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('payload', $exception->errors());
        }
        $this->assertDatabaseCount('ingredients', 0);
    }

    public function test_non_admin_editors_cannot_import_or_generate_a_preview_token(): void
    {
        $editor = User::factory()->editor()->create();
        $group = IngredientCatalogGroup::factory()->create();

        $this->expectException(AuthorizationException::class);
        app(IngredientTextImport::class)->preview($editor, $this->payload($group->code));
    }

    public function test_import_action_renders_the_preview_wizard_and_rejects_unpreviewed_submissions(): void
    {
        Filament::setCurrentPanel('admin');
        $admin = User::factory()->admin()->create();
        $group = IngredientCatalogGroup::factory()->create();
        Livewire::actingAs($admin)->test(ListIngredients::class)->mountAction('importText')
            ->assertActionMounted('importText')
            ->setActionData(['payload' => $this->payload($group->code)])
            ->callMountedAction()->assertHasActionErrors();
        $this->assertDatabaseCount('ingredients', 0);
    }

    public function test_filament_import_wizard_previews_then_applies_the_reviewed_records(): void
    {
        Filament::setCurrentPanel('admin');
        $admin = User::factory()->admin()->create();
        $group = IngredientCatalogGroup::factory()->create();
        $component = Livewire::actingAs($admin)->test(ListIngredients::class)->mountAction('importText')
            ->setActionData(['payload' => $this->payload($group->code)])
            ->goToNextWizardStep()->assertHasNoActionErrors();
        $this->assertDatabaseCount('ingredients', 0);
        $component->callMountedAction()->assertHasNoActionErrors();
        $this->assertDatabaseHas('ingredients', ['code' => 'test-import', 'catalog_group_id' => $group->id]);
        $this->assertDatabaseCount('ingredient_translations', 2);
    }

    private function payload(string $group): string
    {
        return json_encode([['code' => 'test-import', 'group_code' => $group, 'en' => ['name' => 'Test ingredient'], 'el' => ['name' => 'Υλικό δοκιμής']]], JSON_UNESCAPED_UNICODE);
    }
}
