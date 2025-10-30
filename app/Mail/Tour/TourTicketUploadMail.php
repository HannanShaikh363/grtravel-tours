<?php

namespace App\Mail\Tour;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TourTicketUploadMail extends Mailable
{
    use Queueable, SerializesModels;

    public $tourBooking;
    public $agentName;
    public $bookingUniqueId;
    /**
     * Create a new message instance.
     */
    public function __construct($tourBooking, $agentName, $bookingUniqueId)
    {
        $this->tourBooking = $tourBooking;
        $this->agentName = $agentName;
        $this->bookingUniqueId = $bookingUniqueId;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Tour Tickets Uploaded',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'email.tour.tour_tourTicketUpload',
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
