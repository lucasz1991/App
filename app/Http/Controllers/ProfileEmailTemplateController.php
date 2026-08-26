<?php

namespace App\Http\Controllers;

use App\Support\EmailTemplateBuilder;
use Illuminate\Http\JsonResponse;
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

        // Die Dampflok ist die einzige oeffentliche Variante. Ein historischer
        // motiv-Queryparameter wird bewusst ignoriert.
        $file = (new EmailTemplateBuilder($request->user()))
            ->build($template);

        return response($file['content'], 200, [
            'Content-Type' => $file['mime'],
            'Content-Disposition' => 'attachment; filename="'.$file['filename'].'"',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Isolierte, personalisierte Kopierfassung fuer neues Outlook und Outlook
     * im Web. Das JSON wird nur gleichurspruenglich im Mitarbeiterdialog als
     * sandboxed srcdoc eingebettet und enthaelt selbst keine Skriptlogik.
     */
    public function signatureCopy(Request $request): JsonResponse
    {
        $html = (new EmailTemplateBuilder($request->user()))
            ->buildSignatureCopyHtml('light');

        return response()->json(['html' => $html], 200, [
            'Cache-Control' => 'private, no-store, max-age=0',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function preview(Request $request, string $template): Response
    {
        $definition = EmailTemplateBuilder::available()[$template] ?? null;

        abort_unless(
            ($definition['category'] ?? null) === 'mail'
                && ($definition['format'] ?? null) === 'html'
                && ($definition['previewable'] ?? false),
            404
        );

        $builder = new EmailTemplateBuilder($request->user());
        $file = $request->boolean('animate')
            ? $builder->buildPreview($template, bin2hex(random_bytes(12)))
            : $builder->buildStaticPreview($template);

        return response($file['content'], 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Content-Security-Policy' => "default-src 'none'; img-src data:; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'",
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
