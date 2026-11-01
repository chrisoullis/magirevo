<?php

namespace App\Notifications;

use App\Models\ContributorRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ContributorRequestRejected extends Notification implements ShouldQueue
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
            ->subject(__('contributor.mail.rejected_subject'))
            ->line(__('contributor.mail.rejected_line'))
            ->line(__('contributor.mail.rejected_reason', ['reason' => (string) $this->request->rejection_reason]))
            ->action(__('contributor.mail.open_action'), url('/'.$this->locale.'/contributor'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return ['contributor_request_id' => $this->request->getKey(), 'status' => 'rejected'];
    }
}
