<?php

namespace App\Mail\Genting;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class GentingBookingRequest extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     */
    public $adminName;
    public $gentingData;
    public $bookingData;
    public $gentingRate;

    public function __construct($gentingData, $bookingData, $adminName, $gentingRate)
    {
        $this->adminName = $adminName;
        $this->gentingData = $gentingData;
        $this->bookingData = $bookingData;
        $this->gentingRate = $gentingRate;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Genting Booking Approval Pending - ID #' . $this->bookingData['booking_id'],
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {

        return new Content(
            view: 'email.genting.genting_booking_request',
            with: array_merge(
                $this->gentingData->toArray(),
                $this->bookingData,
                [
                    'gentingData' => $this->gentingData,
                    'bookingData' => $this->bookingData,
                ]
            ),
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
