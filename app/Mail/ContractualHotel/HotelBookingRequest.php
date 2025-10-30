<?php

namespace App\Mail\ContractualHotel;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class HotelBookingRequest extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     */
    public $adminName;
    public $hotelData;
    public $bookingData;
    public $hotelRate;

    public function __construct($hotelData, $bookingData, $adminName, $hotelRate)
    {
        $this->adminName = $adminName;
        $this->hotelData = $hotelData;
        $this->bookingData = $bookingData;
        $this->hotelRate = $hotelRate;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Contractual Booking Approval Pending - ID #' . $this->bookingData['booking_id'],
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {

        return new Content(
            view: 'email.contractualhotel.hotel_booking_request',
            with: array_merge(
                $this->hotelData->toArray(),
                $this->bookingData,
                [
                    'hotelData' => $this->hotelData,
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
