<?php

namespace App\Mail\Genting;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class GentingBookingApproved extends Mailable
{
    use Queueable, SerializesModels;

    public $gentingBooking;
    public $agentName;
    public $bookingUniqueId;


    public function __construct($gentingBooking, $agentName, $bookingUniqueId)
    {
        $this->$gentingBooking = $gentingBooking;
        $this->agentName = $agentName;
        $this->bookingUniqueId = $bookingUniqueId;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Genting Hotel Booking Approved - ID #'.$this->bookingUniqueId,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'email.genting.gentingbooking_approved',
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
