<?php

namespace App\Livewire;

use App\Enums\TagClassification;
use App\Models\DietaryClaim;
use App\Models\Media;
use App\Models\Recipe;
use App\Models\RecipeRevision;
use App\Models\Tag;
use App\Services\DietaryClaimService;
use App\Services\RecipeComponentResolver;
use App\Services\RecipeRevisionService;
use App\Services\RecipeRevisionSnapshotter;
use App\Services\RecipeRevisionValidator;
use App\Services\Recipes\RecipeEditorData;
use App\Services\Recipes\RecipeEditorFields;
use App\Services\Recipes\RecipeEditorMedia;
use App\Services\Recipes\RecipeEditorService;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Concerns\RestrictsFileUploadsToSchemaComponents;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

class ContributorRecipeStudio extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use RestrictsFileUploadsToSchemaComponents;

    #[Locked]
    public string $locale;

    #[Locked]
    public ?int $recipeId = null;

    #[Locked]
    public ?int $revisionId = null;

    #[Locked]
    public string $editorVersion = '';

    #[Locked]
    public string $creationToken;

    #[Locked]
    public ?string $savedAt = null;

    public array $data = [];

    private ?array $reviewCache = null;

    public function mount(string $locale, ?int $recipeId = null): void
    {
        $this->locale = $locale;
        $this->recipeId = $recipeId;
        $this->creationToken = (string) Str::uuid();
        $recipe = $this->recipe();
        if ($recipe && app(RecipeRevisionService::class)->isLive($recipe)) {
            $this->revisionId = app(RecipeRevisionService::class)->openRevision($recipe)?->id;
        }
        $this->fillEditor();
    }

    private function recipe(): ?Recipe
    {
        abort_unless(in_array($this->locale, config('translatable.locales'), true), 404);
        app()->setLocale($this->locale);
        Gate::authorize('create', Recipe::class);
        if ($this->recipeId === null) {
            return null;
        }
        $recipe = Recipe::query()->findOrFail($this->recipeId);
        abort_unless($recipe->author_id === auth()->id() && $recipe->derived_from_recipe_id === null, 404);
        if (! Gate::allows('update', $recipe) && ! Gate::allows('createDraft', [RecipeRevision::class, $recipe])) {
            Gate::authorize('view', $recipe);
        }

        return $recipe;
    }

    private function target(): Recipe|RecipeRevision|null
    {
        $recipe = $this->recipe();

        return $this->revisionId === null ? $recipe : RecipeRevision::query()->where('recipe_id', $recipe->id)->findOrFail($this->revisionId);
    }

    private function editable(): bool
    {
        $target = $this->target();

        return $target === null || ($target instanceof RecipeRevision ? Gate::allows('edit', $target)
            : ! app(RecipeRevisionService::class)->isLive($target) && $target->status !== 'pending' && Gate::allows('update', $target));
    }

    private function fillEditor(): void
    {
        $this->reviewCache = null;
        $target = $this->target();
        $this->savedAt = $target?->updated_at?->format('H:i:s');
        $data = $target instanceof RecipeRevision ? RecipeEditorData::fromPayload($target->payload)
            : ($target ? RecipeEditorData::fromRecipe($target) : ['prep_minutes' => 0, 'cook_minutes' => 0, 'rest_minutes' => 0, 'base_servings' => 4, 'ingredients_data' => [], 'steps_data' => [], 'ingredient_groups_data' => [], 'step_groups_data' => [], 'step_group_removals' => [], 'categories' => [], 'tags' => []]);
        $this->editorVersion = $target instanceof RecipeRevision ? app(RecipeRevisionService::class)->draftVersion($target)
            : ($target ? app(RecipeEditorService::class)->version($target) : '');
        $this->form->fill($data);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->model($this->target() ?? Recipe::class)->operation($this->recipeId ? 'edit' : 'create')->statePath('data')->disabled(! $this->editable())->components([
            Tabs::make('studio')->tabs([
                Tab::make(__('studio.basics'))->schema([
                    Section::make('English')->schema(RecipeEditorFields::translationFields('en')),
                    Section::make('Ελληνικά')->schema(RecipeEditorFields::translationFields('el')),
                    ...RecipeEditorFields::detailFields(),
                ]),
                Tab::make(__('studio.ingredients'))->schema([RecipeEditorFields::groupsRepeater(), RecipeEditorFields::ingredientsRepeater()]),
                Tab::make(__('studio.instructions'))->schema([...RecipeEditorFields::stepGroupFields(), RecipeEditorFields::stepsRepeater(withMedia: $this->revisionId === null, revisionMedia: $this->revisionId !== null)]),
                Tab::make(__('studio.media'))->schema([\Filament\Schemas\Components\View::make('recipes.partials.studio-media'), ...($this->revisionId ? [RecipeEditorFields::revisionMediaRepeater()] : [])]),
                Tab::make(__('studio.review'))->schema([\Filament\Schemas\Components\View::make('recipes.partials.studio-review')]),
            ])->columnSpanFull(),
        ]);
    }

    private function draftData(): array
    {
        $state = ['data' => $this->form->getRawState()];
        $this->form->dehydrateState($state);
        $this->form->mutateDehydratedState($state);
        $data = $state['data'];
        $data['step_group_removals'] ??= [];
        foreach (['ingredient_groups_data', 'ingredients_data', 'steps_data', 'media_data'] as $field) {
            $data[$field] = array_values($data[$field] ?? []);
        }
        if (array_key_exists('step_groups_data', $data)) {
            $data['step_groups_data'] = array_values($data['step_groups_data']);
        }
        foreach ($data['steps_data'] as &$step) {
            $step['media_items'] = array_values($step['media_items'] ?? []);
            if ($this->revisionId !== null) {
                $step['revision_media'] = array_values($step['revision_media'] ?? []);
            } else {
                unset($step['revision_media']);
            }
        }

        return $data;
    }

    public function save(): void
    {
        $this->reviewCache = null;
        abort_unless($this->editable(), 403);
        $service = app(RecipeEditorService::class);
        $target = $this->target();
        $data = $this->draftData();
        if ($target === null) {
            $id = Cache::lock('studio-create:'.auth()->id().':'.$this->creationToken, 15)->block(5, function () use ($service, $data): int {
                return Cache::remember('studio-created:'.auth()->id().':'.$this->creationToken, 86400, fn () => $service->create(auth()->user(), $data)->id);
            });
            $this->recipeId = $id;
            $this->redirectRoute('recipes.studio.edit', ['locale' => $this->locale, 'recipe' => $id]);

            return;
        }
        $saved = $target instanceof RecipeRevision
            ? $service->saveRevision(auth()->user(), $target, $data, $this->editorVersion)
            : $service->update(auth()->user(), $target, $data, $this->editorVersion);
        $freshData = $saved instanceof RecipeRevision ? RecipeEditorData::fromPayload($saved->payload) : RecipeEditorData::fromRecipe($saved);
        $this->editorVersion = $saved instanceof RecipeRevision ? app(RecipeRevisionService::class)->draftVersion($saved) : $service->version($saved);
        foreach (['ingredients_data', 'steps_data', 'ingredient_groups_data'] as $field) {
            foreach (array_keys($this->data[$field] ?? []) as $index => $key) {
                $this->data[$field][$key]['id'] = $freshData[$field][$index]['id'] ?? null;
            }
        }
        foreach (['en', 'el'] as $locale) {
            $this->data[$locale]['slug'] = $freshData[$locale]['slug'] ?? null;
        }
        $this->savedAt = now()->format('H:i:s');
        $this->resetValidation();
        $this->dispatch('studio-saved', version: $this->editorVersion);
    }

    public function autosave(): void
    {
        if ($this->recipeId === null || ! $this->editable()) {
            return;
        }
        try {
            $this->save();
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $key => $messages) {
                $this->addError($key, implode(' ', $messages));
            }
        }
    }

    public function startRevision(?int $fromRevisionId = null): void
    {
        $recipe = $this->recipe();
        abort_unless($recipe !== null, 404);
        $from = $fromRevisionId ? $recipe->revisions()->findOrFail($fromRevisionId) : null;
        $revision = app(RecipeRevisionService::class)->createDraft(auth()->user(), $recipe, $from);
        $this->revisionId = $revision->id;
        $this->fillEditor();
    }

    public function submit(): void
    {
        $this->save();
        $target = $this->target();
        abort_unless($target !== null, 422);
        app(RecipeEditorService::class)->submit(auth()->user(), $target);
        $this->fillEditor();
    }

    public function uploadImageAction(): Action
    {
        return Action::make('uploadImage')->label(__('studio.upload'))->visible(fn () => $this->recipeId && $this->editable())->schema([
            ...RecipeEditorFields::imageUploadFields(),
            Select::make('role')->label(__('studio.image_role'))->options(['cover' => __('studio.cover'), 'gallery' => __('studio.gallery'), 'step' => __('studio.step_image')])->default('cover')->required(),
            Select::make('step_index')->label(__('studio.step_index'))->options(fn () => collect(array_values($this->data['steps_data'] ?? []))->mapWithKeys(fn ($step, $index) => [$index => (string) ($index + 1)])->all()),
            Toggle::make('rights_confirmed')->label(__('studio.rights'))->accepted(),
        ])->action(function (array $data): void {
            $this->save();
            $target = $this->target();
            $media = app(RecipeEditorMedia::class);
            $translations = ['en' => ['alt_text' => $data['alt_text_en'], 'caption' => $data['caption_en'] ?? null], 'el' => ['alt_text' => $data['alt_text_el'], 'caption' => $data['caption_el'] ?? null]];
            if ($target instanceof RecipeRevision) {
                $media->upload(auth()->user(), $target, $data['file'], $translations, $data['role'], $this->editorVersion, (bool) $data['rights_confirmed'], isset($data['step_index']) ? (int) $data['step_index'] : null);
            } else {
                $media->uploadDraft(auth()->user(), $target, $data['file'], $translations, $data['role'], $this->editorVersion, (bool) $data['rights_confirmed'], isset($data['step_index']) ? (int) $data['step_index'] : null);
            }
            $this->fillEditor();
        });
    }

    public function videoAction(): Action
    {
        return Action::make('video')->label(__('studio.video'))->visible(fn () => $this->recipeId && $this->editable())->schema([
            Select::make('step_index')->label(__('studio.step_index'))->options(fn () => collect(array_values($this->data['steps_data'] ?? []))->mapWithKeys(fn ($step, $index) => [$index => (string) ($index + 1)])->all())->required(),
            TextInput::make('url')->label(__('studio.video_url'))->url()->required()->maxLength(2048),
            TextInput::make('title_en')->label(__('studio.video_title_en'))->required()->maxLength(255),
            TextInput::make('title_el')->label(__('studio.video_title_el'))->required()->maxLength(255),
        ])->action(function (array $data): void {
            $this->save();
            app(RecipeEditorMedia::class)->video(auth()->user(), $this->target(), (int) $data['step_index'], $data['url'], ['en' => ['alt_text' => $data['title_en']], 'el' => ['alt_text' => $data['title_el']]], $this->editorVersion);
            $this->fillEditor();
        });
    }

    public function removeMediaAction(): Action
    {
        return Action::make('removeMedia')->label(__('studio.remove_media'))->color('danger')
            ->visible(fn () => $this->recipeId && $this->editable())->requiresConfirmation()
            ->schema([Select::make('media_id')->label(__('studio.choose_media'))->options(fn () => $this->reviewData()['images']->mapWithKeys(fn ($media) => [$media->id => ($media->getTranslation($this->locale, false)?->alt_text ?? __('studio.unknown_media')).' #'.$media->id])->all())->required()])
            ->action(function (array $data): void {
                $this->save();
                app(RecipeEditorMedia::class)->remove(auth()->user(), $this->target(), (int) $data['media_id'], $this->editorVersion);
                $this->fillEditor();
            });
    }

    public function suggestDietaryAction(): Action
    {
        return Action::make('suggestDietary')->label(__('studio.suggest_dietary'))
            ->visible(fn () => $this->recipeId && $this->editable() && Gate::allows('suggest', [DietaryClaim::class, $this->recipe()]))
            ->schema([Select::make('tag_id')->label(__('studio.dietary_tag'))->options(fn () => Tag::query()->where('is_active', true)->whereIn('classification', TagClassification::claimValues())->with('translations')->get()->mapWithKeys(fn ($tag) => [$tag->id => $tag->getTranslation($this->locale, false)?->name ?? $tag->code])->all())->required()])
            ->action(function (array $data): void {
                $this->save();
                $target = $this->target();
                app(DietaryClaimService::class)->suggest(auth()->user(), $this->recipe(), Tag::query()->findOrFail($data['tag_id']), revision: $target instanceof RecipeRevision ? $target : null);
            });
    }

    public function reviewData(): array
    {
        if ($this->reviewCache !== null) {
            return $this->reviewCache;
        }
        $target = $this->target();
        if ($target === null) {
            return ['payload' => [], 'problems' => [__('studio.create_first')], 'images' => collect()];
        }
        $payload = $target instanceof RecipeRevision ? $target->payload : app(RecipeRevisionSnapshotter::class)->serialize($target->id);
        try {
            app(RecipeRevisionValidator::class)->assertSubmittable($payload, $this->recipeId);
            $problems = [];
        } catch (ValidationException $exception) {
            $problems = collect($exception->errors())->flatten()->all();
        }
        if ($target instanceof RecipeRevision) {
            // Draft previews use the editor's authorization; the public resolver deliberately accepts immutable revisions only.
            Gate::authorize('view', $target);
            try {
                app(RecipeComponentResolver::class)->assertValidPayload($payload, $target->recipe_id, auth()->user(), false);
            } catch (ValidationException) {
                $payload = [];
            }
        } elseif (! app(RecipeComponentResolver::class)->canResolve($target, auth()->user())) {
            $payload = [];
        }
        $ids = collect($payload['media'] ?? [])->pluck('media_id')->merge(collect($payload['steps'] ?? [])->flatMap(fn ($step) => collect($step['media'] ?? [])->pluck('media_id')));

        return $this->reviewCache = compact('payload', 'problems') + ['images' => Media::query()->whereIn('id', $ids)->with('translations')->get()->keyBy('id')];
    }

    public function render(): View
    {
        $recipe = $this->recipe();
        $target = $this->target();
        $history = $recipe ? $recipe->revisions()->select(['id', 'recipe_id', 'revision_number', 'status', 'rejection_reason', 'review_notes', 'created_at'])->paginate(10, ['*'], 'history_page')->withPath(route('recipes.studio.edit', ['locale' => $this->locale, 'recipe' => $recipe->id], false)) : collect();
        $claims = $recipe ? DietaryClaim::query()->where('recipe_id', $recipe->id)
            ->when($this->revisionId !== null, fn ($query) => $query->where('recipe_revision_id', $this->revisionId))
            ->with(['recipe', 'tag.translations', 'revision'])->latest('id')->limit(10)->get()->filter(fn ($claim) => Gate::allows('view', $claim)) : collect();

        return view('livewire.contributor-recipe-studio', ['editable' => $this->editable(), 'recipe' => $recipe, 'target' => $target, 'history' => $history, 'claims' => $claims, 'review' => $this->reviewData()]);
    }
}
