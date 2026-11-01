<?php

namespace App\Livewire;

use App\Services\HouseholdInvitationService;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

class HouseholdInvitationAccept extends Component
{
    #[Locked]
    public string $locale = 'en';

    #[Locked]
    public string $token = '';

    public function mount(string $locale, string $token): void
    {
        $this->locale = $locale;
        $this->token = $token;
    }

    public function accept(HouseholdInvitationService $service): void
    {
        $household = $service->accept(auth()->user(), $this->token);

        session()->flash('status', __('households.flash.joined'));
        $this->redirectRoute('households.show', ['locale' => $this->locale, 'household' => $household->getKey()]);
    }

    public function render(HouseholdInvitationService $service): View
    {
        app()->setLocale($this->locale);

        return view('livewire.household-invitation-accept', [
            'invitation' => $service->preview(auth()->user(), $this->token),
        ]);
    }
}
