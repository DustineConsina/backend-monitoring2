<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ComplaintResponseNotification extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $tenantName,
        public string $portalUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'New Response to Your Complaint – Contract Management System',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.complaint-response-notification',
            with: [
                'tenantName' => $this->tenantName,
                'portalUrl' => $this->portalUrl,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
