<?php

namespace App\Notifications;

use App\Models\ContributorRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ContributorRequestApproved extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public ContributorRequest $request, string $locale)
    {
        $this->afterCommit();
        $this->locale($locale);
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('contributor.mail.approved_subject'))
            ->line(__('contributor.mail.approved_line'))
            ->action(__('contributor.mail.open_action'), url('/'.$this->locale.'/contributor'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return ['contributor_request_id' => $this->request->getKey(), 'status' => 'approved'];
    }
}
