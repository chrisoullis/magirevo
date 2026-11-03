<?php

namespace App\Livewire;

use App\Enums\TagClassification;
use App\Models\Category;
use App\Models\Difficulty;
use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\ScalingMode;
use App\Models\Tag;
use App\Models\Unit;
use App\Services\RecipeRevisionSnapshotter;
use App\Services\Recipes\RecipeVariationAttribution;
use App\Services\Recipes\RecipeVariationContent;
use App\Services\Recipes\RecipeVariationMedia;
use App\Services\Recipes\RecipeVariationPublishingService;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

class EditRecipeVariation extends Component
{
    use WithFileUploads;

    #[Locked]
    public int $recipeId;

    #[Locked]
    public string $locale;

    #[Locked]
    public string $version;

    public array $draft = [];

    public array $summaries = ['en' => '', 'el' => ''];

    public string $ingredientSearch = '';

    public $photo;

    public string $photoRole = 'cover';

    public ?int $mediaStepId = null;

    public array $alt = ['en' => '', 'el' => ''];

    public bool $mediaRightsConfirmed = false;

    public bool $publicationRightsConfirmed = false;

    public bool $meaningfulChangesConfirmed = false;

    public string $videoUrl = '';

    public function mount(int $recipeId, string $locale): void
    {
        abort_unless(in_array($locale, ['en', 'el'], true), 404);
        $this->recipeId = $recipeId;
        $this->locale = $locale;
        $this->loadDraft($this->recipe());
    }

    private function recipe(): Recipe
    {
        app()->setLocale($this->locale);
        $recipe = Recipe::query()->findOrFail($this->recipeId);
        Gate::authorize('editVariation', $recipe);

        return $recipe;
    }

