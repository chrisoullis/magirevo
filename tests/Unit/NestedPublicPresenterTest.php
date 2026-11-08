<?php

namespace Tests\Unit;

use App\Models\Ingredient;
use App\Models\IngredientGroup;
use App\Models\IngredientGroupTranslation;
use App\Models\IngredientTranslation;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\RecipeTranslation;
use App\Services\RecipeComponentResolver;
use App\Services\RecipeContentPresenter;
use App\Services\ServingAdjustmentService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Mockery;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class NestedPublicPresenterTest extends TestCase
{
    public function test_small_nonzero_quantities_do_not_display_as_zero(): void
    {
        $presenter = app(RecipeContentPresenter::class);

        $this->assertSame('0.0001', $presenter->formatQuantity('0.0001', 'en'));
        $this->assertSame('0,001', $presenter->formatQuantity('0.001', 'el'));
        $this->assertSame('½', $presenter->formatQuantity('0.5', 'en'));
    }

    public function test_nested_content_uses_only_the_authorized_pinned_payload_and_requested_locale(): void
    {
        app()->setLocale('el');
        $recipe = Mockery::mock(Recipe::class)->makePartial();
        $recipe->shouldReceive('getTranslation')->with('el', false)->andReturn(new RecipeTranslation(['slug' => 'parent']));
        $component = [
            'path' => 'first/second', 'revision_id' => 42, 'title' => 'Pinned title',
            'quantity' => '2', 'quantity_max' => null, 'unit' => null, 'quantity_basis' => 'whole_batch',
            'translations_notes' => ['el' => ['preparation_note' => 'Κρύο']],
            'translations' => ['el' => ['title' => 'Pinned title']], 'lines' => [],
            'payload' => ['steps' => [['step_number' => 1, 'duration_minutes' => 3,
                'translations' => ['el' => ['instruction' => 'Παλιά οδηγία'], 'en' => ['instruction' => 'Old instruction']],
            ]]],
        ];
        $this->mock(RecipeComponentResolver::class, function ($mock) use ($recipe, $component): void {
            $mock->shouldReceive('resolve')->once()->with($recipe, null, '4', [], 'display')
                ->andReturn(['components' => [$component], 'unresolved' => [], 'lines' => []]);
        });
        $result = app(RecipeContentPresenter::class)->components($recipe, '4')[0];
        $this->assertSame('Παλιά οδηγία', $result['steps'][0]['text']);
        $this->assertSame('Κρύο', $result['note']);
        $this->assertSame(42, $result['revision_id']);
        $this->assertStringContainsString('path=first%2Fsecond', $result['preview_url']);
        $this->assertArrayNotHasKey('payload', $result);
        $this->assertArrayNotHasKey('translations', $result);
    }

    public function test_mixed_rows_keep_their_group_and_authored_order_without_an_ingredient_on_components(): void
    {
        $recipe = Mockery::mock(Recipe::class)->makePartial();
        $recipe->base_servings = 2;
        $group = Mockery::mock(IngredientGroup::class)->makePartial();
        $group->sort_order = 1;
        $group->shouldReceive('translate')->andReturn(new IngredientGroupTranslation(['name' => 'Sauce']));
        $componentGroup = Mockery::mock(IngredientGroup::class)->makePartial();
        $componentGroup->sort_order = 2;
        $componentGroup->shouldReceive('translate')->andReturn(new IngredientGroupTranslation(['name' => 'Filling']));
        $makeLine = function (int $id, string $type, string $uuid, $lineGroup = null) {
            $line = Mockery::mock(RecipeIngredient::class)->makePartial();
            $line->setRawAttributes(['id' => $id, 'line_type' => $type, 'line_uuid' => $uuid,
                'ingredient_group_id' => $lineGroup ? $lineGroup->sort_order : null,
                'ingredient_id' => $type === 'ingredient' ? $id : null, 'quantity' => null]);
            $line->setRelation('ingredientGroup', $lineGroup);
            $line->setRelation('unit', null);
            $line->setRelation('ingredient', null);
            $line->shouldReceive('translate')->andReturn(null);
            if ($type === 'ingredient') {
                $ingredient = Mockery::mock(Ingredient::class)->makePartial();
                $ingredient->shouldReceive('translate')->andReturn(new IngredientTranslation(['name' => 'Ingredient '.$id]));
                $ingredient->shouldReceive('encyclopediaUrl')->andReturn(null);
                $line->setRelation('ingredient', $ingredient);
            }

            return $line;
        };
        $lines = new Collection([
            $makeLine(1, 'ingredient', 'plain'),
            $makeLine(2, 'ingredient', 'before', $group),
            $makeLine(3, 'recipe', 'z-root', $group),
            $makeLine(4, 'ingredient', 'after', $group),
            $makeLine(5, 'recipe', 'a-root', $componentGroup),
        ]);
        $relation = Mockery::mock(HasMany::class);
        $relation->shouldReceive('with')->andReturnSelf();
        $relation->shouldReceive('orderBy')->andReturnSelf();
        $relation->shouldReceive('get')->andReturn($lines);
        $recipe->shouldReceive('recipeIngredients')->andReturn($relation);
        $presenter = Mockery::mock(RecipeContentPresenter::class, [app(ServingAdjustmentService::class)])->makePartial();
        $components = array_map(fn (string $path): array => [
            'path' => $path, 'amount' => '1 batch', 'title' => 'Pinned '.$path, 'note' => null,
            'ingredients' => [], 'steps' => [], 'can_use_prepared' => false, 'revision_id' => 42, 'preview_url' => '/preview',
        ], ['z-root', 'z-root/child', 'a-root']);
        $presenter->shouldReceive('components')->with($recipe, null)->andReturn($components);

        $groups = $presenter->ingredientGroups($recipe);
        $this->assertSame([null, 'Sauce', 'Filling'], array_column($groups, 'name'));
        $this->assertSame(['before', 'z-root', 'after'], array_column($groups[1]['items'], 'line_uuid'));
        $this->assertSame(['ingredient', 'recipe', 'ingredient'], array_column($groups[1]['items'], 'line_type'));
        $this->assertNull($groups[1]['items'][1]['ingredient_id']);
        $this->assertSame(['z-root', 'z-root/child'], array_column($groups[1]['items'][1]['components'], 'path'));
        $this->assertSame('a-root', $groups[2]['items'][0]['line_uuid']);
        $this->assertSame(['Ingredient 1', 'Ingredient 2', '1 batch Pinned z-root', 'Ingredient 4', '1 batch Pinned a-root'], $presenter->plainIngredients($recipe));
        foreach ($groups as &$group) {
            foreach ($group['items'] as &$item) {
                $item['substitutions'] = [];
                $item['personalChange'] = null;
            }
            unset($item);
        }
        unset($group);
        $html = view('livewire.recipe-servings', [
            'groups' => $groups, 'recipeId' => 7, 'stateVersion' => 8, 'current' => 2, 'base' => 2,
            'min' => 1, 'max' => 48, 'isScaled' => false, 'canPersonalize' => false,
        ])->render();
        $this->assertStringContainsString('id="ingredient-7-0-0"', $html);
        $this->assertStringContainsString('for="ingredient-7-0-0"', $html);
        $this->assertStringContainsString('data-ingredient-key="0-0"', $html);
        $this->assertStringContainsString('id="ingredient-7-1-2"', $html);
        $this->assertStringContainsString('for="ingredient-7-1-2"', $html);
        $this->assertStringContainsString('data-ingredient-reset', $html);
        $this->assertStringContainsString('wire:key="component-z-root"', $html);
        $this->assertSame(1, substr_count($html, 'data-component-path="z-root"'));
        $this->assertSame(1, substr_count($html, 'data-component-path="a-root"'));
        $this->assertLessThan(strpos($html, 'Pinned z-root'), strpos($html, 'Ingredient 2'));
        $this->assertLessThan(strpos($html, 'Ingredient 4'), strpos($html, 'Pinned z-root'));
        $this->assertLessThan(strpos($html, 'Pinned a-root'), strpos($html, 'Filling'));
        $printed = view('recipes.print', [
            'groups' => $groups, 'locale' => 'en', 'servings' => 2, 'steps' => [], 'cover' => null, 'backUrl' => '/',
            'recipe' => (object) ['title' => 'Parent', 'summary' => null, 'prep_minutes' => 0, 'cook_minutes' => 0],
        ])->render();
        $this->assertSame(1, substr_count($printed, 'data-component-path="z-root"'));
        $this->assertSame(1, substr_count($printed, 'data-component-path="a-root"'));
        $this->assertLessThan(strpos($printed, 'Pinned z-root'), strpos($printed, 'Ingredient 2'));
        $this->assertLessThan(strpos($printed, 'Ingredient 4'), strpos($printed, 'Pinned z-root'));

    }

    public function test_component_presentation_preserves_resolver_traversal_order(): void
    {
        app()->setLocale('en');
        $recipe = Mockery::mock(Recipe::class)->makePartial();
        $recipe->shouldReceive('getTranslation')->andReturn(new RecipeTranslation(['slug' => 'parent']));
        $components = array_map(fn (string $path): array => [
            'path' => $path, 'revision_id' => 42, 'title' => $path, 'quantity' => '1',
            'quantity_max' => null, 'unit' => null, 'quantity_basis' => 'whole_batch',
            'translations_notes' => [], 'payload' => [], 'lines' => [],
        ], ['z-root', 'z-root/z-child', 'z-root/a-child', 'a-root']);
        $this->mock(RecipeComponentResolver::class, function ($mock) use ($components): void {
            $mock->shouldReceive('resolve')->andReturn(['components' => $components, 'unresolved' => [], 'lines' => []]);
        });

        $this->assertSame(array_column($components, 'path'), array_column(app(RecipeContentPresenter::class)->components($recipe), 'path'));
    }

    public function test_unresolved_dependencies_never_reach_the_view(): void
    {
        $recipe = new Recipe;
        $this->mock(RecipeComponentResolver::class, function ($mock): void {
            $mock->shouldReceive('resolve')->andReturn(['components' => [['title' => 'Secret']], 'unresolved' => [['code' => 'unavailable']], 'lines' => []]);
        });
        $this->expectException(HttpException::class);
        app(RecipeContentPresenter::class)->components($recipe);
    }
}
