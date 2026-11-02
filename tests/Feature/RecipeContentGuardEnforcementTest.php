<?php

namespace Tests\Feature;

use App\Actions\SyncRecipeContent;
use App\Enums\RecipeRevisionStatus;
use App\Filament\Resources\RecipeRevisions\Pages\ViewRecipeRevision;
use App\Filament\Resources\Recipes\Pages\EditRecipe;
use App\Models\Category;
use App\Models\Recipe;
use App\Models\RecipeStep;
use App\Models\Tag;
use App\Models\User;
use App\Services\RecipeContentGuard;
use App\Services\RecipePublishingService;
use App\Services\RecipeRevisionService;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Readiness checks before the content guard is switched to enforce. Passing them shows the guard refuses
 * unreviewed writes in these paths; it is not an exhaustive proof.
 */
class RecipeContentGuardEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private Recipe $recipe;

    private User $author;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->author = User::factory()->contributor()->create();
        $this->recipe = Recipe::factory()->complete()->create(['author_id' => $this->author->id]);
        $this->actingAs(User::factory()->admin()->create());
        app(RecipePublishingService::class)->publish($this->recipe);
        $this->recipe->refresh();
        app(RecipeContentGuard::class)->setMode('enforce');
    }

    private function assertRefused(callable $write, string $label): void
    {
        DB::beginTransaction();

        try {
            $write();
            $this->fail("Enforced guard accepted: {$label}");
        } catch (QueryException) {
            $this->assertTrue(true);
        } finally {
            DB::rollBack();
        }
    }

    public function test_pivots_steps_and_translations_are_refused_through_eloquent_and_raw_sql(): void
    {
        $step = RecipeStep::query()->where('recipe_id', $this->recipe->id)->firstOrFail();

        $writes = [
            'tag attach' => fn () => $this->recipe->tags()->attach(Tag::factory()->create()),
            'category sync' => fn () => $this->recipe->categories()->sync([Category::factory()->create()->getKey()]),
            'media detach' => fn () => $this->recipe->media()->detach(),
            'step create' => fn () => $this->recipe->steps()->create(['step_number' => 99]),
            'step delete' => fn () => $step->delete(),
            'step translation' => fn () => DB::table('recipe_step_translations')->where('recipe_step_id', $step->id)->update(['instruction' => 'x']),
            'ingredient line update' => fn () => $this->recipe->recipeIngredients()->first()->update(['quantity' => 42]),
            'ingredient line delete' => fn () => $this->recipe->recipeIngredients()->delete(),
            'recipe timing' => fn () => $this->recipe->update(['cook_minutes' => 999]),
            'recipe translation' => fn () => $this->recipe->translate('en')->update(['title' => 'Hacked']),
        ];

        foreach ($writes as $label => $write) {
            $this->assertRefused($write, $label);
        }
    }

    public function test_the_existing_editor_action_cannot_write_to_a_live_recipe(): void
    {
        $this->assertRefused(fn () => app(SyncRecipeContent::class)->handle($this->recipe, [], []), 'SyncRecipeContent');
    }

    public function test_status_changes_for_unpublishing_remain_possible_under_enforce(): void
    {
        app(RecipePublishingService::class)->unpublish($this->recipe);

        $this->assertSame('draft', $this->recipe->fresh()->status);
    }

    public function test_review_approval_and_rollback_work_through_filament_under_enforce(): void
    {
        $service = app(RecipeRevisionService::class);
        $original = $this->recipe->currentRevision;
        $draft = $service->createDraft($this->author, $this->recipe);
        $payload = $draft->payload;
        $payload['translations']['en']['title'] = 'Reviewed title';
        $revision = $service->submit($this->author, $service->saveDraft($this->author, $draft, $payload));

        Livewire::test(ViewRecipeRevision::class, ['record' => $revision->getKey()])->callAction('approve', ['note' => 'fine']);

        $this->assertSame('Reviewed title', $this->recipe->fresh()->translate('en')->title);

        Livewire::test(ViewRecipeRevision::class, ['record' => $original->getKey()])->callAction('rollback', ['note' => 'revert']);

        $this->assertSame($original->payload['translations']['en']['title'], $this->recipe->fresh()->translate('en')->title);
        $this->assertSame(RecipeRevisionStatus::Superseded, $revision->fresh()->status);
    }

    public function test_the_recipe_edit_page_does_not_reach_the_database_for_a_live_recipe(): void
    {
        $title = $this->recipe->translate('en')->title;

        Livewire::test(EditRecipe::class, ['record' => $this->recipe->getKey()])
            ->fillForm(['en.title' => 'Direct edit'])
            ->call('save')
            ->assertNotified('This recipe is public');

        $this->assertSame($title, $this->recipe->fresh()->translate('en')->title);
    }
}
