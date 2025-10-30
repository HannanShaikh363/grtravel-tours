<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

class BookingConfirmed extends Notification
{
    use Queueable;

    protected $username;
    protected $bookingId;
    protected $address;

    public function __construct($username, $bookingId, $address)
    {
        $this->username = $username;
        $this->bookingId = $bookingId;
        $this->address = $address;
    }

    public function via($notifiable)
    {
        return ['mail'];
    }

    public function toMail($notifiable)
    {
        return (new MailMessage)
            ->subject('Booking Confirmed')
            ->view('emails.booking_confirm', [
                'username' => $this->username,
                'booking_id' => $this->bookingId,
                'address' => $this->address,
            ]);
    }
}
