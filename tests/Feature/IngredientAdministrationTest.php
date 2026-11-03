<?php

namespace Tests\Feature;

use App\Filament\Resources\IngredientCatalogGroups\Pages\CreateIngredientCatalogGroup;
use App\Filament\Resources\IngredientCatalogGroups\Pages\ListIngredientCatalogGroups;
use App\Filament\Resources\Ingredients\Pages\EditIngredient;
use App\Filament\Resources\Ingredients\Pages\ListIngredients;
use App\Filament\Resources\Ingredients\RelationManagers\ImagesRelationManager;
use App\Models\Ingredient;
use App\Models\IngredientCatalogGroup;
use App\Models\IngredientMedia;
use App\Models\Media;
use App\Models\Recipe;
use App\Models\RecipeRevisionMedia;
use App\Models\User;
use App\Services\IngredientImageService;
use Database\Seeders\DifficultySeeder;
use Database\Seeders\ScalingModeSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class IngredientAdministrationTest extends TestCase
{
    use DatabaseTransactions;

    private const PHOTO = ['role' => 'primary', 'rights_basis' => 'own_work', 'rights_reference' => 'Photo by the uploader', 'alt_text_en' => 'Fresh tomatoes', 'alt_text_el' => 'Φρέσκες ντομάτες'];

    public function test_upload_produces_private_optimized_image_thumbnail_and_recorded_rights(): void
    {
        Storage::fake('local');
        $admin = User::factory()->admin()->create();
        $ingredient = $this->ingredient();

        $assignment = app(IngredientImageService::class)->upload($admin, $ingredient, UploadedFile::fake()->image('tomatoes.png', 2400, 1600), self::PHOTO + ['rights_confirmed_by' => 999999]);

        $assignment->load(['media.translations', 'thumbnail']);
        $this->assertSame(1920, $assignment->media->width);
        $this->assertSame(480, $assignment->thumbnail->width);
        $this->assertSame('image/webp', $assignment->media->mime_type);
        $this->assertSame('local', $assignment->media->disk);
        $this->assertSame($admin->id, $assignment->rights_confirmed_by);
        $this->assertNotNull($assignment->rights_confirmed_at);
        $this->assertSame('Φρέσκες ντομάτες', $ingredient->fresh()->imageAlt('el'));
        Storage::disk('local')->assertExists([$assignment->media->file_path, $assignment->thumbnail->file_path]);
        $this->assertDatabaseCount('ingredient_media', 1);
    }

    public static function nonManagers(): array
    {
        return ['editor' => ['editor'], 'contributor' => ['contributor'], 'member' => ['member']];
    }

    #[DataProvider('nonManagers')]
    public function test_non_managers_cannot_upload_ingredient_images(string $role): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $user->syncRoles([$role]);
        $ingredient = $this->ingredient();
        try {
            app(IngredientImageService::class)->upload($user, $ingredient, UploadedFile::fake()->image('test.png'), self::PHOTO);
            $this->fail('Photo changes require ingredient update permission.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('media', 0);
            $this->assertSame([], Storage::disk('local')->allFiles());
        }
    }

    public function test_image_validation_requires_both_languages_and_usage_rights_before_storing_files(): void
    {
        Storage::fake('local');
        $admin = User::factory()->admin()->create();
        $ingredient = $this->ingredient();
        try {
            app(IngredientImageService::class)->upload($admin, $ingredient, UploadedFile::fake()->image('test.png'), ['role' => 'primary', 'alt_text_en' => 'Tomatoes']);
            $this->fail('Incomplete rights and alt text must fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('rights_reference', $exception->errors());
            $this->assertArrayHasKey('rights_basis', $exception->errors());
            $this->assertArrayHasKey('alt_text_el', $exception->errors());
            $this->assertDatabaseCount('media', 0);
            $this->assertSame([], Storage::disk('local')->allFiles());
        }
    }

    public function test_staff_preview_is_authorized_while_guest_reader_and_unverified_staff_are_denied(): void
    {
        Storage::fake('local');
        $admin = User::factory()->admin()->create();
        $photo = $this->upload($admin, $this->ingredient());
        $url = '/media/'.$photo->media_id;

        $this->get($url)->assertNotFound();
        $this->actingAs(User::factory()->create())->get($url)->assertNotFound();
        $this->actingAs(User::factory()->admin()->unverified()->create())->get($url)->assertNotFound();
        $response = $this->actingAs(User::factory()->editor()->create())->get($url)->assertOk();
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsString($photo->media->file_path, $response->headers->get('Content-Disposition', ''));
    }

    public function test_gallery_promotion_and_removal_are_scoped_to_the_ingredient(): void
    {
        Storage::fake('local');
        $admin = User::factory()->admin()->create();
        $ingredient = $this->ingredient();
        $first = $this->upload($admin, $ingredient);
        $second = $this->upload($admin, $ingredient, ['role' => 'gallery']);
        $service = app(IngredientImageService::class);

        $service->promote($admin, $ingredient, $second->id);
        $this->assertSame($second->id, $ingredient->fresh()->primaryImage->id);
        $this->assertSame('gallery', $first->fresh()->role);
        try {
            $service->remove($admin, $this->ingredient(), $second->id);
            $this->fail('Cross-ingredient image IDs must not be accepted.');
        } catch (ModelNotFoundException) {
            $this->assertModelExists($second);
        }
        $service->remove($admin, $ingredient, $first->id);
        $this->assertModelMissing($first);
        $this->assertDatabaseCount('ingredient_media', 1);
        $this->assertDatabaseCount('media', 2);
    }

    public function test_assigning_a_library_image_creates_an_independent_copy_and_alt_text_can_be_edited(): void
    {
        Storage::fake('local');
        $admin = User::factory()->admin()->create();
        $original = $this->upload($admin, $this->ingredient());
        $destination = $this->ingredient();
        $service = app(IngredientImageService::class);

        $copy = $service->assign($admin, $destination, $original->media, array_replace(self::PHOTO, ['alt_text_en' => 'Tomato close-up']));
        $service->updateText($admin, $destination, $copy->id, array_replace(self::PHOTO, ['alt_text_en' => 'New alt text']));

        $this->assertNotSame($original->media_id, $copy->media_id);
        $this->assertSame('New alt text', $copy->fresh()->media->translate('en')->alt_text);
        $this->assertSame('New alt text', $copy->fresh()->thumbnail->translate('en')->alt_text);
        $this->assertSame('Fresh tomatoes', $original->fresh()->media->translate('en')->alt_text);
    }

    public function test_recipe_and_external_media_cannot_be_assigned_to_ingredients(): void
    {
        Storage::fake('local');
        $admin = User::factory()->admin()->create();
        $ingredient = $this->ingredient();
        $media = Media::factory()->remote()->create();

        $this->expectException(ValidationException::class);
        app(IngredientImageService::class)->assign($admin, $ingredient, $media, self::PHOTO);
    }

    public function test_a_mislinked_private_recipe_image_is_not_exposed_by_ingredient_preview_permissions(): void
    {
        Storage::fake('local');
        $this->seed([DifficultySeeder::class, ScalingModeSeeder::class]);
        $admin = User::factory()->admin()->create();
        $image = $this->upload($admin, $this->ingredient());
        $privateRecipe = Recipe::factory()->create();
        $privateRecipe->media()->attach($image->media_id, ['role' => 'cover']);

        $this->actingAs(User::factory()->editor()->create())->get('/media/'.$image->media_id)->assertNotFound();
        $this->expectException(ValidationException::class);
        app(IngredientImageService::class)->assign($admin, $this->ingredient(), $image->media, self::PHOTO);
    }

    public function test_retained_revision_images_cannot_be_reassigned_as_ingredient_photographs(): void
    {
        Storage::fake('local');
        $this->seed([DifficultySeeder::class, ScalingModeSeeder::class]);
        $admin = User::factory()->admin()->create();
        $image = $this->upload($admin, $this->ingredient());
        $recipe = Recipe::factory()->create();
        $revision = $recipe->revisions()->create(['revision_number' => 1, 'status' => 'draft', 'payload' => [], 'content_hash' => str_repeat('a', 64)]);
        RecipeRevisionMedia::query()->create(['recipe_revision_id' => $revision->id, 'media_id' => $image->media_id, 'usage' => 'cover']);

        $this->expectException(ValidationException::class);
        app(IngredientImageService::class)->assign($admin, $this->ingredient(), $image->media, self::PHOTO);
    }

    public function test_update_permission_alone_does_not_grant_access_to_other_ingredient_library_images(): void
    {
        Storage::fake('local');
        $admin = User::factory()->admin()->create();
        $image = $this->upload($admin, $this->ingredient());
        $writer = User::factory()->create();
        $writer->givePermissionTo('Update:Ingredient');

        $this->expectException(ValidationException::class);
        app(IngredientImageService::class)->assign($writer, $this->ingredient(), $image->media, self::PHOTO);
    }

    public function test_filament_edits_education_and_aliases_and_reports_duplicate_names(): void
    {
        $this->panel();
        $admin = User::factory()->admin()->create();
        $ingredient = $this->ingredient();
        $other = $this->ingredient();
        $originalCode = $ingredient->code;
        $other->translations()->where('locale', 'en')->update(['name' => 'Existing Tomato']);

        $component = Livewire::actingAs($admin)->test(EditIngredient::class, ['record' => $ingredient->id]);
        $component->fillForm(['code' => 'attempted-code-change', 'en.storage_tips' => 'Keep cool.', 'el.preparation_tips' => 'Πλύνετε καλά.', 'aliases' => [['locale' => 'en', 'alias' => 'Tomato synonym']]])
            ->call('save')->assertHasNoFormErrors();
        $this->assertSame('Keep cool.', $ingredient->fresh()->translate('en')->storage_tips);
        $this->assertSame($originalCode, $ingredient->fresh()->code);
        $this->assertSame('Πλύνετε καλά.', $ingredient->fresh()->translate('el')->preparation_tips);
        $this->assertDatabaseHas('ingredient_aliases', ['ingredient_id' => $ingredient->id, 'alias' => 'Tomato synonym']);
        $component->fillForm(['en.name' => 'existing tomato'])->call('save')->assertHasFormErrors(['en.name']);
        $this->assertDatabaseCount('recipe_dietary_claims', 0);
    }

    public function test_filament_filters_groups_missing_photos_and_incomplete_translations(): void
    {
        $this->panel();
        Storage::fake('local');
        $admin = User::factory()->admin()->create();
        $complete = $this->ingredient();
        $missing = $this->ingredient();
        $missing->translations()->where('locale', 'el')->delete();
        $this->upload($admin, $complete);

        Livewire::actingAs($admin)->test(ListIngredients::class)->filterTable('missing_image')
            ->assertCanSeeTableRecords([$missing])->assertCanNotSeeTableRecords([$complete]);
        Livewire::actingAs($admin)->test(ListIngredients::class)->filterTable('incomplete_translations')
            ->assertCanSeeTableRecords([$missing])->assertCanNotSeeTableRecords([$complete]);
        Livewire::actingAs($admin)->test(ListIngredients::class)->filterTable('catalog_group_id', $complete->catalog_group_id)
            ->assertCanSeeTableRecords([$complete])->assertCanNotSeeTableRecords([$missing]);
    }

    public function test_catalogue_group_admin_is_bilingual_and_read_only_for_editors(): void
    {
        $this->panel();
        $admin = User::factory()->admin()->create();
        Livewire::actingAs($admin)->test(CreateIngredientCatalogGroup::class)->fillForm(['code' => 'test_group', 'sort_order' => 10, 'en' => ['name' => 'Test group'], 'el' => ['name' => 'Ομάδα δοκιμής']])
            ->call('create')->assertHasNoFormErrors();
        $group = IngredientCatalogGroup::query()->where('code', 'test_group')->firstOrFail();
        $this->assertSame('Ομάδα δοκιμής', $group->translate('el')->name);
        Livewire::actingAs(User::factory()->editor()->create())->test(ListIngredientCatalogGroups::class)->assertCanSeeTableRecords([$group]);
        $this->actingAs(User::factory()->editor()->create())->get('/admin/ingredient-catalog-groups/create')->assertForbidden();
        $this->actingAs(User::factory()->create())->get('/admin/ingredient-catalog-groups')->assertForbidden();
    }

    public function test_filament_photograph_manager_uploads_and_promotes_gallery_images(): void
    {
        $this->panel();
        Storage::fake('local');
        $admin = User::factory()->admin()->create();
        $ingredient = $this->ingredient();
        $component = Livewire::actingAs($admin)->test(ImagesRelationManager::class, ['ownerRecord' => $ingredient, 'pageClass' => EditIngredient::class]);
        $component->callAction(TestAction::make('upload')->table(), self::PHOTO + ['file' => UploadedFile::fake()->image('first.png')])->assertHasNoFormErrors();
        $component->callAction(TestAction::make('upload')->table(), array_replace(self::PHOTO, ['role' => 'gallery', 'file' => UploadedFile::fake()->image('second.png')]))->assertHasNoFormErrors();
        $gallery = $ingredient->imageAssignments()->where('role', 'gallery')->firstOrFail();
        $component->callAction(TestAction::make('primary')->table($gallery))->assertHasNoFormErrors();
        $this->assertSame($gallery->id, $ingredient->fresh()->primaryImage->id);
        $this->assertDatabaseCount('ingredient_media', 2);
    }

    public function test_duplicate_aliases_are_reported_before_any_ingredient_changes_are_saved(): void
    {
        $this->panel();
        $admin = User::factory()->admin()->create();
        $ingredient = $this->ingredient();
        $original = $ingredient->translate('en')->name;
        Livewire::actingAs($admin)->test(EditIngredient::class, ['record' => $ingredient->id])
            ->fillForm(['en.name' => 'Changed name', 'aliases' => [['locale' => 'en', 'alias' => 'Courgette'], ['locale' => 'en', 'alias' => 'courgette']]])
            ->call('save')->assertHasFormErrors(['aliases']);
        $this->assertSame($original, $ingredient->fresh()->translate('en')->name);
        $this->assertDatabaseCount('ingredient_aliases', 0);
    }

    public function test_retained_recipe_revision_ingredients_are_marked_in_use_even_without_live_lines(): void
    {
        $this->seed([DifficultySeeder::class, ScalingModeSeeder::class]);
        $ingredient = $this->ingredient();
        $recipe = Recipe::factory()->create();
        $recipe->revisions()->create(['revision_number' => 1, 'status' => 'draft', 'payload' => ['ingredients' => [['ingredient_id' => $ingredient->id]]], 'content_hash' => str_repeat('a', 64)]);

        $this->assertTrue($ingredient->isInUse());
        $this->assertSame(0, $ingredient->recipeIngredients()->count());
    }

    private function ingredient(): Ingredient
    {
        $group = IngredientCatalogGroup::factory()->create(['en' => ['name' => 'Vegetables'], 'el' => ['name' => 'Λαχανικά']]);
        $code = fake()->unique()->bothify('test-????-####');

        return Ingredient::factory()->create(['code' => $code, 'catalog_group_id' => $group->id, 'en' => ['name' => $code, 'slug' => $code], 'el' => ['name' => 'Υλικό '.$code, 'slug' => $code.'-el']]);
    }

    private function upload(User $actor, Ingredient $ingredient, array $overrides = []): IngredientMedia
    {
        return app(IngredientImageService::class)->upload($actor, $ingredient, UploadedFile::fake()->image('tomato.png', 800, 600), array_replace(self::PHOTO, $overrides));
    }

    private function panel(): void
    {
        Filament::setCurrentPanel('admin');
    }
}
