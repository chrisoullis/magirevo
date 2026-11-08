<?php

namespace Tests\Feature;

use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\User;
use App\Services\RecipeComponentDietaryTraversal;
use App\Services\RecipeComponentResolver;
use App\Services\RecipePublishingService;
use App\Services\RecipeRevisionService;
use App\Services\Recipes\RecipeComponentEligibility;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class NestedRecipeReleaseTest extends TestCase
{
    use DatabaseTransactions;

    public function test_disabling_components_blocks_search_public_print_and_resolution_without_changing_data(): void
    {
        $actor = User::factory()->admin()->create();
        $this->actingAs($actor);
        $child = app(RecipePublishingService::class)->publish(Recipe::factory()->complete()->create());
        $parent = Recipe::factory()->complete()->create();
        $line = RecipeIngredient::factory()->for($parent)->component($child->currentRevision)->create();
        app(RecipePublishingService::class)->publish($parent);
        $before = $parent->currentRevision->getRawOriginal();
        config(['nested-recipes.enabled' => false]);

        $this->assertSame([], app(RecipeComponentEligibility::class)->search($actor, '', $parent->id));
        $this->assertFalse(app(RecipeComponentResolver::class)->canResolve($parent, $actor));
        $this->get('/en/recipes/'.$parent->translate('en')->slug)->assertNotFound();
        $this->get('/en/recipes/'.$parent->translate('en')->slug.'/print')->assertNotFound();
        $this->assertTrue(app(RecipeComponentResolver::class)->canResolve($child, $actor));
        $this->assertSame($before, $parent->currentRevision->fresh()->getRawOriginal());
        $this->assertModelExists($line);
        config(['nested-recipes.enabled' => true]);
        $this->assertTrue(app(RecipeComponentResolver::class)->canResolve($parent, $actor));
    }

    public function test_archived_private_components_are_not_eligible_even_for_their_owner(): void
    {
        $actor = User::factory()->contributor()->create();
        $this->actingAs($actor);
        $child = Recipe::factory()->complete()->create(['author_id' => $actor->id]);
        $revision = app(RecipeRevisionService::class)->captureComponentSnapshot($actor, $child);
        $parent = Recipe::factory()->complete()->create(['author_id' => $actor->id]);
        RecipeIngredient::factory()->for($parent)->component($revision)->create();
        $child->update(['status' => 'archived']);

        $this->assertNull(app(RecipeComponentEligibility::class)->source($actor, $child->id, $parent->id));
        $this->assertArrayNotHasKey($child->id, app(RecipeComponentEligibility::class)->search($actor, '', $parent->id));
        $this->assertFalse(app(RecipeComponentResolver::class)->canResolve($parent, $actor));
        $this->assertTrue(app(RecipeComponentDietaryTraversal::class)->inspect($parent)['unresolved']);
    }
}
