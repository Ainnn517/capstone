<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

// Tells a resident that the barangay changed the status of their report.
class ReportStatusMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $fullName,
        public readonly string $tracking,
        public readonly string $status,
        public readonly string $headline,
        public readonly string $adminMessage,
        public readonly ?string $respondBy,
        public readonly string $reportUrl,
        public readonly string $unsubscribeUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Report {$this->tracking}: {$this->status}");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.report-status');
    }

    public function headers(): Headers
    {
        return new Headers(text: [
            'List-Unsubscribe' => "<{$this->unsubscribeUrl}>",
        ]);
    }
}
