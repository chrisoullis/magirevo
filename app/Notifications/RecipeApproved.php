<?php

namespace App\Notifications;

use App\Models\Recipe;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RecipeApproved extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Recipe $recipe)
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
        $translation = $this->recipe->displayTranslation('en');

        return (new MailMessage)
            ->subject(__('review.mail.approved_subject'))
            ->line(__('review.mail.approved_line', ['title' => $translation?->title ?? '#'.$this->recipe->getKey()]))
            ->action(__('review.mail.view_action'), url('/en/recipes/'.($translation?->slug ?? '')));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return ['recipe_id' => $this->recipe->getKey(), 'status' => 'approved'];
    }
}
