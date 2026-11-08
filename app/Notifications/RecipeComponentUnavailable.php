<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** No source identifiers, names or private component content leave the access boundary. */
class RecipeComponentUnavailable extends Notification
{
    public function __construct(public int $recipeId) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('A recipe component needs attention')
            ->line('A component of one of your recipes is unavailable. Review the recipe before using or publishing it.')
            ->line('Recipe reference: '.$this->recipeId);
    }
}
