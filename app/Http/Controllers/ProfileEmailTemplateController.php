<?php

namespace App\Http\Controllers;

use App\Support\EmailTemplateBuilder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Personalisierte E-Mail-Vorlagen/Signaturen aus dem eigenen Profil
 * herunterladen. Jeder Benutzer erhaelt ausschliesslich Dateien mit
 * seinen eigenen Kontaktdaten.
 */
class ProfileEmailTemplateController extends Controller
{
    public function __invoke(Request $request, string $template): Response
    {
        abort_unless(array_key_exists($template, EmailTemplateBuilder::available()), 404);

        $file = (new EmailTemplateBuilder($request->user()))->build($template);

        return response($file['content'], 200, [
            'Content-Type' => $file['mime'],
            'Content-Disposition' => 'attachment; filename="' . $file['filename'] . '"',
        ]);
    }

    public function preview(Request $request, string $template): Response
    {
        abort_unless($template === 'vorlage-html', 404);

        $file = (new EmailTemplateBuilder($request->user()))->build($template);

        return response($file['content'], 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Content-Security-Policy' => "default-src 'none'; img-src data:; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'",
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
