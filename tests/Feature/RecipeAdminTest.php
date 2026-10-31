<?php

namespace Tests\Feature;

use App\Filament\Resources\Recipes\Pages\CreateRecipe;
use App\Filament\Resources\Recipes\Pages\EditRecipe;
use App\Filament\Resources\Recipes\Pages\ListRecipes;
use App\Filament\Resources\Recipes\RelationManagers\MediaRelationManager;
use App\Models\Category;
use App\Models\Ingredient;
use App\Models\Media;
use App\Models\Recipe;
use App\Models\RecipeStep;
use App\Models\Unit;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class RecipeAdminTest extends TestCase
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

    public function test_only_verified_administrators_can_manage_recipes(): void
    {
        $recipe = Recipe::factory()->create();
        $reader = User::factory()->create();

        foreach (['/admin/recipes', '/admin/recipes/create', "/admin/recipes/{$recipe->id}/edit"] as $url) {
            $this->actingAs($reader)->get($url)->assertForbidden();
        }

        $this->assertFalse($reader->can('publish', $recipe));
        $this->assertFalse($this->administrator()->can('forceDelete', $recipe));
    }

    public function test_administrators_see_recipes_in_the_list(): void
    {
        $recipe = $this->recipeWithTranslations();

        Livewire::actingAs($this->administrator())
            ->test(ListRecipes::class)
            ->assertCanSeeTableRecords([$recipe])
            ->searchTable('Roast')
            ->assertCanSeeTableRecords([$recipe]);
    }

    public function test_administrators_create_a_bilingual_recipe_with_ingredients_steps_and_taxonomy(): void
    {
        $admin = $this->administrator();
        $ingredient = Ingredient::factory()->create();
        $unit = Unit::factory()->create();
        $category = Category::factory()->create();

        Livewire::actingAs($admin)
            ->test(CreateRecipe::class)
            ->fillForm([
                'en' => ['title' => 'Roast chicken', 'slug' => 'roast-chicken'],
                'el' => ['title' => 'Ψητό κοτόπουλο', 'slug' => 'psito-kotopoulo'],
                'difficulty' => 'Easy',
                'prep_minutes' => 10,
                'cook_minutes' => 60,
                'rest_minutes' => 0,
                'base_servings' => 4,
                'categories' => [$category->id],
                'ingredients_data' => [[
                    'ingredient_id' => $ingredient->id,
                    'quantity' => 2,
                    'unit_id' => $unit->id,
                    'scaling_mode' => 'linear',
                    'preparation_note_en' => 'chopped',
                    'preparation_note_el' => 'ψιλοκομμένο',
                ]],
                'steps_data' => [[
                    'instruction_en' => 'Roast it.',
                    'instruction_el' => 'Ψήστε το.',
                    'duration_minutes' => 45,
                ]],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $recipe = Recipe::query()->whereTranslation('slug', 'roast-chicken', 'en')->firstOrFail();

        $this->assertSame($admin->id, $recipe->author_id);
        $this->assertSame('draft', $recipe->status);
        $this->assertSame('Ψητό κοτόπουλο', $recipe->translate('el')->title);
        $this->assertSame($category->id, $recipe->categories()->firstOrFail()->id);
        $this->assertSame('Ψήστε το.', $recipe->steps()->firstOrFail()->translate('el')->instruction);
        $this->assertSame('ψιλοκομμένο', $recipe->recipeIngredients()->firstOrFail()->translate('el')->preparation_note);
    }

    public function test_recipe_form_validates_required_translations_unique_slugs_and_quantities(): void
    {
        $existing = $this->recipeWithTranslations();
        $ingredient = Ingredient::factory()->create();

        Livewire::actingAs($this->administrator())
            ->test(CreateRecipe::class)
            ->fillForm([
                'en' => ['title' => 'Another', 'slug' => $existing->translate('en')->slug],
                'el' => ['title' => '', 'slug' => 'Bad Slug'],
                'prep_minutes' => -1,
                'base_servings' => 0,
                'ingredients_data' => [['ingredient_id' => $ingredient->id, 'quantity' => 5, 'quantity_max' => 2]],
                'steps_data' => [],
            ])
            ->call('create')
            ->assertHasFormErrors([
                'en.slug' => 'unique',
                'el.title' => 'required',
                'el.slug' => 'regex',
                'prep_minutes' => 'min',
                'base_servings' => 'min',
                'steps_data' => 'min',
            ]);
    }

    public function test_editing_reorders_and_removes_steps_without_unique_conflicts(): void
    {
        $recipe = $this->recipeWithTranslations();
        $recipe->recipeIngredients()->create(['ingredient_id' => Ingredient::factory()->create()->id]);
        [$first, $second] = $recipe->steps()->get()->all();

        $component = Livewire::actingAs($this->administrator())
            ->test(EditRecipe::class, ['record' => $recipe->getRouteKey()])
            ->assertFormSet(['en.title' => 'Roast chicken', 'el.slug' => 'psito-kotopoulo']);

        $steps = $component->get('data.steps_data');
        $component->set('data.steps_data', array_reverse($steps))
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame([$second->id, $first->id], $recipe->steps()->pluck('id')->all());

        $component->set('data.steps_data', array_slice($component->get('data.steps_data'), 0, 1))
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(1, RecipeStep::query()->where('recipe_id', $recipe->id)->count());
    }

    public function test_publishing_workflow_requires_complete_content_and_follows_allowed_transitions(): void
    {
        Storage::fake('public');
        $recipe = $this->recipeWithTranslations();
        $admin = $this->administrator();

        $component = Livewire::actingAs($admin)
            ->test(EditRecipe::class, ['record' => $recipe->getRouteKey()])
            ->callAction('publish');

        $this->assertSame('draft', $recipe->fresh()->status);

        $recipe->categories()->attach(Category::factory()->create());
        $recipe->media()->attach(Media::factory()->create(), ['role' => 'cover', 'sort_order' => 0]);
        $ingredient = Ingredient::factory()->create();
        $ingredient->fill(['en' => ['name' => 'Chicken'], 'el' => ['name' => 'Κοτόπουλο']])->save();
        $recipe->recipeIngredients()->create(['ingredient_id' => $ingredient->id]);

        $component->callAction('publish');
        $recipe->refresh();
        $this->assertSame('published', $recipe->status);
        $this->assertNotNull($recipe->published_at);

        $component->assertActionHidden('publish')->callAction('unpublish');
        $this->assertSame('draft', $recipe->fresh()->status);

        $component->callAction('submitForReview');
        $this->assertSame('pending', $recipe->fresh()->status);

        $component->assertActionHidden('archive')->callAction('publish');
        $this->assertSame('published', $recipe->fresh()->status);

        $component->callAction('archive');
        $this->assertSame('archived', $recipe->fresh()->status);

        $component->callAction('restoreToDraft');
        $this->assertSame('draft', $recipe->fresh()->status);
    }

    public function test_cover_images_are_unique_and_can_be_swapped(): void
    {
        Storage::fake('public');
        $recipe = $this->recipeWithTranslations();
        $first = Media::factory()->create();
        $second = Media::factory()->create();
        $recipe->media()->attach($first, ['role' => 'cover', 'sort_order' => 0]);
        $recipe->media()->attach($second, ['role' => 'gallery', 'sort_order' => 1]);

        Livewire::actingAs($this->administrator())
            ->test(MediaRelationManager::class, ['ownerRecord' => $recipe, 'pageClass' => EditRecipe::class])
            ->callAction(TestAction::make('makeCover')->table($second));

        $this->assertSame([$second->id], $recipe->media()->wherePivot('role', 'cover')->pluck('media.id')->all());

        Livewire::actingAs($this->administrator())
            ->test(MediaRelationManager::class, ['ownerRecord' => $recipe, 'pageClass' => EditRecipe::class])
            ->callAction(TestAction::make('upload')->table(), [
                'file' => UploadedFile::fake()->createWithContent('new.png', $this->pngContent(800, 600)),
                'role' => 'cover',
                'alt_text_en' => 'New cover',
                'alt_text_el' => 'Νέο εξώφυλλο',
            ])
            ->assertHasNoFormErrors();

        $this->assertSame(1, $recipe->media()->wherePivot('role', 'cover')->count());
        $this->assertSame(3, $recipe->media()->count());
    }

    private function administrator(): User
    {
        $admin = User::factory()->admin()->create();

        return $admin;
    }

    private function recipeWithTranslations(): Recipe
    {
        $recipe = Recipe::factory()->create();
        $recipe->fill([
            'en' => ['title' => 'Roast chicken', 'slug' => 'roast-chicken', 'summary' => 'Crisp roast chicken.'],
            'el' => ['title' => 'Ψητό κοτόπουλο', 'slug' => 'psito-kotopoulo', 'summary' => 'Τραγανό ψητό κοτόπουλο.'],
        ])->save();

        foreach ([1 => 'Season.', 2 => 'Roast.'] as $number => $text) {
            $step = $recipe->steps()->create(['step_number' => $number]);
            $step->translations()->createMany([
                ['locale' => 'en', 'instruction' => $text],
                ['locale' => 'el', 'instruction' => "{$text} (el)"],
            ]);
        }

        return $recipe;
    }

    private function pngContent(int $width, int $height): string
    {
        $chunk = static fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

        return "\x89PNG\r\n\x1a\n"
            .$chunk('IHDR', pack('N2C5', $width, $height, 8, 2, 0, 0, 0))
            .$chunk('IDAT', gzcompress(str_repeat("\0".str_repeat("\0", $width * 3), $height)))
            .$chunk('IEND', '');
    }
}
