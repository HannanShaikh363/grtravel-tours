<?php

namespace App\Notifications;

use Illuminate\Bus\Batch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ImportCompleted extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Batch $batch,
        public string $importType = 'hotel'
    ) {}

    public function via($notifiable)
    {
        return ['database', 'mail'];
    }

    public function toMail($notifiable)
    {
        return (new MailMessage)
            ->subject(ucfirst($this->importType) . ' Import Completed')
            ->line("Your {$this->importType} import has completed successfully.")
            ->line("Processed jobs: {$this->batch->processedJobs()}")
            ->line("Failed jobs: {$this->batch->failedJobs}")
            ->action('View Dashboard', url('/dashboard'));
    }

    public function toArray($notifiable)
    {
        return [
            'title' => ucfirst($this->importType) . ' Import Completed',
            'message' => "Processed {$this->batch->processedJobs()} records with {$this->batch->failedJobs} failures",
            'url' => '/dashboard',
            'type' => $this->importType
        ];
    }
}