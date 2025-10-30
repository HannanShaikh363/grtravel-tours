<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class VerificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public $verificationUrl; // Add a property to hold the verification URL
    public $resendUrl; // Add a property to hold the verification URL
    public $agentName;
    /**
     * Create a new message instance.
     *
     * @param string $verificationUrl
     */
    public function __construct(string $verificationUrl,$agentName)
    {
        $this->verificationUrl = $verificationUrl; // Assign the verification URL to the property
        $this->agentName = $agentName; // Assign the verification URL to the property
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Please Verify Your Email Address - GR TRAVEL & TOURS!',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'email.verification', // Change this to the correct view name
            with: [
                'verificationUrl' => $this->verificationUrl, // Pass the verification URL to the view
                'agentName' => $this->agentName, // Pass the verification URL to the view

            ],
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
