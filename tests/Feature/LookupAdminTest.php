<?php

namespace Tests\Feature;

use App\Filament\Resources\Categories\Pages\CreateCategory;
use App\Filament\Resources\Categories\Pages\EditCategory;
use App\Filament\Resources\Categories\Pages\ListCategories;
use App\Filament\Resources\Difficulties\Pages\ListDifficulties;
use App\Filament\Resources\Ingredients\Pages\ListIngredients;
use App\Filament\Resources\ScalingModes\Pages\ListScalingModes;
use App\Filament\Resources\Tags\Pages\ListTags;
use App\Filament\Resources\Units\Pages\ListUnits;
use App\Models\Category;
use App\Models\Difficulty;
use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\ScalingMode;
use App\Models\Tag;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\DifficultySeeder;
use Database\Seeders\ScalingModeSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LookupAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('intl')) {
            $this->markTestSkipped('Filament page rendering requires the PHP intl extension.');
        }

        Filament::setCurrentPanel('admin');
    }

    public function test_only_administrators_can_open_the_lookup_menus(): void
    {
        $reader = User::factory()->create();
        $admin = $this->administrator();

        foreach (['categories', 'tags', 'difficulties', 'ingredients', 'units', 'scaling-modes'] as $path) {
            $this->actingAs($reader)->get("/admin/{$path}")->assertForbidden();
            $this->actingAs($admin)->get("/admin/{$path}")->assertOk();
        }
    }

    public function test_each_lookup_list_shows_its_records(): void
    {
        $admin = $this->administrator();

        $cases = [
            ListCategories::class => Category::factory()->create(),
            ListTags::class => Tag::factory()->create(),
            ListDifficulties::class => Difficulty::factory()->create(),
            ListIngredients::class => Ingredient::factory()->create(),
            ListUnits::class => Unit::factory()->create(),
            ListScalingModes::class => ScalingMode::factory()->create(),
        ];

        foreach ($cases as $page => $record) {
            Livewire::actingAs($admin)
                ->test($page)
                ->set('tableRecordsPerPage', 50)
                ->assertCanSeeTableRecords([$record]);
        }
    }

    public function test_a_category_is_created_and_edited_with_translations(): void
    {
        $admin = $this->administrator();

        Livewire::actingAs($admin)
            ->test(CreateCategory::class)
            ->fillForm([
                'code' => 'soups',
                'is_active' => true,
                'sort_order' => 5,
                'en.name' => 'Soups',
                'en.slug' => 'soups',
                'el.name' => 'Σούπες',
                'el.slug' => 'soupes',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $category = Category::query()->where('code', 'soups')->firstOrFail();
        $this->assertSame('Σούπες', $category->getTranslation('el', false)->name);

        Livewire::actingAs($admin)
            ->test(EditCategory::class, ['record' => $category->getKey()])
            ->assertFormSet(['en.name' => 'Soups', 'el.slug' => 'soupes'])
            ->fillForm(['en.name' => 'Hot soups'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Hot soups', $category->fresh()->getTranslation('en', false)->name);
    }

    public function test_lookup_values_in_use_cannot_be_deleted(): void
    {
        $admin = $this->administrator();
        $category = Category::factory()->create();
        Recipe::factory()->create()->categories()->attach($category);
        $unused = Category::factory()->create();

        Livewire::actingAs($admin)
            ->test(ListCategories::class)
            ->callAction(TestAction::make('delete')->table($category))
            ->callAction(TestAction::make('delete')->table($unused));

        $this->assertModelExists($category);
        $this->assertModelMissing($unused);
    }

    public function test_difficulties_and_scaling_modes_are_seeded_and_drive_the_recipe_form_options(): void
    {
        $this->seed([DifficultySeeder::class, ScalingModeSeeder::class]);
        $this->seed([DifficultySeeder::class, ScalingModeSeeder::class]);

        $this->assertSame(['Easy', 'Medium', 'A little project'], array_keys(Difficulty::options()));
        $this->assertSame(['linear', 'fixed', 'manual'], array_keys(ScalingMode::options()));
        $this->assertSame('Εύκολη', Difficulty::query()->where('code', 'Easy')->first()->getTranslation('el', false)->name);

        Difficulty::query()->where('code', 'Medium')->update(['is_active' => false]);

        $this->assertArrayNotHasKey('Medium', Difficulty::options());
        $this->assertArrayHasKey('Medium', Difficulty::options('Medium'));
    }

    private function administrator(): User
    {
        $admin = User::factory()->admin()->create();

        return $admin;
    }
}
