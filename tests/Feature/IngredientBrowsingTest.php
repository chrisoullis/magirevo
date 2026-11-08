<?php

namespace Tests\Feature;

use App\Livewire\IngredientIndex;
use App\Models\Ingredient;
use App\Models\IngredientCatalogGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class IngredientBrowsingTest extends TestCase
{
    use RefreshDatabase;

    private function ingredient(string $code, string $english, string $greek): Ingredient
    {
        $group = IngredientCatalogGroup::factory()->create(['en' => ['name' => 'Herbs'], 'el' => ['name' => 'Βότανα']]);

        return Ingredient::factory()->create(['code' => $code, 'catalog_group_id' => $group->id,
            'en' => ['name' => $english, 'slug' => $code], 'el' => ['name' => $greek, 'slug' => $code.'-el']]);
    }

    public function test_alphabet_filters_the_current_language_and_folds_greek_accents(): void
    {
        $dill = $this->ingredient('dill', 'Dill', 'Άνηθος');
        $this->ingredient('basil', 'Basil', 'Βασιλικός');

        Livewire::test(IngredientIndex::class, ['locale' => 'el'])->set('letter', 'Α')
            ->assertViewHas('ingredients', fn ($items) => $items->modelKeys() === [$dill->id]);
        Livewire::test(IngredientIndex::class, ['locale' => 'en'])->set('letter', 'D')
            ->assertViewHas('ingredients', fn ($items) => $items->modelKeys() === [$dill->id]);
        Livewire::test(IngredientIndex::class, ['locale' => 'en'])->set('letter', 'A')
            ->assertViewHas('ingredients', fn ($items) => $items->isEmpty());
    }

    public function test_sort_and_clear_filters_use_localized_names(): void
    {
        $dill = $this->ingredient('dill', 'Dill', 'Άνηθος');
        $basil = $this->ingredient('basil', 'Basil', 'Βασιλικός');
        Livewire::test(IngredientIndex::class, ['locale' => 'en'])
            ->assertViewHas('ingredients', fn ($items) => $items->modelKeys() === [$basil->id, $dill->id])
            ->set('sort', 'za')->assertViewHas('ingredients', fn ($items) => $items->modelKeys() === [$dill->id, $basil->id])
            ->set('letter', 'D')->call('clearFilters')->assertSet('letter', '')->assertSet('sort', 'az')
            ->assertViewHas('ingredients', fn ($items) => $items->total() === 2);
    }

    public function test_invalid_filter_urls_are_rejected_and_language_switch_drops_incompatible_letters(): void
    {
        $this->getJson('/en/ingredients?letter=Α')->assertUnprocessable();
        $this->getJson('/el/ingredients?sort=invalid')->assertUnprocessable();
        $this->get('/en/ingredients?letter=A&page=2&sort=za')->assertOk()
            ->assertSee('/el/ingredients?sort=za', false)->assertDontSee('/el/ingredients?letter=A', false);
        Livewire::test(IngredientIndex::class, ['locale' => 'el'])->set('letter', 'A')->assertHasErrors('letter');
    }

    public function test_missing_content_is_presented_honestly_and_the_audit_does_not_change_it(): void
    {
        $ingredient = $this->ingredient('dill', 'Dill', 'Άνηθος');
        $before = $ingredient->fresh()->toArray();
        $this->get('/en/ingredients/dill')->assertOk()->assertSee(__('ingredients.content_pending'))
            ->assertSee('ingredient-placeholder.svg')->assertSee('Άνηθος')->assertSee('ingredients%5B0%5D=dill', false);
        $this->artisan('ingredients:audit-catalogue', ['--json' => true])->assertSuccessful();
        $this->assertSame($before, $ingredient->fresh()->toArray());
        $this->assertDatabaseCount('ingredients', 1);
    }
}
