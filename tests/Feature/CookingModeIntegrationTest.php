<?php

namespace Tests\Feature;

use App\Models\CookingSession;
use App\Models\Media;
use App\Models\Recipe;
use App\Models\RecipeStepGroup;
use App\Models\User;
use App\Services\CookingSessionService;
use App\Services\RecipePublishingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class CookingModeIntegrationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cooking.sessions_enabled' => true]);
    }

    private function published(bool $parallel = false): Recipe
    {
        $this->actingAs(User::factory()->admin()->create());
        $recipe = Recipe::factory()->complete()->create(['base_servings' => 2, 'author_id' => User::factory()->contributor()->create()->id]);
        $group = RecipeStepGroup::factory()->for($recipe)->create([
            'en' => ['title' => 'Dough'], 'el' => ['title' => 'Ζύμη'],
        ]);
        $recipe->steps()->first()->update(['recipe_step_group_id' => $group->id]);
        $recipe->categories()->first()->fill([
            'en' => ['name' => 'Category', 'slug' => 'category-'.$recipe->id],
            'el' => ['name' => 'Κατηγορία', 'slug' => 'category-el-'.$recipe->id],
        ])->save();
        $recipe->recipeIngredients()->first()->update(['quantity' => 100, 'quantity_max' => null]);
        if ($parallel) {
            $recipe->steps()->first()->update(['duration_minutes' => 1]);
            $photo = Media::factory()->remote()->create([
                'en' => ['alt_text' => 'Dough photo', 'caption' => 'Rest the dough.'],
                'el' => ['alt_text' => 'Φωτογραφία ζύμης', 'caption' => 'Αφήστε τη ζύμη να ξεκουραστεί.'],
            ]);
            $video = Media::query()->create([
                'disk' => 'remote', 'media_type' => Media::TYPE_VIDEO, 'source' => Media::SOURCE_EMBED,
                'url' => 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', 'mime_type' => 'video/embed',
                'en' => ['alt_text' => 'Dough technique'], 'el' => ['alt_text' => 'Τεχνική ζύμης'],
            ]);
            $recipe->steps()->first()->media()->attach([
                $photo->id => ['kind' => 'image', 'sort_order' => 0],
                $video->id => ['kind' => 'video', 'sort_order' => 1],
            ]);
            $filling = RecipeStepGroup::factory()->for($recipe)->create([
                'position' => 1, 'en' => ['title' => 'Filling'], 'el' => ['title' => 'Γέμιση'],
            ]);
            $recipe->steps()->create([
                'step_number' => 2, 'recipe_step_group_id' => $filling->id, 'duration_minutes' => 2,
                'en' => ['instruction' => 'Chop the filling.'], 'el' => ['instruction' => 'Κόψτε τη γέμιση.'],
            ]);
            $recipe->steps()->create([
                'step_number' => 3, 'recipe_step_group_id' => $group->id,
                'en' => ['instruction' => 'Shape the dough.'], 'el' => ['instruction' => 'Πλάστε τη ζύμη.'],
            ]);
        }
        app(RecipePublishingService::class)->publish($recipe);
        auth()->forgetGuards();

        return $recipe->fresh();
    }

    public function test_guests_read_bilingual_pinned_scaled_content_without_database_sessions(): void
    {
        $recipe = $this->published();
        $count = CookingSession::count();
        $this->getJson('/el/recipes/'.$recipe->id.'/cooking-content?servings=4')
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.id', null)->assertJsonPath('data.revision_id', $recipe->current_revision_id)
            ->assertJsonPath('data.content.step_groups.0.translations.el.title', 'Ζύμη')
            ->assertJsonPath('data.ingredients.0.key', 'i0')
            ->assertJsonPath('data.ingredients.0.quantity', '200');
        $this->assertSame($count, CookingSession::count());
    }

    public function test_public_content_route_never_exposes_private_or_foreign_revisions(): void
    {
        $recipe = $this->published();
        $owner = User::factory()->contributor()->create();
        $private = Recipe::factory()->complete()->create(['author_id' => $owner->id]);
        $session = app(CookingSessionService::class)->start($owner, $private->id, (string) Str::uuid());
        $this->actingAs($owner)->getJson('/en/recipes/'.$private->id.'/cooking-content?revision='.$session->recipe_revision_id)->assertNotFound();
        $this->getJson('/en/recipes/'.$recipe->id.'/cooking-content?revision='.$session->recipe_revision_id)->assertNotFound();
    }

    public function test_guest_access_is_rechecked_after_withdrawal(): void
    {
        $recipe = $this->published();
        $url = '/en/recipes/'.$recipe->id.'/cooking-content?revision='.$recipe->current_revision_id;
        $this->getJson($url)->assertOk();
        app(RecipePublishingService::class)->withdraw($recipe->author, $recipe);
        $this->getJson($url)->assertNotFound();
    }

    public function test_private_session_scaled_ingredients_and_instructions_stay_pinned_after_edits(): void
    {
        $owner = User::factory()->contributor()->create();
        $recipe = Recipe::factory()->complete()->create(['author_id' => $owner->id, 'base_servings' => 2]);
        $recipe->recipeIngredients()->first()->update(['quantity' => 100, 'quantity_max' => null]);
        $session = app(CookingSessionService::class)->start($owner, $recipe->id, (string) Str::uuid(), '4');
        $recipe->update(['base_servings' => 10]);
        $recipe->recipeIngredients()->first()->update(['quantity' => 999]);
        $recipe->steps()->first()->fill(['en' => ['instruction' => 'Changed instructions']])->save();
        $this->actingAs($owner)->getJson('/en/cooking-sessions/'.$session->id)->assertOk()
            ->assertJsonPath('data.ingredients.0.quantity', '200')
            ->assertJsonPath('data.content.steps.0.translations.en.instruction', 'Cook it.');
    }

    public function test_overlay_renders_controls_with_safe_json_and_default_disabled_compatibility(): void
    {
        $recipe = $this->published();
        foreach (['en', 'el'] as $locale) {
            $this->get('/'.$locale.'/recipes/'.$recipe->translate($locale)->slug)->assertOk()
                ->assertSee('data-session-enabled="true"', false)->assertSee('data-cooking-group-list', false)
                ->assertSee('data-cooking-servings', false)->assertSee('data-cooking-labels', false);
        }
        config(['cooking.sessions_enabled' => false]);
        $this->getJson('/en/recipes/'.$recipe->id.'/cooking-content')->assertNotFound();
        $this->get('/en/recipes/'.$recipe->translate('en')->slug)->assertOk()->assertSee('data-session-enabled="false"', false);
    }

    public function test_parallel_overlay_and_pinned_content_use_the_same_bilingual_groups(): void
    {
        config(['cooking.step_groups_enabled' => true]);
        $recipe = $this->published(parallel: true);
        foreach (['en', 'el'] as $locale) {
            $page = $this->get('/'.$locale.'/recipes/'.$recipe->translate($locale)->slug)->assertOk();
            $content = $this->getJson('/'.$locale.'/recipes/'.$recipe->id.'/cooking-content')->assertOk()
                ->assertJsonCount(2, 'data.content.step_groups')->assertJsonCount(3, 'data.content.steps');
            $title = $locale === 'en' ? 'Filling' : 'Γέμιση';
            $page->assertSee($title);
            $content->assertJsonPath('data.content.step_groups.1.translations.'.$locale.'.title', $title)
                ->assertJsonPath('data.content.steps.2.step_group_index', 0);

            if ($directory = getenv('COOKING_BROWSER_FIXTURES_DIR')) {
                if (! is_dir($directory)) {
                    mkdir($directory, 0700, true);
                }
                file_put_contents($directory.'/'.$locale.'.html', $page->getContent());
                file_put_contents($directory.'/'.$locale.'.json', $content->getContent());
            }
        }
    }

    public function test_ingredient_reset_is_a_versioned_session_event(): void
    {
        $owner = User::factory()->contributor()->create();
        $recipe = Recipe::factory()->complete()->create(['author_id' => $owner->id]);
        $service = app(CookingSessionService::class);
        $session = $service->start($owner, $recipe->id, (string) Str::uuid());
        $session = $service->update($owner, $session->id, 1, ['action' => 'ingredient', 'ingredient' => 'i0', 'checked' => true]);
        $this->assertSame(['i0' => true], $session->state['checked_ingredients']);
        $session = $service->update($owner, $session->id, 2, ['action' => 'ingredients_reset']);
        $this->assertSame([], $session->state['checked_ingredients']);
        $this->assertSame(3, $session->version);
    }
}
