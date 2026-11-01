<?php

namespace App\Livewire;

use App\Models\ContributorRequest;
use App\Services\ContributorRequestService;
use App\Support\AppPermissions;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

class ContributorApplication extends Component
{
    #[Locked]
    public string $locale = 'en';

    public string $message = '';

    public function mount(string $locale): void
    {
        $this->locale = $locale;
    }

    public function submit(ContributorRequestService $service): void
    {
        $this->resetErrorBag();

        $service->submit(auth()->user(), $this->message, $this->locale);

        $this->message = '';
        session()->flash('status', __('contributor.submitted_flash'));
    }

    public function cancel(ContributorRequestService $service): void
    {
        $pending = ContributorRequest::query()->pending()->where('user_id', auth()->id())->firstOrFail();

        $service->cancel(auth()->user(), $pending);

        session()->flash('status', __('contributor.cancelled_flash'));
    }

    public function render(): View
    {
        app()->setLocale($this->locale);

        $user = auth()->user();
        $latest = ContributorRequest::query()->where('user_id', $user->getKey())->latest('id')->first();

        return view('livewire.contributor-application', [
            'isContributor' => $user->can(AppPermissions::RECIPES_CREATE),
            'canApply' => $user->can('create', ContributorRequest::class),
            'pending' => $latest?->isPending() ? $latest : null,
            'lastRejected' => $latest?->status->value === 'rejected' ? $latest : null,
        ]);
    }
}
