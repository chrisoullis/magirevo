<?php

namespace Tests\Feature;

use App\Actions\AttachRemoteRecipeMedia;
use App\Livewire\RecipeIndex;
use App\Models\Category;
use App\Models\Difficulty;
use App\Models\Media;
use App\Models\Recipe;
use App\Models\User;
use App\Services\RecipePublishingService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use LogicException;
use Tests\TestCase;

class RolesAndMediaTest extends TestCase
{
    use RefreshDatabase;

    public function test_editors_can_view_but_not_change_lookups_while_members_cannot_enter_the_panel(): void
    {
        $editor = User::factory()->editor()->create();
        $member = User::factory()->create();
        $category = Category::factory()->create();

        $this->assertTrue($editor->can('viewAny', Category::class));
        $this->assertFalse($editor->can('create', Category::class));
        $this->assertFalse($editor->can('update', $category));
        $this->assertFalse($editor->can('delete', $category));
        $this->assertTrue($editor->can('create', Recipe::class));
        $this->assertFalse($member->can('viewAny', Category::class));

        $this->actingAs($member)->get('/admin/recipes')->assertForbidden();
    }

    public function test_only_administrators_can_publish_recipes(): void
    {
        $recipe = Recipe::factory()->create();

        $this->assertFalse(User::factory()->editor()->create()->can('publish', $recipe));
        $this->assertTrue(User::factory()->editor()->create()->can('submitForReview', $recipe));
        $this->assertTrue(User::factory()->admin()->create()->can('publish', $recipe));
    }

    public function test_lookup_codes_are_immutable(): void
    {
        $difficulty = Difficulty::query()->firstOrFail();

        $this->expectException(LogicException::class);

        $difficulty->update(['code' => 'Renamed']);
    }

    public function test_publication_requires_complete_english_and_greek_content_and_a_cover(): void
    {
        $recipe = Recipe::factory()->create();
        $recipe->fill(['en' => ['title' => 'Soup', 'slug' => 'soup'], 'el' => ['title' => 'Σούπα', 'slug' => 'soupa']])->save();

        try {
            app(RecipePublishingService::class)->publish($recipe);
            $this->fail('Incomplete recipes must not be published.');
        } catch (ValidationException $exception) {
            $this->assertEqualsCanonicalizing(['summary', 'ingredients', 'steps', 'media', 'categories'], array_keys($exception->errors()));
        }

        $this->assertSame('draft', $recipe->fresh()->status);
    }

    public function test_remote_media_is_served_by_url_and_replaces_the_previous_cover(): void
    {
        $recipe = Recipe::factory()->create();
        $translations = ['en' => ['alt_text' => 'Dish'], 'el' => ['alt_text' => 'Πιάτο']];
        $action = app(AttachRemoteRecipeMedia::class);

        $first = $action->handle($recipe, 'https://images.unsplash.com/photo-1', $translations, 'cover', 'Unsplash', 'https://unsplash.com/license');
        $second = $action->handle($recipe, 'https://images.unsplash.com/photo-2', $translations, 'cover');

        $this->assertSame('https://images.unsplash.com/photo-2', $recipe->fresh()->coverUrl());
        $this->assertSame('gallery', $recipe->media()->whereKey($first->id)->firstOrFail()->pivot->role);
        $this->assertSame(1, $recipe->media()->wherePivot('role', 'cover')->count());
        $this->assertTrue($second->isRemote());
    }

    public function test_recipes_without_a_cover_use_the_placeholder_image(): void
    {
        $this->assertStringEndsWith('images/recipe-placeholder.svg', Recipe::factory()->create()->coverUrl());
    }

    public function test_database_rejects_a_second_cover_and_malformed_media_sources(): void
    {
        $recipe = Recipe::factory()->complete()->create();
        $extra = Media::factory()->remote()->create();

        try {
            $recipe->media()->attach($extra, ['role' => 'cover', 'sort_order' => 1]);
            $this->fail('A second cover must be rejected.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_remote_media_cannot_carry_a_file_path(): void
    {
        $this->expectException(QueryException::class);

        DB::table('media')->insert(['disk' => 'remote', 'url' => 'https://example.com/a.jpg', 'file_path' => 'x.jpg']);
    }

    public function test_search_ignores_greek_accents_and_case(): void
    {
        $recipe = Recipe::factory()->complete()->create(['status' => 'published', 'published_at' => now()]);
        $recipe->fill(['el' => ['title' => 'Μουσακάς του κυρίου']])->save();

        Livewire::test(RecipeIndex::class, ['locale' => 'el'])
            ->set('search', 'ΜΟΥΣΑΚΑΣ')
            ->assertSee('Μουσακάς του κυρίου');
    }
}
