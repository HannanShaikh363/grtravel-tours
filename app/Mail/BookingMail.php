<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BookingMail  extends Mailable
{
    use Queueable, SerializesModels;
    /**
     * Create a new message instance.
     */
    public $bookingData;
    public $pdfFilePath;
    public $passenger_full_name;
    public $passenger_contact_number;
    public $vehicle_seating_capacity;
    public $id;
    public $pick_time;
    public $pick_date;
    public $pickup_address;
    public $dropoff_address;
    public $flight_number;
    public $flight_departure_time;
    public $flight_arrival_time;
    public $return_flight_number;
    public $return_flight_departure_time;
    public $return_flight_time;
    public $vehicle;
    public $hirerName;
    public $haveMeetingPointDesc;
    public $meeting_point_name;
    public $meeting_point_images;
    public $haveMeetingPointDescEmail;
    public $meeting_point_name_email;
    public $meeting_point_images_email;
    public $return_pickup_address;
    public $return_pickup_date;
    public $return_pickup_time;
    public $return_meeting_point_name;
    public $return_meeting_point_name_email;
    public $return_meeting_point_images_email;
    public $return_arrival_flight_number;
    public $return_flight_arrival_time;
    public $arrival_flight_number;
    public $booking_date;
    public $arrival_flight_date;
    public $depart_flight_date;
    public $return_depart_flight_date;
    public $return_arrival_flight_date;


    public function __construct($bookingData, $pdfFilePath, $passenger_full_name)
    {
        $this->bookingData = $bookingData;
        $this->pdfFilePath = $pdfFilePath;
        $this->passenger_full_name = $passenger_full_name;
    }

    public function getBookingDetail($field)
    {
        return $this->bookingData[$field] ?? null; // Safe access to any booking detail
    }
    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
         $subject = $this->bookingData['is_updated'] ? 'TRANSFER BOOKING VOUCHER UPDATED' : 'TRANSFER BOOKING VOUCHER';
        return new Envelope(
            subject: $subject,
        );
    }


    /**
     * Get the message content definition.
     */
    public function content(): Content
    {

        $bookingData = [
            'passenger_full_name' => $this->passenger_full_name,
            'passenger_contact_number' => $this->passenger_contact_number,
            'id' => $this->id,
            'vehicle_seating_capacity' => $this->vehicle_seating_capacity,
            'pick_time' => $this->pick_time,
            'pick_date' => $this->pick_date,
            'pickup_address' => $this->pickup_address,
            'dropoff_address' => $this->dropoff_address,
            'flight_number' => $this->flight_number,
            'arrival_flight_number' => $this->arrival_flight_number,
            'flight_departure_time' => $this->flight_departure_time,
            'flight_arrival_time' => $this->flight_arrival_time,
            'arrival_flight_date' => $this->arrival_flight_date, 
            'return_arrival_flight_date' => $this->return_arrival_flight_date, 
            'depart_flight_date' => $this->depart_flight_date, 
            'return_depart_flight_date' => $this->return_depart_flight_date, 
            'return_flight_number' => $this->return_flight_number,
            'return_arrival_flight_number' => $this->return_arrival_flight_number,
            'return_flight_arrival_time' => $this->return_flight_arrival_time,
            'return_flight_departure_time' => $this->return_flight_departure_time,
            'return_pickup_address' => $this->return_pickup_address,
            'vehicle' => $this->vehicle,
            'meeting_point_desc' => $this->haveMeetingPointDesc,
            'meeting_point_name' => $this->meeting_point_name,
            'meeting_point_images' => $this->meeting_point_images,
            'meeting_point_desc_email' => $this->haveMeetingPointDescEmail,
            'meeting_point_name_email' => $this->meeting_point_name_email,
            'meeting_point_images_email' => $this->meeting_point_images_email,
            'return_meeting_point_name_email' => $this->return_meeting_point_name_email,
            'return_meeting_point_images_email' => $this->return_meeting_point_images_email,
            'hirerName' => $this->hirerName,
            'return_pickup_date' => $this->return_pickup_date,
            'return_pickup_time' => $this->return_pickup_time,
            'booking_date' => $this->booking_date,
        ];

        return new Content(
            view: 'email.booking_confirm',
            with: array_merge($bookingData, ['bookingData' => $bookingData]),
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [
            Attachment::fromPath($this->pdfFilePath) // Use the passed PDF file path
                ->as('bookingVoucher.pdf') // Name the attachment as 'booking.pdf'
                ->withMime('application/pdf') // Set the MIME type
        ];
    }
}
