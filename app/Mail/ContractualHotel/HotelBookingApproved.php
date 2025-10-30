<?php

namespace App\Mail\ContractualHotel;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class HotelBookingApproved extends Mailable
{
    use Queueable, SerializesModels;

    public $contractualBooking;
    public $agentName;
    public $bookingUniqueId;


    public function __construct($contractualBooking, $agentName, $bookingUniqueId)
    {
        $this->$contractualBooking = $contractualBooking;
        $this->agentName = $agentName;
        $this->bookingUniqueId = $bookingUniqueId;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Contractual Hotel Booking Approved - ID #'.$this->bookingUniqueId,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'email.contractualhotel.contractualbooking_approved',
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
