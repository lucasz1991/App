<?php

namespace App\Mail;

use App\Models\StaffInvitation;
use App\Support\CompanyData;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class StaffInvitationMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public StaffInvitation $invitation,
    ) {}

    public function envelope(): Envelope
    {
        $company = CompanyData::all();

        return new Envelope(
            from: new Address(config('mail.from.address'), $company['name']),
            subject: __('app.invitation_to', ['app' => config('app.name')]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.staff-invitation',
            with: [
                'registrationUrl' => route('invitation.register', $this->invitation->token),
            ],
        );
    }
}
