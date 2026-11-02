<?php

namespace Tests\Feature;

use App\Models\Ingredient;
use App\Models\IngredientSubstitution;
use App\Models\MealPlanEntry;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\User;
use App\Services\RecipePublishingService;
use App\Services\RecipeRevisionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RecipeSubstitutionRevisionTest extends TestCase
{
    use RefreshDatabase;

    private User $author;

    private Recipe $recipe;

    private RecipeIngredient $line;

    protected function setUp(): void
    {
        parent::setUp();

        $this->author = User::factory()->contributor()->create();
        $this->recipe = Recipe::factory()->complete()->create(['author_id' => $this->author->id]);
        $this->actingAs(User::factory()->admin()->create());
        app(RecipePublishingService::class)->publish($this->recipe);
        $this->recipe->refresh();
        $this->line = RecipeIngredient::where('recipe_id', $this->recipe->id)->firstOrFail();
    }

    private function recipeSubstitution(string $status = 'approved'): IngredientSubstitution
    {
        return IngredientSubstitution::factory()->create([
            'ingredient_id' => $this->line->ingredient_id,
            'replacement_ingredient_id' => Ingredient::factory()->create()->id,
            'recipe_id' => $this->recipe->id,
            'status' => $status,
        ]);
    }

    /**
     * @return list<int>
     */
    private function applicableIds(): array
    {
        return IngredientSubstitution::query()->approved()->applicableToRecipe($this->recipe->id)->pluck('id')->all();
    }

    private function publishNewRevision(?callable $change = null): void
    {
        $service = app(RecipeRevisionService::class);
        $draft = $service->createDraft($this->author, $this->recipe->fresh());
        $payload = ($change ?? function (array $payload): array {
            $payload['translations']['en']['title'] = 'Retitled';

            return $payload;
        })($draft->payload);
        $revision = $service->submit($this->author, $service->saveDraft($this->author, $draft, $payload));
        $service->approve(User::factory()->admin()->create(), $revision);
        $this->recipe->refresh();
    }

    public function test_approval_is_bound_to_the_current_published_revision(): void
    {
        $substitution = $this->recipeSubstitution();

        $this->assertSame($this->recipe->current_revision_id, $substitution->validated_revision_id);
        $this->assertContains($substitution->id, $this->applicableIds());
    }

    public function test_a_new_published_revision_does_not_inherit_the_approval(): void
    {
        $substitution = $this->recipeSubstitution();
        $first = $substitution->validated_revision_id;

        $this->publishNewRevision();

        $this->assertNotSame($first, $this->recipe->current_revision_id);
        $this->assertNotContains($substitution->id, $this->applicableIds());
        $this->assertDatabaseHas('ingredient_substitutions', ['id' => $substitution->id, 'validated_revision_id' => $first, 'status' => 'approved']);
    }

    public function test_reapproving_validates_it_for_the_new_revision_only(): void
    {
        $substitution = $this->recipeSubstitution();
        $this->publishNewRevision();

        $substitution->update(['status' => 'pending']);
        $substitution->update(['status' => 'approved']);

        $this->assertSame($this->recipe->current_revision_id, $substitution->fresh()->validated_revision_id);
        $this->assertContains($substitution->id, $this->applicableIds());
    }

    public function test_pending_and_general_substitutions_behave_as_before(): void
    {
        $pending = $this->recipeSubstitution('pending');
        $general = IngredientSubstitution::factory()->approved()->create(['recipe_id' => null]);
        $this->publishNewRevision();

        $this->assertNull($pending->fresh()->validated_revision_id);
        $this->assertNotContains($pending->id, $this->applicableIds());
        $this->assertContains($general->id, $this->applicableIds());
    }

    public function test_another_recipes_approval_is_not_applicable(): void
    {
        $other = Recipe::factory()->complete()->create();
        $otherLine = RecipeIngredient::where('recipe_id', $other->id)->firstOrFail();
        $foreign = IngredientSubstitution::factory()->approved()->create(['ingredient_id' => $otherLine->ingredient_id, 'recipe_id' => $other->id]);

        $this->assertNotContains($foreign->id, $this->applicableIds());
    }

    public function test_personal_and_planned_substitutions_survive_a_revision_and_stay_independent(): void
    {
        $substitution = $this->recipeSubstitution();
        $entry = MealPlanEntry::factory()->create(['recipe_id' => $this->recipe->id]);
        $planned = DB::table('meal_plan_entry_substitutions')->insertGetId([
            'meal_plan_entry_id' => $entry->id, 'recipe_id' => $this->recipe->id, 'recipe_ingredient_id' => $this->line->id,
            'ingredient_substitution_id' => $substitution->id, 'replacement_ingredient_id' => $substitution->replacement_ingredient_id,
            'confirmed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->publishNewRevision(function (array $payload): array {
            $payload['ingredients'][0]['quantity'] = '9';

            return $payload;
        });

        $this->assertDatabaseHas('meal_plan_entry_substitutions', ['id' => $planned, 'ingredient_substitution_id' => $substitution->id]);
        $this->assertNotContains($substitution->id, $this->applicableIds());
    }

    public function test_substitutions_never_carry_dietary_claims(): void
    {
        $substitution = $this->recipeSubstitution();

        $this->publishNewRevision();

        $this->assertDatabaseMissing('ingredient_substitutions', ['id' => $substitution->id, 'validated_revision_id' => $this->recipe->current_revision_id]);
        $this->assertSame(0, DB::table('recipe_dietary_claims')->where('recipe_revision_id', $this->recipe->current_revision_id)->count());
    }
}
