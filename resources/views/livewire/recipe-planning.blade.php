<section class="recipe-planning kitchen-panel" id="recipe-planning" aria-labelledby="recipe-planning-title">
    <div class="recipe-planning-heading"><x-ui.icon name="calendar" /><div><h2 id="recipe-planning-title">{{ __('recipe_detail.plan_title') }}</h2><p>{{ __('recipe_detail.plan_intro') }}</p></div></div>
    @if ($added)
        <p role="status">{{ $shoppingUrl ? __('recipe_detail.shopping_added') : __('recipe_detail.plan_added') }}</p>
        @if ($excludedEntries > 0)<p role="status">{{ trans_choice('planner.shopping.flash.generated_with_exclusions', $excludedEntries, ['count' => $excludedEntries]) }}</p>@endif
        <div class="recipe-planning-actions"><x-ui.button :href="$plannerUrl" variant="secondary">{{ __('recipe_detail.open_plan') }}</x-ui.button>@if ($shoppingUrl)<x-ui.button :href="$shoppingUrl">{{ __('recipe_detail.open_shopping') }}</x-ui.button>@endif</div>
    @elseif ($canPlan)
        <form wire:submit="addToPlan" class="recipe-planning-form">
            <div><label for="recipe-plan-household">{{ __('recipe_detail.plan_for') }}</label><select id="recipe-plan-household" wire:model="householdId"><option value="">{{ __('recipe_detail.personal_plan') }}</option>@foreach ($households as $household)<option value="{{ $household->id }}" wire:key="plan-household-{{ $household->id }}">{{ $household->name }}</option>@endforeach</select></div>
            <div><label for="recipe-plan-date">{{ __('recipe_detail.date') }}</label><input id="recipe-plan-date" type="date" wire:model="date" required></div>
            <div><label for="recipe-plan-meal">{{ __('recipe_detail.meal') }}</label><select id="recipe-plan-meal" wire:model="meal">@foreach (\App\Enums\MealType::cases() as $type)<option value="{{ $type->value }}">{{ $type->label() }}</option>@endforeach</select></div>
            <div><label for="recipe-plan-servings">{{ __('site.servings') }}</label><input id="recipe-plan-servings" type="number" min="1" max="48" wire:model="servings" required></div>
            <p class="recipe-plan-note">{{ __('recipe_detail.shopping_note') }}</p>
            @foreach ($errors->all() as $message)<p class="field-error" role="alert">{{ $message }}</p>@endforeach
            <div class="recipe-planning-actions"><button class="kitchen-button kitchen-button-secondary" type="submit" wire:loading.attr="disabled">{{ __('recipe_detail.add_plan') }}</button><button class="kitchen-button kitchen-button-primary" type="button" wire:click="addToShopping" wire:loading.attr="disabled"><x-ui.icon name="shopping" />{{ __('recipe_detail.add_shopping') }}</button><span role="status" wire:loading>{{ __('recipe_detail.saving') }}</span></div>
        </form>
    @elseif (auth()->check() && auth()->user()->hasVerifiedEmail())
        <p>{{ __('recipe_detail.plan_unavailable') }}</p>
    @else
        <p>{{ __('recipe_detail.plan_sign_in') }}</p><x-ui.button :href="auth()->check() ? route('verification.notice', [], false) : route('login', [], false)" variant="secondary">{{ auth()->check() ? __('site.verify_email') : __('site.log_in') }}</x-ui.button>
    @endif
</section>
