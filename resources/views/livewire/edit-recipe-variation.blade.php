<section class="variation-shell">
    <header>
        <span class="eyebrow">{{ __('variations.private') }}</span>
        <h1>{{ __('variations.editor_title') }}</h1>
        <p>{{ __('variations.explanation') }}</p>
        <a href="{{ route('recipes.personal', ['locale' => $locale], false) }}">{{ __('variations.collection') }}</a>
    </header>
    @include('recipes.partials.variation-attribution')
    @if (session('variation-status'))<p class="variation-status" role="status">{{ session('variation-status') }}</p>@endif
    @if ($errors->any())
        <div class="field-error" role="alert"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif
    <form wire:submit="save" class="variation-form">
        <div class="variation-grid">
            @foreach (['en' => 'English', 'el' => 'Ελληνικά'] as $language => $label)
                <fieldset>
                    <legend>{{ $label }}</legend>
                    @foreach (['title', 'summary', 'introduction'] as $field)
                        <label for="variation-{{ $language }}-{{ $field }}">{{ __('variations.'.$field) }}</label>
                        @if ($field === 'title')
                            <input id="variation-{{ $language }}-{{ $field }}" wire:model="draft.translations.{{ $language }}.{{ $field }}" maxlength="255">
                        @else
                            <textarea id="variation-{{ $language }}-{{ $field }}" wire:model="draft.translations.{{ $language }}.{{ $field }}" rows="{{ $field === 'introduction' ? 5 : 3 }}" maxlength="{{ $field === 'introduction' ? 10000 : 1000 }}"></textarea>
                        @endif
                    @endforeach
                    <label for="variation-summary-{{ $language }}">{{ __('variations.change_summary') }}</label>
                    <textarea id="variation-summary-{{ $language }}" wire:model="summaries.{{ $language }}" rows="3" maxlength="5000"></textarea>
                </fieldset>
            @endforeach
        </div>
        <fieldset>
            <legend>{{ __('variations.details') }}</legend>
            <div class="variation-grid">
                @foreach (['prep_minutes', 'cook_minutes', 'rest_minutes', 'base_servings'] as $field)
                    <label>{{ __('variations.'.$field) }}<input type="number" wire:model="draft.recipe.{{ $field }}" min="{{ $field === 'base_servings' ? '0.01' : '0' }}" max="{{ $field === 'base_servings' ? '999999.99' : '10000' }}" step="{{ $field === 'base_servings' ? '0.01' : '1' }}" required></label>
                @endforeach
                <label>{{ __('variations.difficulty') }}<select wire:model="draft.recipe.difficulty"><option value="">—</option>@foreach ($difficulties as $difficulty)<option value="{{ $difficulty->code }}">{{ $difficulty->translate($locale)?->name ?? $difficulty->code }}</option>@endforeach</select></label>
            </div>
        </fieldset>
        <fieldset id="variation-ingredients">
            <legend>{{ __('site.ingredients') }}</legend>
            <p>{{ __('variations.permanent_changes') }}</p>
            <label for="ingredient-search">{{ __('variations.search_ingredients') }}</label>
            <input id="ingredient-search" wire:model.live.debounce.300ms="ingredientSearch" type="search" maxlength="100" autocomplete="off">
            <div class="variation-search" aria-live="polite">
                @foreach ($results as $result)<button type="button" class="button" wire:click="addIngredient({{ $result->id }})">+ {{ $result->translate($locale)?->name }}</button>@endforeach
                @if (mb_strlen(trim($ingredientSearch)) >= 2 && $results->isEmpty())<p>{{ __('variations.no_matches') }}</p>@endif
            </div>
            <div class="variation-groups">
                @foreach ($draft['ingredient_groups'] as $index => $group)
                    <div class="variation-grid" wire:key="group-{{ $index }}">@foreach (['en', 'el'] as $language)<label>{{ __('variations.group') }} ({{ strtoupper($language) }})<input wire:model="draft.ingredient_groups.{{ $index }}.names.{{ $language }}" maxlength="150"></label>@endforeach<button class="button" type="button" wire:click="removeGroup({{ $index }})">{{ __('variations.remove') }}</button></div>
                @endforeach
                <button type="button" class="button" wire:click="addGroup">{{ __('variations.add_group') }}</button>
            </div>
            @foreach ($draft['ingredients'] as $index => $line)
                <section class="variation-row" wire:key="line-{{ $line['id'] ?? 'new' }}-{{ $index }}">
                    <h3>{{ ($line['line_type'] ?? 'ingredient') === 'recipe' ? ($componentTitles[$line['line_uuid'] ?? ''] ?? __('variations.source_unavailable')) : $ingredients->get($line['ingredient_id'])?->translate($locale)?->name }}</h3>
                    <div class="variation-grid">
                        <label>{{ __('variations.quantity') }}<input type="number" wire:model="draft.ingredients.{{ $index }}.quantity" min="0" step="0.0001"></label>
                        <label>{{ __('variations.quantity_max') }}<input type="number" wire:model="draft.ingredients.{{ $index }}.quantity_max" min="0" step="0.0001"></label>
                        <label>{{ __('variations.unit') }}<select wire:model="draft.ingredients.{{ $index }}.unit_id" @disabled(($line['line_type'] ?? 'ingredient') === 'recipe' && in_array($line['quantity_basis'] ?? null, ['whole_batch', 'servings'], true))><option value="">—</option>@foreach ($units as $unit)<option value="{{ $unit->id }}">{{ $unit->translate($locale)?->name ?? $unit->code }}</option>@endforeach</select></label>
                        <label>{{ __('variations.scaling') }}<select wire:model="draft.ingredients.{{ $index }}.scaling_mode">@foreach ($modes as $mode)<option value="{{ $mode->code }}">{{ $mode->translate($locale)?->name ?? $mode->code }}</option>@endforeach</select></label>
                        <label>{{ __('variations.group') }}<select wire:model="draft.ingredients.{{ $index }}.group_index"><option value="">—</option>@foreach ($draft['ingredient_groups'] as $groupIndex => $group)<option value="{{ $groupIndex }}">{{ ($group['names'][$locale] ?? '') ?: (($group['names']['en'] ?? '') ?: $groupIndex + 1) }}</option>@endforeach</select></label>
                    </div>
                    <label class="variation-check"><input type="checkbox" wire:model="draft.ingredients.{{ $index }}.is_optional">{{ __('site.optional') }}</label>
                    <div class="variation-grid">@foreach (['en', 'el'] as $language)
                        <label>{{ __('variations.preparation_note') }} ({{ strtoupper($language) }})<input wire:model="draft.ingredients.{{ $index }}.translations.{{ $language }}.preparation_note" maxlength="255"></label>
                        <label>{{ __('variations.quantity_text') }} ({{ strtoupper($language) }})<input wire:model="draft.ingredients.{{ $index }}.translations.{{ $language }}.quantity_text" maxlength="100"></label>
                    @endforeach</div>
                    <div class="variation-actions"><button type="button" class="button" wire:click="moveRow('ingredients', {{ $index }}, -1)" @disabled($index === 0)>{{ __('variations.up') }}</button><button type="button" class="button" wire:click="moveRow('ingredients', {{ $index }}, 1)" @disabled($loop->last)>{{ __('variations.down') }}</button><button class="button" type="button" wire:click="removeRow('ingredients', {{ $index }})">{{ __('variations.remove') }}</button></div>
                </section>
            @endforeach
        </fieldset>
        <fieldset>
            <legend>{{ __('variations.instructions') }}</legend>
            @foreach ($draft['steps'] as $index => $step)
                <section class="variation-row" wire:key="step-{{ $step['id'] ?? 'new' }}-{{ $index }}">
                    <h3>{{ __('site.step_label', ['number' => $index + 1]) }}</h3>
                    <div class="variation-grid">@foreach (['en', 'el'] as $language)<label>{{ __('variations.instructions') }} ({{ strtoupper($language) }})<textarea wire:model="draft.steps.{{ $index }}.translations.{{ $language }}.instruction" rows="5" maxlength="10000"></textarea></label>@endforeach</div>
                    <label>{{ __('variations.duration') }}<input type="number" wire:model="draft.steps.{{ $index }}.duration_minutes" min="1" max="32000"></label>
                    <div class="variation-actions"><button class="button" type="button" wire:click="moveRow('steps', {{ $index }}, -1)" @disabled($index === 0)>{{ __('variations.up') }}</button><button class="button" type="button" wire:click="moveRow('steps', {{ $index }}, 1)" @disabled($loop->last)>{{ __('variations.down') }}</button><button class="button" type="button" wire:click="removeRow('steps', {{ $index }})">{{ __('variations.remove') }}</button></div>
                </section>
            @endforeach
            <button class="button" type="button" wire:click="addStep">{{ __('variations.add_step') }}</button>
        </fieldset>
        <div class="variation-grid">
            <fieldset><legend>{{ __('variations.categories') }}</legend>@foreach ($categories as $category)<label class="variation-check"><input type="checkbox" wire:model="draft.category_ids" value="{{ $category->id }}">{{ $category->translate($locale)?->name }}</label>@endforeach</fieldset>
            <fieldset><legend>{{ __('variations.tags') }}</legend>@foreach ($tags as $tag)<label class="variation-check"><input type="checkbox" wire:model="draft.tag_ids" value="{{ $tag->id }}">{{ $tag->translate($locale)?->name }}</label>@endforeach</fieldset>
        </div>
        <div class="variation-actions variation-save">
            <button class="button button-dark" type="submit" wire:loading.attr="disabled">{{ __('variations.save_draft') }}</button>
            @php($translation = $recipe->displayTranslation($locale))
            @if ($translation)<a class="button" href="{{ route('recipes.show', ['locale' => $translation->locale, 'slug' => $translation->slug], false) }}">{{ __('variations.preview') }}</a>@endif
            <a class="button" href="#variation-ingredients">{{ __('variations.edit_ingredients') }}</a>
        </div>
    </form>
    <section class="variation-media">
        <h2>{{ __('variations.media') }}</h2>
        <p>{{ __('variations.save_before_media') }}</p>
        <div class="variation-grid">
            @foreach ($recipe->media as $asset)<figure><img src="{{ $asset->publicUrl() }}" alt="{{ $asset->translate($locale)?->alt_text }}" width="320" loading="lazy"><figcaption>{{ $asset->pivot->role === 'cover' ? __('variations.cover') : __('variations.gallery') }}</figcaption><button class="button" type="button" wire:click="removeMedia({{ $asset->id }})">{{ __('variations.remove') }}</button></figure>@endforeach
        </div>
        @foreach ($recipe->steps as $step)
            @if ($step->media->isNotEmpty())
                <h3>{{ __('site.step_label', ['number' => $step->step_number]) }}</h3>
                <div class="variation-grid">@foreach ($step->media as $asset)
                    <div>
                        @if ($asset->media_type === 'image')<img src="{{ $asset->publicUrl() }}" alt="{{ $asset->translate($locale)?->alt_text }}" width="320" loading="lazy">@else<p>{{ __('variations.video') }}</p>@endif
                        <button class="button" type="button" wire:click="removeMedia({{ $asset->id }}, {{ $step->id }})">{{ __('variations.remove') }}</button>
                    </div>
                @endforeach</div>
            @endif
        @endforeach
        <form wire:submit="uploadPhoto">
            <label>{{ __('variations.photo') }}<input type="file" wire:model="photo" accept="image/jpeg,image/png,image/webp" required></label>
            <label>{{ __('variations.photo_role') }}<select wire:model="photoRole">@foreach (['cover', 'gallery', 'step'] as $role)<option value="{{ $role }}">{{ __('variations.'.$role) }}</option>@endforeach</select></label>
            <div class="variation-grid">@foreach (['en', 'el'] as $language)<label>{{ __('variations.alt_text') }} ({{ strtoupper($language) }})<input wire:model="alt.{{ $language }}" maxlength="255" required></label>@endforeach</div>
            <label>{{ __('variations.step') }}<select wire:model="mediaStepId"><option value="">—</option>@foreach ($recipe->steps as $step)<option value="{{ $step->id }}">{{ __('site.step_label', ['number' => $step->step_number]) }}</option>@endforeach</select></label>
            <label class="variation-check"><input type="checkbox" wire:model="mediaRightsConfirmed" required>{{ __('variations.media_rights') }}</label>
            <button class="button button-dark" type="submit" wire:loading.attr="disabled">{{ __('variations.upload_photo') }}</button>
        </form>
        <form wire:submit="addVideo">
            <label>{{ __('variations.video') }}<input type="url" wire:model="videoUrl" maxlength="2048" required></label>
            <label>{{ __('variations.step') }}<select wire:model="mediaStepId" required><option value="">—</option>@foreach ($recipe->steps as $step)<option value="{{ $step->id }}">{{ __('site.step_label', ['number' => $step->step_number]) }}</option>@endforeach</select></label>
            <label class="variation-check"><input type="checkbox" wire:model="mediaRightsConfirmed" required>{{ __('variations.media_rights') }}</label>
            <button class="button" type="submit" wire:loading.attr="disabled">{{ __('variations.add_video') }}</button>
        </form>
    </section>
    @if ($canSubmit)
        <form wire:submit="requestPublication" class="variation-row">
            <h2>{{ __('variations.request_publication') }}</h2>
            <p>{{ __('variations.publication_explanation') }}</p>
            <label class="variation-check"><input type="checkbox" wire:model="publicationRightsConfirmed" required>{{ __('variations.publication_rights_confirm') }}</label>
            <label class="variation-check"><input type="checkbox" wire:model="meaningfulChangesConfirmed" required>{{ __('variations.meaningful_changes_confirm') }}</label>
            <button class="button button-dark" type="submit" wire:loading.attr="disabled">{{ __('variations.request_publication') }}</button>
        </form>
    @else
        <p>{{ __('variations.public_disabled') }}</p>
    @endif
</section>
