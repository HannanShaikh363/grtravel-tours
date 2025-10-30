<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PendingPaymentReminder extends Notification
{
    use Queueable;

    protected $bookingData;
    protected $pdfFilePath;
    protected $passenger_full_name;
    /**
     * Create a new notification instance.
     */
    public function __construct($booking)
    {
        $this->booking = $booking;
        
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
    
        return (new MailMessage)
            ->subject('Booking Payment Reminder')
            ->view('email.payment_pending_reminder', [
                'booking' => $this->booking,
            ]);
    }

    
}
