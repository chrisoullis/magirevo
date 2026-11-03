<?php

namespace Tests\Feature;

use App\Actions\DeleteMediaIfUnused;
use App\Models\Ingredient;
use App\Models\IngredientAlias;
use App\Models\IngredientCatalogGroup;
use App\Models\IngredientGroup;
use App\Models\Media;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\User;
use Database\Seeders\DifficultySeeder;
use Database\Seeders\ScalingModeSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class IngredientCatalogSchemaTest extends TestCase
{
    use DatabaseTransactions;

    public function test_catalogue_groups_are_bilingual_and_distinct_from_recipe_sections(): void
    {
        $this->seed([DifficultySeeder::class, ScalingModeSeeder::class]);
        $catalogue = IngredientCatalogGroup::factory()->create([
            'en' => ['name' => 'Vegetables', 'description' => 'Culinary vegetables.'],
            'el' => ['name' => 'Λαχανικά'],
        ]);
        $ingredient = Ingredient::factory()->create(['catalog_group_id' => $catalogue->id]);
        $recipe = Recipe::factory()->create();
        $section = IngredientGroup::factory()->create(['recipe_id' => $recipe->id]);
        $line = RecipeIngredient::factory()->create(['recipe_id' => $recipe->id, 'ingredient_id' => $ingredient->id, 'ingredient_group_id' => $section->id]);

        $this->assertSame('Λαχανικά', $ingredient->catalogGroup->translate('el')->name);
        $this->assertSame([$ingredient->id], $catalogue->ingredients()->get()->modelKeys());
        $this->assertTrue($catalogue->isInUse());
        $this->assertSame($section->id, $line->ingredientGroup->id);
        $this->assertSame($recipe->id, $section->recipe_id);
    }

    public function test_existing_ingredients_need_no_group_or_slug_and_can_add_educational_translations(): void
    {
        $ingredient = Ingredient::factory()->create(['is_active' => false]);
        $ingredient->fill([
            'en' => ['name' => 'Fresh coriander', 'slug' => 'fresh-coriander', 'culinary_uses' => 'Garnish', 'preparation_tips' => 'Wash leaves.', 'storage_tips' => 'Refrigerate.'],
            'el' => ['name' => 'Φύλλα κόλιανδρου'],
        ])->save();

        $this->assertNull($ingredient->fresh()->catalog_group_id);
        $this->assertFalse($ingredient->fresh()->is_active);
        $this->assertNull($ingredient->fresh()->translate('el')->slug);
        $this->assertSame('Refrigerate.', $ingredient->fresh()->translate('en')->storage_tips);
        $this->assertDatabaseCount('ingredient_dietary_profiles', 0);
        $this->assertDatabaseCount('ingredient_dietary_assessments', 0);
        $this->assertDatabaseCount('recipe_dietary_claims', 0);
    }

    public function test_an_assigned_catalogue_group_cannot_be_deleted(): void
    {
        $group = IngredientCatalogGroup::factory()->create();
        Ingredient::factory()->create(['catalog_group_id' => $group->id]);

        $this->expectException(QueryException::class);
        $group->delete();
    }

    public function test_localized_slug_collisions_are_rejected_without_requiring_slugs_for_legacy_rows(): void
    {
        Ingredient::factory()->create(['en' => ['name' => 'First ingredient', 'slug' => 'same-slug']]);
        $other = Ingredient::factory()->create(['el' => ['name' => 'Δεύτερο υλικό', 'slug' => 'same-slug']]);

        $this->expectException(QueryException::class);
        $other->fill(['en' => ['name' => 'Other ingredient', 'slug' => 'same-slug']])->save();
    }

    public function test_aliases_link_to_ingredients_and_reject_case_only_duplicates(): void
    {
        $ingredient = Ingredient::factory()->create();
        $alias = $ingredient->aliases()->create(['locale' => 'en', 'alias' => 'Courgette']);
        $this->assertSame($ingredient->id, $alias->ingredient->id);

        $this->expectException(QueryException::class);
        IngredientAlias::query()->create(['ingredient_id' => $ingredient->id, 'locale' => 'en', 'alias' => 'courgette']);
    }

    public function test_primary_and_thumbnail_images_are_retained_by_media_cleanup(): void
    {
        $ingredient = Ingredient::factory()->create();
        $image = Media::factory()->remote()->create();
        $thumbnail = Media::factory()->remote()->create();
        $reviewer = User::factory()->admin()->create();
        $ingredient->media()->attach($image, $this->rights($reviewer) + ['role' => 'primary', 'thumbnail_media_id' => $thumbnail->id]);
        $ingredient->load('primaryImage.media', 'primaryImage.thumbnail', 'media');

        $this->assertSame($image->id, $ingredient->primaryImage->media->id);
        $this->assertSame($thumbnail->id, $ingredient->primaryImage->thumbnail->id);
        $this->assertSame('own_work', $ingredient->media->first()->pivot->rights_basis);
        $this->assertFalse(app(DeleteMediaIfUnused::class)->handle($image));
        $this->assertFalse(app(DeleteMediaIfUnused::class)->handle($thumbnail));
        $this->assertModelExists($image);
        $this->assertModelExists($thumbnail);
    }

    public function test_an_ingredient_cannot_have_two_primary_images(): void
    {
        $ingredient = Ingredient::factory()->create();
        $reviewer = User::factory()->admin()->create();
        $ingredient->media()->attach(Media::factory()->remote()->create(), $this->rights($reviewer) + ['role' => 'primary']);

        $this->expectException(QueryException::class);
        $ingredient->media()->attach(Media::factory()->remote()->create(), $this->rights($reviewer) + ['role' => 'primary']);
    }

    public function test_media_assignments_cannot_omit_usage_rights_confirmation(): void
    {
        $ingredient = Ingredient::factory()->create();
        $image = Media::factory()->remote()->create();

        $this->expectException(QueryException::class);
        $ingredient->media()->attach($image, ['role' => 'primary']);
    }

    /** @return array<string, array{string, bool, bool}> */
    public static function roles(): array
    {
        return ['admin' => ['admin', true, true], 'editor' => ['editor', true, false], 'contributor' => ['contributor', false, false], 'member' => ['member', false, false]];
    }

    #[DataProvider('roles')]
    public function test_group_policy_follows_lookup_role_permissions(string $role, bool $view, bool $write): void
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        $group = IngredientCatalogGroup::factory()->create();

        $this->assertSame($view, $user->can('viewAny', IngredientCatalogGroup::class));
        $this->assertSame($write, $user->can('create', IngredientCatalogGroup::class));
        $this->assertSame($write, $user->can('update', $group));
        $this->assertSame($write, $user->can('delete', $group));
        $this->assertFalse($user->can('forceDelete', $group));
    }

    public function test_upgrade_grants_only_new_group_permissions_and_preserves_custom_permissions(): void
    {
        $admin = User::factory()->admin()->create();
        $editor = User::factory()->editor()->create();
        DB::table('role_has_permissions')->whereIn('permission_id', DB::table('permissions')->select('id')->where('name', 'like', '%:IngredientCatalogGroup'))->delete();
        $before = DB::table('role_has_permissions')->orderBy('role_id')->orderBy('permission_id')->get()->toJson();
        $migration = require database_path('migrations/2026_11_03_083554_grant_ingredient_catalog_group_permissions_to_existing_roles.php');

        $migration->up();
        $migration->up();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertTrue($admin->fresh()->can('Create:IngredientCatalogGroup'));
        $this->assertTrue($editor->fresh()->can('ViewAny:IngredientCatalogGroup'));
        $this->assertFalse($editor->fresh()->can('Create:IngredientCatalogGroup'));
        $this->assertFalse($admin->fresh()->can('ForceDelete:IngredientCatalogGroup'));
        $after = DB::table('role_has_permissions')->whereNotIn('permission_id', DB::table('permissions')->select('id')->where('name', 'like', '%:IngredientCatalogGroup'))->orderBy('role_id')->orderBy('permission_id')->get()->toJson();
        $this->assertSame($before, $after);
    }

    /** @return array<string, mixed> */
    private function rights(User $reviewer): array
    {
        return ['rights_basis' => 'own_work', 'rights_reference' => 'Photographed by the uploader.', 'rights_confirmed_by' => $reviewer->id, 'rights_confirmed_at' => now()];
    }
}
