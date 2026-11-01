<?php

namespace Tests\Feature;

use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\RecipeShare;
use App\Models\User;
use App\Services\RecipeContentPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RecipeViewingExperienceTest extends TestCase
{
    use RefreshDatabase;

    private const LONG_INTRODUCTION = 'A very long story about pasta. ';

    private function recipe(string $introduction = 'Short intro.', string $visibility = 'public', ?User $author = null): Recipe
    {
        $recipe = Recipe::factory()->create([
            'author_id' => $author?->id ?? User::factory()->contributor()->create()->id,
            'visibility' => $visibility === 'public' ? 'private' : $visibility,
            'status' => 'draft',
            'base_servings' => 4,
            'prep_minutes' => 10,
            'cook_minutes' => 20,
        ]);
        $recipe->fill([
            'en' => ['title' => 'Lemon pasta', 'slug' => 'lemon-pasta-'.$recipe->id, 'summary' => 'Bright.', 'introduction' => $introduction],
            'el' => ['title' => 'Μακαρόνια λεμονιού', 'slug' => 'makaronia-'.$recipe->id, 'summary' => 'Φρέσκα.', 'introduction' => $introduction],
        ])->save();

        $ingredient = Ingredient::factory()->create();
        $ingredient->fill(['en' => ['name' => 'Spaghetti '.$recipe->id], 'el' => ['name' => 'Σπαγγέτι '.$recipe->id]])->save();
        $recipe->recipeIngredients()->create(['ingredient_id' => $ingredient->id, 'quantity' => 200, 'unit' => 'g']);

        foreach ([1 => [10, 'Boil the water.', 'Βράστε το νερό.'], 2 => [null, 'Toss the pasta.', 'Ανακατέψτε τα μακαρόνια.']] as $number => [$minutes, $en, $el]) {
            $step = $recipe->steps()->create(['step_number' => $number, 'duration_minutes' => $minutes]);
            $step->fill(['en' => ['instruction' => $en], 'el' => ['instruction' => $el]])->save();
        }

        if ($visibility === 'public') {
            // Bypass the publishing gate: only the public page is under test here.
            $recipe->forceFill(['visibility' => 'public', 'status' => 'published', 'published_at' => now()])->saveQuietly();
        }

        return $recipe->fresh();
    }

    public function test_ingredients_render_as_labelled_checkboxes_with_a_reset_action(): void
    {
        $recipe = $this->recipe();

        $this->get("/en/recipes/{$recipe->slug}")->assertOk()
            ->assertSee('type="checkbox"', false)
            ->assertSee('for="ingredient-'.$recipe->id.'-0-0"', false)
            ->assertSee('id="ingredient-'.$recipe->id.'-0-0"', false)
            ->assertSee('data-ingredient-reset', false)
            ->assertSee('Reset ingredients');
    }

    public function test_jump_to_recipe_is_offered_only_for_long_introductions(): void
    {
        $short = $this->recipe('A quick dinner.');
        $long = $this->recipe(str_repeat(self::LONG_INTRODUCTION, 40));

        $this->assertGreaterThan(RecipeContentPresenter::LONG_INTRODUCTION_CHARACTERS, mb_strlen(str_repeat(self::LONG_INTRODUCTION, 40)));
        $this->get("/en/recipes/{$short->slug}")->assertOk()->assertDontSee('data-jump-to-recipe', false);
        $this->get("/en/recipes/{$long->slug}")->assertOk()
            ->assertSee('data-jump-to-recipe', false)
            ->assertSee('href="#recipe-content"', false)
            ->assertSee('id="recipe-content"', false)
            ->assertSee('Jump to recipe');
    }

    public function test_greek_page_uses_greek_labels_for_jump_cooking_mode_and_print(): void
    {
        $recipe = $this->recipe(str_repeat('Μεγάλη ιστορία για τα μακαρόνια. ', 30));

        $this->get("/el/recipes/{$recipe->getTranslation('el')->slug}")->assertOk()
            ->assertSee('Μετάβαση στη συνταγή')
            ->assertSee('Έναρξη λειτουργίας μαγειρέματος')
            ->assertSee('Εκτύπωση συνταγής')
            ->assertSee('Επαναφορά υλικών')
            ->assertSee('Βράστε το νερό.');
    }

    public function test_cooking_mode_renders_every_step_with_timer_data_only_where_a_duration_exists(): void
    {
        $recipe = $this->recipe();

        $response = $this->get("/en/recipes/{$recipe->slug}")->assertOk();

        $response->assertSee('data-cooking-overlay', false)
            ->assertSee('data-cooking-start', false)
            ->assertSee('data-wake-toggle', false)
            ->assertSee('data-cooking-close', false)
            ->assertSee('Timer alerts may not sound if this tab is in the background', false);
        $this->assertSame(2, substr_count($response->getContent(), 'data-cooking-step '));
        $this->assertSame(1, substr_count($response->getContent(), 'data-timer-toggle'));
        $this->assertSame(1, substr_count($response->getContent(), 'data-minutes="10"'));
    }

    public function test_print_view_uses_selected_servings_and_scaled_quantities_without_site_chrome(): void
    {
        $recipe = $this->recipe();

        $this->get("/en/recipes/{$recipe->slug}/print?servings=8")->assertOk()
            ->assertSee('Lemon pasta')
            ->assertSee('Servings: 8')
            ->assertSee('400')
            ->assertSeeInOrder(['Boil the water.', 'Toss the pasta.'])
            ->assertSee('Prep: 10 minutes')
            ->assertSee('Cook: 20 minutes')
            ->assertDontSee('site-header', false)
            ->assertDontSee('site-footer', false)
            ->assertDontSee('related-recipes', false)
            ->assertDontSee('type="checkbox"', false)
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }

    public function test_print_view_falls_back_to_base_servings_and_clamps_extreme_values(): void
    {
        $recipe = $this->recipe();

        $this->get("/en/recipes/{$recipe->slug}/print?servings=abc")->assertOk()->assertSee('Servings: 4');
        $this->get("/en/recipes/{$recipe->slug}/print?servings=9999")->assertOk()->assertSee('Servings: 48');
    }

    public function test_print_view_in_greek_shows_greek_content(): void
    {
        $recipe = $this->recipe();

        $this->get("/el/recipes/{$recipe->getTranslation('el')->slug}/print")->assertOk()
            ->assertSee('Μακαρόνια λεμονιού')
            ->assertSee('Οδηγίες')
            ->assertSee('Ανακατέψτε τα μακαρόνια.');
    }

    public function test_print_view_hides_private_recipes_from_everyone_but_the_owner(): void
    {
        $owner = User::factory()->contributor()->create();
        $recipe = $this->recipe(visibility: 'private', author: $owner);

        $this->get("/en/recipes/{$recipe->slug}/print")->assertNotFound();
        $this->actingAs(User::factory()->create())->get("/en/recipes/{$recipe->slug}/print")->assertNotFound();
        $this->actingAs($owner)->get("/en/recipes/{$recipe->slug}/print")
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }

    public function test_print_view_of_a_shared_recipe_is_available_only_while_the_share_is_active(): void
    {
        $owner = User::factory()->contributor()->create();
        $recipe = $this->recipe(visibility: 'shared', author: $owner);
        $guest = User::factory()->create();
        $share = RecipeShare::create(['recipe_id' => $recipe->id, 'user_id' => $guest->id, 'shared_by' => $owner->id]);

        $this->actingAs($guest)->get("/en/recipes/{$recipe->slug}/print")->assertOk();

        $share->forceFill(['revoked_at' => now()])->save();

        $this->actingAs($guest)->get("/en/recipes/{$recipe->slug}/print")->assertNotFound();
    }

    public function test_servings_component_keeps_checkbox_markup_after_scaling(): void
    {
        $recipe = $this->recipe();

        Livewire::test('recipe-servings', ['recipeId' => $recipe->id, 'locale' => 'en', 'baseServings' => 4])
            ->call('increase')
            ->assertSee('data-servings="5"', false)
            ->assertSee('data-ingredient-key="0-0"', false)
            ->assertSee('250');
    }
}
