<?php

namespace Tests\Feature;

use App\Livewire\RecipeServings;
use App\Models\Ingredient;
use App\Models\IngredientSubstitution;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\User;
use App\Models\UserRecipeIngredientChange;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class PersonalRecipeIngredientChangesTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_can_create_update_and_delete_a_private_ingredient_change(): void
    {
        $author = User::factory()->contributor()->create();
        $member = User::factory()->create();
        $recipe = $this->recipe($author);
        $original = $this->ingredient('Chickpeas', 'Ρεβίθια');
        $firstAlternative = $this->ingredient('Butter beans', 'Γίγαντες');
        $secondAlternative = $this->ingredient('Lentils', 'Φακές');
        $line = $this->recipeLine($recipe, $original);

        $component = Livewire::actingAs($member)->test(RecipeServings::class, [
            'recipeId' => $recipe->id,
            'locale' => 'en',
            'baseServings' => 2,
        ]);

        $component
            ->call('editPersonalChange', $line->id)
            ->set('ingredientSearch', 'Γίγαντες')
            ->assertSee('Butter beans')
            ->set('alternativeIngredientId', $firstAlternative->id)
            ->set('personalNote', 'Use the jarred kind.')
            ->call('savePersonalChange', $line->id)
            ->assertHasNoErrors()
            ->assertSee('Butter beans')
            ->assertSee('Use the jarred kind.');

        $this->assertDatabaseHas('user_recipe_ingredient_changes', [
            'user_id' => $member->id,
            'recipe_id' => $recipe->id,
            'recipe_ingredient_id' => $line->id,
            'alternative_ingredient_id' => $firstAlternative->id,
            'note' => 'Use the jarred kind.',
        ]);

        $component
            ->call('editPersonalChange', $line->id)
            ->set('ingredientSearch', 'Lentils')
            ->set('alternativeIngredientId', $secondAlternative->id)
            ->set('personalNote', 'Rinse first.')
            ->call('savePersonalChange', $line->id)
            ->assertHasNoErrors();

        $this->assertSame(
            1,
            UserRecipeIngredientChange::query()
                ->where('user_id', $member->id)
                ->where('recipe_ingredient_id', $line->id)
                ->count(),
        );
        $this->assertDatabaseHas('user_recipe_ingredient_changes', [
            'user_id' => $member->id,
            'recipe_ingredient_id' => $line->id,
            'alternative_ingredient_id' => $secondAlternative->id,
            'note' => 'Rinse first.',
        ]);
        $this->assertSame($original->id, $line->fresh()->ingredient_id);

        $component->call('deletePersonalChange', $line->id)->assertHasNoErrors();

        $this->assertDatabaseMissing('user_recipe_ingredient_changes', [
            'user_id' => $member->id,
            'recipe_ingredient_id' => $line->id,
        ]);
        $this->assertSame($original->id, $line->fresh()->ingredient_id);
    }

    public function test_personal_ingredient_changes_are_visible_only_to_their_owner(): void
    {
        $author = User::factory()->contributor()->create();
        $owner = User::factory()->create();
        $otherMember = User::factory()->create();
        $recipe = $this->recipe($author);
        $original = $this->ingredient('White beans', 'Λευκά φασόλια');
        $alternative = $this->ingredient('Butter beans', 'Γίγαντες');
        $line = $this->recipeLine($recipe, $original);
        $owner->recipeIngredientChanges()->create([
            'recipe_id' => $recipe->id,
            'recipe_ingredient_id' => $line->id,
            'alternative_ingredient_id' => $alternative->id,
            'note' => 'Only for my kitchen.',
        ]);

        Livewire::actingAs($otherMember)->test(RecipeServings::class, [
            'recipeId' => $recipe->id,
            'locale' => 'en',
            'baseServings' => 2,
        ])
            ->assertSee('Add your alternative')
            ->assertDontSee('Butter beans')
            ->assertDontSee('Only for my kitchen.')
            ->call('deletePersonalChange', $line->id)
            ->assertNotFound();

        $this->assertDatabaseHas('user_recipe_ingredient_changes', [
            'user_id' => $owner->id,
            'recipe_ingredient_id' => $line->id,
            'note' => 'Only for my kitchen.',
        ]);
    }

    public function test_recipe_ingredient_lines_from_other_recipes_cannot_be_changed(): void
    {
        $author = User::factory()->contributor()->create();
        $member = User::factory()->create();
        $firstRecipe = $this->recipe($author);
        $secondRecipe = $this->recipe($author);
        $firstLine = $this->recipeLine($firstRecipe, $this->ingredient('First original', 'Πρώτο αρχικό'));
        $otherLine = $this->recipeLine($secondRecipe, $this->ingredient('Other original', 'Άλλο αρχικό'));

        Livewire::actingAs($member)->test(RecipeServings::class, [
            'recipeId' => $firstRecipe->id,
            'locale' => 'en',
            'baseServings' => 2,
        ])
            ->call('editPersonalChange', $otherLine->id)
            ->assertNotFound();

        $this->assertDatabaseMissing('user_recipe_ingredient_changes', [
            'recipe_id' => $firstRecipe->id,
            'recipe_ingredient_id' => $otherLine->id,
        ]);
        $this->assertModelExists($firstLine);
    }

    public function test_database_rejects_a_recipe_ingredient_reference_from_another_recipe(): void
    {
        $author = User::factory()->contributor()->create();
        $member = User::factory()->create();
        $firstRecipe = $this->recipe($author);
        $secondRecipe = $this->recipe($author);
        $otherLine = $this->recipeLine($secondRecipe, $this->ingredient('Wrong recipe line', 'Λάθος γραμμή'));
        $alternative = $this->ingredient('Alternative', 'Εναλλακτικό');

        $this->expectException(QueryException::class);

        DB::table('user_recipe_ingredient_changes')->insert([
            'user_id' => $member->id,
            'recipe_id' => $firstRecipe->id,
            'recipe_ingredient_id' => $otherLine->id,
            'alternative_ingredient_id' => $alternative->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_only_approved_curated_substitutions_for_this_recipe_are_rendered_in_both_locales(): void
    {
        $author = User::factory()->contributor()->create();
        $recipe = $this->recipe($author);
        $original = $this->ingredient('Yogurt', 'Γιαούρτι');
        $globalAlternative = $this->ingredient('Sour cream', 'Ξινή κρέμα');
        $recipeAlternative = $this->ingredient('Labneh', 'Λαμπνέ');
        $pendingAlternative = $this->ingredient('Mayonnaise', 'Μαγιονέζα');
        $this->recipeLine($recipe, $original);

        $globalSubstitution = $this->substitution($original, $globalAlternative, null, 'Use an equal amount.', 'Χρησιμοποιήστε ίση ποσότητα.');
        $recipeSubstitution = $this->substitution($original, $recipeAlternative, $recipe->id, 'Best for this dip.', 'Ιδανικό για αυτό το ντιπ.');
        $pendingSubstitution = IngredientSubstitution::factory()->create([
            'ingredient_id' => $original->id,
            'replacement_ingredient_id' => $pendingAlternative->id,
            'status' => 'pending',
        ]);
        $pendingSubstitution->fill([
            'en' => ['explanation' => 'This suggestion is not approved.'],
            'el' => ['explanation' => 'Αυτή η πρόταση δεν έχει εγκριθεί.'],
        ])->save();

        Livewire::test(RecipeServings::class, [
            'recipeId' => $recipe->id,
            'locale' => 'en',
            'baseServings' => 2,
        ])
            ->assertSee('Sour cream')
            ->assertSee('Use an equal amount.')
            ->assertSee('Labneh')
            ->assertSee('Best for this dip.')
            ->assertDontSee('Mayonnaise')
            ->assertDontSee('This suggestion is not approved.')
            ->assertSee('Substitutions are suggestions only.');

        Livewire::test(RecipeServings::class, [
            'recipeId' => $recipe->id,
            'locale' => 'el',
            'baseServings' => 2,
        ])
            ->assertSee('Ξινή κρέμα')
            ->assertSee('Χρησιμοποιήστε ίση ποσότητα.')
            ->assertSee('Λαμπνέ')
            ->assertSee('Ιδανικό για αυτό το ντιπ.')
            ->assertDontSee('Μαγιονέζα')
            ->assertSee('Οι αντικαταστάσεις είναι μόνο προτάσεις.');

        $this->assertSame($original->id, $recipe->recipeIngredients()->firstOrFail()->ingredient_id);
        $this->assertModelExists($globalSubstitution);
        $this->assertModelExists($recipeSubstitution);
    }

    public function test_recipe_specific_curated_substitution_requires_the_original_ingredient_in_that_recipe(): void
    {
        $author = User::factory()->contributor()->create();
        $recipe = $this->recipe($author);
        $original = $this->ingredient('Absent ingredient', 'Υλικό που απουσιάζει');
        $alternative = $this->ingredient('Replacement', 'Αντικατάσταση');

        $this->expectException(ValidationException::class);

        IngredientSubstitution::factory()->approved()->create([
            'ingredient_id' => $original->id,
            'replacement_ingredient_id' => $alternative->id,
            'recipe_id' => $recipe->id,
        ]);
    }

    private function recipe(User $author): Recipe
    {
        $recipe = Recipe::factory()->create([
            'author_id' => $author->id,
            'visibility' => 'private',
            'status' => 'draft',
        ]);
        $recipe->fill([
            'en' => ['title' => 'Substitution test recipe', 'slug' => 'substitution-test-'.$recipe->id],
            'el' => ['title' => 'Δοκιμή εναλλακτικών', 'slug' => 'dokimi-enallaktikon-'.$recipe->id],
        ])->save();
        $recipe->forceFill(['status' => 'published', 'visibility' => 'public', 'published_at' => now()])->saveQuietly();

        return $recipe->fresh();
    }

    private function ingredient(string $englishName, string $greekName): Ingredient
    {
        $ingredient = Ingredient::factory()->create();
        $ingredient->fill([
            'en' => ['name' => $englishName, 'plural_name' => $englishName],
            'el' => ['name' => $greekName, 'plural_name' => $greekName],
        ])->save();

        return $ingredient->fresh();
    }

    private function recipeLine(Recipe $recipe, Ingredient $ingredient): RecipeIngredient
    {
        return RecipeIngredient::factory()->for($recipe)->create([
            'ingredient_id' => $ingredient->id,
        ]);
    }

    private function substitution(
        Ingredient $original,
        Ingredient $alternative,
        ?int $recipeId,
        string $englishExplanation,
        string $greekExplanation,
    ): IngredientSubstitution {
        $substitution = IngredientSubstitution::factory()->approved()->create([
            'ingredient_id' => $original->id,
            'replacement_ingredient_id' => $alternative->id,
            'recipe_id' => $recipeId,
        ]);
        $substitution->fill([
            'en' => ['explanation' => $englishExplanation],
            'el' => ['explanation' => $greekExplanation],
        ])->save();

        return $substitution;
    }
}
