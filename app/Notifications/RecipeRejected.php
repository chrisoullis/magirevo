<?php

namespace App\Notifications;

use App\Models\Recipe;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RecipeRejected extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Recipe $recipe, public string $reason)
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
            ->subject(__('review.mail.rejected_subject'))
            ->line(__('review.mail.rejected_line', ['title' => $this->recipe->displayTranslation('en')?->title ?? '#'.$this->recipe->getKey()]))
            ->line(__('review.mail.reason', ['reason' => $this->reason]))
            ->action(__('review.mail.edit_action'), url('/admin/recipes/'.$this->recipe->getKey().'/edit'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return ['recipe_id' => $this->recipe->getKey(), 'status' => 'rejected', 'reason' => $this->reason];
    }
}
