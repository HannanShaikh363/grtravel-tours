<?php

namespace App\Mail\Genting;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class GentingVoucherToAdminMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     */
    public $bookingData;
    public $pdfFilePath;
    public $passenger_full_name;

    public $seating_capacity;
    public $id;
    public $pick_time;
    public $tour_date;
    public $pickup_address;
    public $dropoff_address;
    public $flight_number;
    public $arrival_flight_number;
    public $flight_departure_time;
    public $flight_arrival_time;
    public $vehicle;
    public $agentCompanyName;
    public $agentCompanyNumber;
    public $agentCompanyAddress;
    public $agentCompanyCity;
    public $agentCompanyZip;
    public $countryName;
    public $booking_date;
    public $booking_cost;
    public $return_flight_number;
    public $return_flight_time;
    public $haveMeetingPointDesc;
    public $meeting_point_name;
    public $meeting_point_images;
    public $hirerName;
    public $base_price;
    public $return_pickup_address;
    public $return_pickup_date;
    public $return_pickup_time;
    public $booking_status;
    public $return_arrival_flight_number;
    public $agentLogo;
    public $hotel_name;
    public $package;
    public $room_type;
    public $number_of_rooms;
    public $extra_bed_for_child;
    public $reservation_id;
    public $confirmation_id;
    public $deadlineDate;
    public $netRate;
    public $netCurrency;
    public $additional_adults;
    public $additional_children;
    public $additional_adult_price;
    public $additional_child_price;
    public $voucher_code;
    public $voucher;
    public $discount;
    public $discountedPrice;
    public $currency;

    public function __construct($bookingData, $pdfFilePath, $passenger_full_name)
    {
        $this->bookingData = $bookingData;
        $this->pdfFilePath = $pdfFilePath;
        $this->passenger_full_name = $passenger_full_name;
    }
    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $subject = $this->bookingData['is_updated'] ? 'GENTING HOTEL BOOKING VOUCHER UPDATED' : 'GENTING HOTEL BOOKING VOUCHER';
        return new Envelope(
            subject: $subject.'- ID #'.$this->bookingData['booking_id'],
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {

        $bookingData = [
            'passenger_full_name' => $this->passenger_full_name,
            'id' => $this->id,
            'seating_capacity' => $this->seating_capacity,
            'pick_time' => $this->pick_time,
            'tour_date`' => $this->tour_date,
            'hotel_name' => $this->hotel_name,
            'pickup_address' => $this->pickup_address,
            'agentCompanyName' => $this->agentCompanyName,
            'agentCompanyNumber' => $this->agentCompanyNumber,
            'agentCompanyAddress' => $this->agentCompanyAddress,
            'agentCompanyCity' => $this->agentCompanyCity,
            'agentCompanyZip' => $this->agentCompanyZip,
            'countryName' => $this->countryName,
            'booking_date' => $this->booking_date,
            'booking_cost' => $this->booking_cost,
            'hirerName' => $this->hirerName,
            'base_price' => $this->base_price,
            'booking_status' => $this->booking_status,
            'package' => $this->package,
            'room_type' => $this->room_type,
            'number_of_rooms' => $this->number_of_rooms,
            'extra_bed_for_child' => $this->extra_bed_for_child,
            'reservation_id' => $this->reservation_id,
            'confirmation_id' => $this->confirmation_id,
            'deadlineDate' => $this->deadlineDate,
            'netRate' => $this->netRate,
            'netCurrency' => $this->netCurrency,
            'additional_adults' => $this->additional_adults,
            'additional_children' => $this->additional_children,
            'additional_adult_price' => $this->additional_adult_price,
            'additional_child_price' => $this->additional_child_price,
            'voucher_code' => $this->voucher_code,
            'voucher' => $this->voucher,
            'discount' => $this->discount,
            'discountedPrice' => $this->discountedPrice,
            'currency' => $this->currency,
        ];

        return new Content(
            view: 'email.genting.genting_voucher_admin_email',
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
                ->as('GentingBookingVoucher.pdf') // Name the attachment as 'booking.pdf'
                ->withMime('application/pdf') // Set the MIME type
        ];
    }
}
