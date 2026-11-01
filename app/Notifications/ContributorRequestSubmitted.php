<?php

namespace App\Notifications;

use App\Models\ContributorRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to reviewers. Reviewers read the admin panel in English.
 */
class ContributorRequestSubmitted extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public ContributorRequest $request)
    {
        $this->afterCommit();
        $this->locale('en');
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
            ->subject(__('contributor.mail.submitted_subject'))
            ->line(__('contributor.mail.submitted_line', ['name' => $this->request->user->name]))
            ->action(__('contributor.mail.review_action'), url('/admin/contributor-requests'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return ['contributor_request_id' => $this->request->getKey(), 'user_id' => $this->request->user_id];
    }
}
