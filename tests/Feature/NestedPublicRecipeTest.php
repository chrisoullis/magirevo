<?php

namespace Tests\Feature;

use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\User;
use App\Services\RecipeContentPresenter;
use App\Services\RecipePublishingService;
use App\Services\RecipeRevisionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NestedPublicRecipeTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_preview_is_pinned_localized_and_never_cached(): void
    {
        [$parent, $source, $line] = $this->nestedRecipe();
        $actor = User::factory()->admin()->create();
        $this->actingAs($actor);
        $revisions = app(RecipeRevisionService::class);
        $draft = $revisions->createDraft($actor, $source);
        $payload = $draft->payload;
        $payload['steps'][0]['translations']['el']['instruction'] = 'NEWER SECRET INSTRUCTION';
        $payload['translations']['el']['title'] = 'NEWER SECRET TITLE';
        $draft = $revisions->saveDraft($actor, $draft, $payload);
        $submitted = $revisions->submit($actor, $draft);
        $revisions->approve(User::factory()->admin()->create(), $submitted);
        auth()->logout();
        $url = route('recipes.component', ['locale' => 'el', 'slug' => $parent->getTranslation('el')->slug, 'path' => $line->line_uuid]);
        $this->get($url)->assertOk()->assertSee('Μαγειρέψτε το.')->assertDontSee('NEWER SECRET')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $this->get($url.'&path=unknown')->assertNotFound();
        $items = app(RecipeContentPresenter::class)->ingredientGroups($parent)[0]['items'];
        $this->assertCount(2, $items);
        $this->assertSame(['ingredient', 'recipe'], array_column($items, 'line_type'));
    }

    public function test_hiding_a_source_closes_preview_print_and_public_parent(): void
    {
        [$parent, $source, $line] = $this->nestedRecipe();
        $this->actingAs($source->author);
        $source->update(['visibility' => 'private']);
        auth()->logout();
        $parameters = ['locale' => 'en', 'slug' => $parent->getTranslation('en')->slug];
        $this->get(route('recipes.component', [...$parameters, 'path' => $line->line_uuid]))->assertNotFound();
        $this->get(route('recipes.print', $parameters))->assertNotFound();
        $this->get(route('recipes.show', $parameters))->assertNotFound();
    }

    private function nestedRecipe(): array
    {
        $this->actingAs(User::factory()->admin()->create());
        $publisher = app(RecipePublishingService::class);
        $source = $publisher->publish(Recipe::factory()->complete()->create())->fresh();
        $parent = Recipe::factory()->complete()->create();
        $line = RecipeIngredient::factory()->for($parent)->component($source->currentRevision)->create();
        $parent = $publisher->publish($parent)->fresh();
        auth()->logout();

        return [$parent, $source, $line];
    }
}
