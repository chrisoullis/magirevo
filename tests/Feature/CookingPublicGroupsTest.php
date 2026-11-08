<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Recipe;
use App\Models\RecipeStepGroup;
use App\Models\User;
use App\Services\RecipeContentPresenter;
use App\Services\RecipePublishingService;
use App\Services\RecipeRevisionService;
use App\Services\Recipes\RecipeEditorData;
use App\Services\Recipes\RecipeEditorService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class CookingPublicGroupsTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cooking.step_groups_enabled' => true]);
    }

    private function recipe(bool $publish = true): Recipe
    {
        $recipe = Recipe::factory()->complete()->create(['author_id' => User::factory()->contributor()->create()->id, 'base_servings' => 2]);
        $recipe->categories()->first()->fill([
            'en' => ['name' => 'Category', 'slug' => 'category-'.$recipe->id],
            'el' => ['name' => 'Κατηγορία', 'slug' => 'category-el-'.$recipe->id],
        ])->save();
        $later = RecipeStepGroup::factory()->for($recipe)->create(['position' => 1,
            'en' => ['title' => 'Bake'], 'el' => ['title' => 'Ψήσιμο'],
        ]);
        $first = RecipeStepGroup::factory()->for($recipe)->create(['position' => 0,
            'en' => ['title' => 'Dough', 'description' => 'Prepare the dough.'],
            'el' => ['title' => 'Ζύμη', 'description' => 'Ετοιμάστε τη ζύμη.'],
        ]);
        RecipeStepGroup::factory()->for($recipe)->create(['position' => 2,
            'en' => ['title' => 'Empty stage'], 'el' => ['title' => 'Κενή ομάδα'],
        ]);
        $recipe->steps()->first()->update(['recipe_step_group_id' => $later->id]);
        foreach ([2 => null, 3 => $first->id, 4 => $first->id] as $number => $group) {
            $recipe->steps()->create(['step_number' => $number, 'recipe_step_group_id' => $group,
                'en' => ['instruction' => 'Instruction '.$number], 'el' => ['instruction' => 'Οδηγία '.$number],
            ]);
        }
        $photo = Media::factory()->remote()->create([
            'en' => ['alt_text' => 'Dough photo', 'caption' => 'Pinned caption'],
            'el' => ['alt_text' => 'Φωτογραφία ζύμης', 'caption' => 'Λεζάντα ζύμης'],
        ]);
        $recipe->steps()->where('step_number', 3)->first()->media()->attach($photo, ['kind' => 'image', 'sort_order' => 0]);
        $recipe->recipeIngredients()->first()->update(['quantity' => 100, 'quantity_max' => null]);
        if ($publish) {
            $this->actingAs(User::factory()->admin()->create());
            app(RecipePublishingService::class)->publish($recipe);
            auth()->logout();
        }

        return $recipe->fresh();
    }

    #[TestWith(['en', 'Dough', 'Prepare the dough.', 'Instruction 2', 'Instruction 3', 'Cook it.'])]
    #[TestWith(['el', 'Ζύμη', 'Ετοιμάστε τη ζύμη.', 'Οδηγία 2', 'Οδηγία 3', 'Μαγειρέψτε το.'])]
    public function test_public_and_print_sections_share_cooking_order_and_localized_metadata(string $locale, string $title, string $description, string $ungrouped, string $first, string $last): void
    {
        $recipe = $this->recipe();
        $parameters = ['locale' => $locale, 'slug' => $recipe->translate($locale)->slug];

        $page = $this->get(route('recipes.show', $parameters))->assertOk()
            ->assertSeeInOrder([$ungrouped, $title, $description, $first, $last])->assertDontSee('Empty stage');
        $sections = $page->viewData('stepSections');
        $this->assertSame([2, 3, 4, 1], array_column(array_merge(...array_column($sections, 'steps')), 'number'));
        $this->assertSame($locale === 'el' ? 'Φωτογραφία ζύμης' : 'Dough photo', $sections[1]['steps'][0]['images'][0]['alt_text']);
        $this->get(route('recipes.print', [...$parameters, 'servings' => 4]))->assertOk()
            ->assertSeeInOrder([$ungrouped, $title, $description, $first, $last])
            ->assertSee('200')->assertDontSee('data-cooking-start', false)->assertDontSee('data-step-done', false);
    }

    public function test_schema_uses_nonempty_sections_and_visible_authorized_images(): void
    {
        $recipe = $this->recipe();

        $response = $this->get(route('recipes.show', ['locale' => 'en', 'slug' => $recipe->translate('en')->slug]))->assertOk();
        $schema = $response->viewData('structuredData')[0]['recipeInstructions'];
        $this->assertSame(['HowToSection', 'HowToSection', 'HowToSection'], array_column($schema, '@type'));
        $this->assertSame(['Ungrouped steps', 'Dough', 'Bake'], array_column($schema, 'name'));
        $this->assertSame(['Instruction 3', 'Instruction 4'], array_column($schema[1]['itemListElement'], 'text'));
        $this->assertSame([1, 2], array_column($schema[1]['itemListElement'], 'position'));
        $this->assertSame('HowToStep', $schema[1]['itemListElement'][0]['@type']);
        $this->assertNotEmpty($schema[1]['itemListElement'][0]['image']);
    }

    public function test_disabled_groups_preserve_the_flat_legacy_order_and_schema(): void
    {
        $recipe = $this->recipe();
        config(['cooking.step_groups_enabled' => false]);

        $response = $this->get(route('recipes.show', ['locale' => 'en', 'slug' => $recipe->translate('en')->slug]))->assertOk();
        $this->assertSame([1, 2, 3, 4], array_column($response->viewData('steps'), 'number'));
        $this->assertNull($response->viewData('stepSections')[0]['title']);
        $this->assertSame(['HowToStep', 'HowToStep', 'HowToStep', 'HowToStep'], array_column($response->viewData('structuredData')[0]['recipeInstructions'], '@type'));
    }

    public function test_ungrouped_recipes_keep_an_untitled_list_when_groups_are_enabled(): void
    {
        $recipe = Recipe::factory()->complete()->create();

        $sections = app(RecipeContentPresenter::class)->stepSections($recipe, 'en');

        $this->assertCount(1, $sections);
        $this->assertNull($sections[0]['title']);
        $this->assertSame('Cook it.', $sections[0]['steps'][0]['text']);
    }

    public function test_private_groups_never_leak_to_guests_or_public_structured_data(): void
    {
        $recipe = $this->recipe(false);
        $parameters = ['locale' => 'en', 'slug' => $recipe->translate('en')->slug];

        $this->get(route('recipes.show', $parameters))->assertNotFound();
        $this->get(route('recipes.print', $parameters))->assertNotFound();
        $this->actingAs($recipe->author)->get(route('recipes.show', $parameters))->assertOk()
            ->assertSee('Dough')->assertDontSee('application/ld+json', false)
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }

    public function test_group_titles_and_descriptions_are_escaped_in_html_and_json_ld(): void
    {
        $recipe = $this->recipe(false);
        $title = '</script><script>alert("group")</script>';
        $description = '<img src=x onerror=alert(1)>';
        $recipe->stepGroups()->first()->fill(['en' => ['title' => $title, 'description' => $description]])->save();
        $this->actingAs(User::factory()->admin()->create());
        app(RecipePublishingService::class)->publish($recipe);
        auth()->logout();
        $parameters = ['locale' => 'en', 'slug' => $recipe->translate('en')->slug];

        $this->get(route('recipes.show', $parameters))->assertOk()
            ->assertSee($title)->assertSee($description)->assertDontSee($title, false)->assertDontSee($description, false);
        $this->get(route('recipes.print', $parameters))->assertOk()
            ->assertSee($title)->assertSee($description)->assertDontSee($title, false)->assertDontSee($description, false);
    }

    public function test_unpublished_group_edits_do_not_change_public_or_printed_instructions(): void
    {
        $recipe = $this->recipe();
        $owner = $recipe->author;
        $this->actingAs($owner);
        $revisions = app(RecipeRevisionService::class);
        $draft = $revisions->createDraft($owner, $recipe);
        $data = RecipeEditorData::fromPayload($draft->payload);
        $data['step_groups_data'][0]['translations']['en']['title'] = 'SECRET DRAFT TITLE';
        app(RecipeEditorService::class)->saveRevision($owner, $draft, $data, $revisions->draftVersion($draft));
        auth()->logout();
        $parameters = ['locale' => 'en', 'slug' => $recipe->translate('en')->slug];

        $this->get(route('recipes.show', $parameters))->assertOk()->assertSee('Dough')->assertDontSee('SECRET DRAFT TITLE');
        $this->get(route('recipes.print', $parameters))->assertOk()->assertSee('Dough')->assertDontSee('SECRET DRAFT TITLE');
    }
}
