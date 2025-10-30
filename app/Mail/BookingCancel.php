<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BookingCancel extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     */
    public $fleetBooking;
    public $agentName;
    public $passenger_full_name;
    public $id;
    public $pick_date;
    public $booking_date;
    public $pick_time;
    public $transfer_name;
    public $fromLocation;
    public $toLocation;
    public $location;
    public $bookingDate;
    public $bookingType;
    public $amountRefunded;
    
    public function __construct($fleetBooking, $agentName, $fromLocation, $toLocation, $bookingDate, $location, $bookingType, $amountRefunded)
    {
        $this->fleetBooking = $fleetBooking;
        $this->agentName = $agentName;
        $this->fromLocation = $fromLocation;
        $this->toLocation = $toLocation;
        $this->bookingDate = $bookingDate;
        $this->location = $location;
        $this->bookingType = $bookingType;
        $this->amountRefunded = $amountRefunded;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Booking Cancelled - #' . $this->fleetBooking->booking->booking_unique_id,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        $fleetBooking = [
            'passenger_full_name' => $this->passenger_full_name,
            'id' => $this->id,
            'pick_time' => $this->pick_time,
            'pick_date' => $this->pick_date,
            'transfer_name' => $this->transfer_name,
        ];
        return new Content(
            view: 'email.booking_cancel',
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
