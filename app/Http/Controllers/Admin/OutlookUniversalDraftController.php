<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\EmailTemplateBuilder;
use App\Support\OutlookAddin\OutlookAddinUserSnapshotStore;
use App\Support\OutlookAddin\OutlookClientPresentation;
use App\Support\OutlookAddin\OutlookStaticLinkedTestProfiles;
use App\Support\OutlookAddin\OutlookUniversalPayloadPresentation;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Read-only review/download of the same admitted U2e projection as compose. */
final class OutlookUniversalDraftController extends Controller
{
    public function __invoke(Request $request, OutlookAddinUserSnapshotStore $snapshots, OutlookUniversalPayloadPresentation $projection): Response
    {
        $actor = $request->user();
        abort_unless($actor instanceof User && $actor->isAdmin(), 403);
        $input = $request->validate([
            'mode' => ['sometimes', 'string', 'in:editor,send'],
            'template' => ['nullable', 'uuid'],
            'download' => ['sometimes', 'boolean'],
        ]);
        $mode = $input['mode'] ?? 'send';
        $canonical = OutlookStaticLinkedTestProfiles::payload(
            OutlookClientPresentation::payload($snapshots->currentForUser($actor)), $actor,
        );
        $draft = $projection->draft($canonical, (new EmailTemplateBuilder($actor))->profileValues(), $input['template'] ?? null, $mode);
        abort_if($draft === null, 422, 'Die ausgewaehlte Vorlage kann nicht eindeutig als U2e-Entwurf dargestellt werden.');

        $html = $draft['html'];
        if ($mode === 'editor' && ! $request->boolean('download')) {
            // A preview wrapper only. Office receives the original BODY
            // fragment, without HEAD/styles/metas, from the bootstrap.
            $html = '<!doctype html><html lang="de"><head><meta charset="utf-8"><title>RailTime U2e – Editorfassung</title></head>'
                .'<body style="margin:0;padding:0">'.$html.'</body></html>';
        }
        $headers = [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
            'X-RailTime-Template' => $draft['header'],
            'X-RailTime-U2e-Mode' => $mode,
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'; frame-ancestors 'self';",
        ];
        if ($request->boolean('download')) {
            $headers['Content-Disposition'] = 'attachment; filename="RailTime-U2e-'.$mode.'.html"';
        }

        return response($html, 200, $headers);
    }
}
