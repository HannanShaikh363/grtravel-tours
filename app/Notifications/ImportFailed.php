<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Throwable;

class ImportFailed extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Throwable $exception,
        public string $importType = 'hotel'
    ) {}

    public function via($notifiable)
    {
        return ['database', 'mail'];
    }

    public function toMail($notifiable)
    {
        return (new MailMessage)
            ->subject(ucfirst($this->importType) . ' Import Failed')
            ->line("Your {$this->importType} import has failed.")
            ->line("Error: {$this->exception->getMessage()}")
            ->action('View Dashboard', url('/dashboard'));
    }

    public function toArray($notifiable)
    {
        return [
            'title' => ucfirst($this->importType) . ' Import Failed',
            'message' => $this->exception->getMessage(),
            'url' => '/dashboard',
            'type' => $this->importType
        ];
    }
}