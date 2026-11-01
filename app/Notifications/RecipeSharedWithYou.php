<?php

namespace App\Notifications;

use App\Models\RecipeShare;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RecipeSharedWithYou extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public RecipeShare $share, string $locale)
    {
        $this->afterCommit();
        $this->locale(in_array($locale, config('translatable.locales'), true) ? $locale : 'en');
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
        $sharer = $this->share->sharer?->name ?? __('sharing.mail.someone');

        return (new MailMessage)
            ->subject(__('sharing.mail.subject', ['name' => $sharer]))
            ->line(__('sharing.mail.line', ['name' => $sharer]))
            ->action(__('sharing.mail.action'), url('/'.$this->locale.'/shared-with-me'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return ['recipe_id' => $this->share->recipe_id, 'share_id' => $this->share->getKey(), 'shared_by' => $this->share->shared_by];
    }
}