    private function loadDraft(Recipe $recipe): void
    {
        $this->draft = app(RecipeRevisionSnapshotter::class)->serialize($recipe->id);
        unset($this->draft['media']);
        foreach ($this->draft['steps'] as &$step) {
            unset($step['media']);
        }
        unset($step);
        foreach (['en', 'el'] as $locale) {
            unset($this->draft['translations'][$locale]['slug']);
            $this->draft['translations'][$locale] ??= ['title' => '', 'summary' => '', 'introduction' => '', 'seo_title' => '', 'seo_description' => ''];
            $this->draft['translations'][$locale]['introduction'] = html_entity_decode(strip_tags(str_replace(['<br>', '<br />'], "\n", (string) ($this->draft['translations'][$locale]['introduction'] ?? ''))), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $this->summaries[$locale] = $recipe->derivation->getTranslation($locale, false)?->change_summary ?? '';
        }
        $this->version = app(RecipeVariationContent::class)->version($recipe);
    }

    public function save(): void
    {
        $recipe = app(RecipeVariationContent::class)->save(auth()->user(), $this->recipe(), $this->draft, $this->summaries, $this->version);
        $this->loadDraft($recipe);
        session()->flash('variation-status', __('variations.saved'));
    }

    public function requestPublication(): void
    {
        $this->save();
        $recipe = $this->recipe();
        app(RecipeVariationPublishingService::class)->submit(auth()->user(), $recipe, $this->version, [
            'rights_confirmed' => $this->publicationRightsConfirmed,
            'meaningful_changes_confirmed' => $this->meaningfulChangesConfirmed,
            'summaries' => $this->summaries,
        ]);
        $translation = $recipe->displayTranslation($this->locale);
        $this->redirectRoute('recipes.show', ['locale' => $translation->locale, 'slug' => $translation->slug]);
    }

    public function addIngredient(int $ingredientId): void
    {
        $this->recipe();
        $ingredient = Ingredient::query()->where('is_active', true)->findOrFail($ingredientId);
        $this->draft['ingredients'][] = [
            'id' => null, 'ingredient_id' => $ingredient->id, 'group_index' => null,
            'quantity' => null, 'quantity_max' => null, 'unit_id' => $ingredient->default_unit_id,
            'scaling_mode' => 'linear', 'is_optional' => false,
            'translations' => ['en' => ['preparation_note' => '', 'quantity_text' => ''], 'el' => ['preparation_note' => '', 'quantity_text' => '']],
        ];
        $this->ingredientSearch = '';
    }

    public function addStep(): void
    {
        $this->recipe();
        $this->draft['steps'][] = ['id' => null, 'duration_minutes' => null, 'translations' => ['en' => ['instruction' => ''], 'el' => ['instruction' => '']]];
    }

    public function addGroup(): void
    {
        $this->recipe();
        $this->draft['ingredient_groups'][] = ['id' => null, 'names' => ['en' => '', 'el' => '']];
    }

    public function removeGroup(int $index): void
    {
        $this->recipe();
        abort_unless(isset($this->draft['ingredient_groups'][$index]), 422);
        unset($this->draft['ingredient_groups'][$index]);
        $this->draft['ingredient_groups'] = array_values($this->draft['ingredient_groups']);
        foreach ($this->draft['ingredients'] as &$line) {
            if (isset($line['group_index']) && $line['group_index'] !== '') {
                $group = (int) $line['group_index'];
                $line['group_index'] = $group === $index ? null : ($group > $index ? $group - 1 : $group);
            }
        }
    }

    public function removeMedia(int $mediaId, ?int $stepId = null): void
    {
        $recipe = $this->recipe();
        app(RecipeVariationMedia::class)->remove(auth()->user(), $recipe, $mediaId, $stepId, $this->version);
        $this->version = app(RecipeVariationContent::class)->version($recipe->fresh());
        session()->flash('variation-status', __('variations.media_saved'));
    }

    public function removeRow(string $collection, int $index): void
    {
        $this->recipe();
        abort_unless(in_array($collection, ['ingredients', 'steps'], true) && isset($this->draft[$collection][$index]), 422);
        unset($this->draft[$collection][$index]);
        $this->draft[$collection] = array_values($this->draft[$collection]);
    }

    public function moveRow(string $collection, int $index, int $direction): void
    {
        $this->recipe();
        abort_unless(in_array($collection, ['ingredients', 'steps'], true) && in_array($direction, [-1, 1], true), 422);
        $other = $index + $direction;
        if (isset($this->draft[$collection][$index], $this->draft[$collection][$other])) {
            [$this->draft[$collection][$index], $this->draft[$collection][$other]] = [$this->draft[$collection][$other], $this->draft[$collection][$index]];
        }
    }

    public function uploadPhoto(): void
    {
        $recipe = $this->recipe();
        $this->validate(['photo' => ['required', 'image', 'max:10240']]);
        app(RecipeVariationMedia::class)->upload(auth()->user(), $recipe, $this->photo, $this->photoRole, $this->mediaStepId, $this->alt, $this->mediaRightsConfirmed, $this->version);
        $this->version = app(RecipeVariationContent::class)->version($recipe->fresh());
        $this->reset('photo', 'mediaRightsConfirmed');
        session()->flash('variation-status', __('variations.media_saved'));
    }

    public function addVideo(): void
    {
        $recipe = $this->recipe();
        $this->validate(['mediaStepId' => ['required', 'integer']]);
        app(RecipeVariationMedia::class)->video(auth()->user(), $recipe, $this->mediaStepId, $this->videoUrl, $this->mediaRightsConfirmed, $this->version);
        $this->version = app(RecipeVariationContent::class)->version($recipe->fresh());
        $this->reset('videoUrl', 'mediaRightsConfirmed');
        session()->flash('variation-status', __('variations.media_saved'));
    }

    public function render()
    {
        $recipe = $this->recipe();
        foreach (['recipe', 'translations', 'ingredients', 'ingredient_groups', 'steps', 'category_ids', 'tag_ids'] as $collection) {
            abort_unless(is_array($this->draft[$collection] ?? null), 422);
        }
        abort_if(count($this->draft['ingredients']) > 150 || count($this->draft['steps']) > 100 || count($this->draft['ingredient_groups']) > 30, 422);
        foreach (['ingredients', 'ingredient_groups', 'steps'] as $collection) {
            abort_unless(collect($this->draft[$collection])->every(fn ($row) => is_array($row)), 422);
        }
        $ids = collect($this->draft['ingredients'])->pluck('ingredient_id')->filter(fn ($id) => is_scalar($id) && ctype_digit((string) $id) && strlen((string) $id) < 19)->map(fn ($id) => (int) $id);
        $ingredients = Ingredient::query()->with('translations')->whereIn('id', $ids)->get()->keyBy('id');
        $search = trim($this->ingredientSearch);
        $results = mb_strlen($search) >= 2 && mb_strlen($search) <= 100
            ? Ingredient::query()->where('is_active', true)->whereHas('translations', fn ($q) => $q->where('name', 'ilike', '%'.addcslashes($search, '%_\\').'%'))->with('translations')->limit(12)->get()
            : collect();

        return view('livewire.edit-recipe-variation', [
            'canSubmit' => Gate::allows('submitVariation', $recipe),
            'recipe' => $recipe, 'ingredients' => $ingredients, 'results' => $results,
            'attribution' => app(RecipeVariationAttribution::class)->present($recipe, auth()->user(), $this->locale),
            'units' => Unit::query()->with('translations')->orderBy('sort_order')->get(),
            'modes' => ScalingMode::query()->with('translations')->get(),
            'difficulties' => Difficulty::query()->with('translations')->get(),
            'categories' => Category::query()->publiclyVisible()->with('translations')->get(),
            'tags' => Tag::query()->where('is_active', true)->whereIn('classification', TagClassification::freelyPublicValues())->with('translations')->get(),
        ]);
    }
}
