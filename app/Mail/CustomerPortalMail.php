<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

class CustomerPortalMail extends Mailable
{
    public function __construct(public string $kind, public array $content) {}

    public function build(): self
    {
        $titles = ['invitation' => 'Einladung zum Kundenportal', 'reset' => 'Passwort zurücksetzen', 'requests' => 'Anfrage eingegangen', 'decisions' => 'Rückmeldung zu Ihrer Anfrage', 'orders' => 'Auftrag aktualisiert', 'proofs' => 'Leistungsnachweis verfügbar', 'documents' => 'Dokument verfügbar', 'messages' => 'Neue Nachricht'];

        return $this->subject('RailTime – '.($titles[$this->kind] ?? 'Kundenportal'))->view('customer-portal.mail', ['heading' => $titles[$this->kind] ?? 'Kundenportal', 'messageContent' => $this->content]);
    }
}
