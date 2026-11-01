<?php

namespace App\Notifications;

use App\Models\HouseholdInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class HouseholdInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public HouseholdInvitation $invitation, public string $token, string $locale)
    {
        $this->afterCommit();
        $this->locale(in_array($locale, config('translatable.locales'), true) ? $locale : 'en');
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $inviter = $this->invitation->inviter?->name ?? __('households.mail.someone');
        $household = $this->invitation->household->name;

        return (new MailMessage)
            ->subject(__('households.mail.subject', ['name' => $inviter]))
            ->line(__('households.mail.line', ['name' => $inviter, 'household' => $household, 'role' => $this->invitation->role->label()]))
            ->action(__('households.mail.action'), route('households.invitations.show', ['locale' => $this->locale ?? 'en', 'token' => $this->token]))
            ->line(__('households.mail.expires', ['date' => $this->invitation->expires_at->translatedFormat('j F Y')]));
    }
}
