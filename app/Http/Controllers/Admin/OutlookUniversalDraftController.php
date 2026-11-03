<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\EmailTemplateBuilder;
use App\Support\OutlookAddin\OutlookAddinUserSnapshotStore;
use App\Support\OutlookAddin\OutlookClientPresentation;
use App\Support\OutlookAddin\OutlookStaticLinkedTestProfiles;
use App\Support\OutlookAddin\OutlookUniversalDiagnosticFixtureStore;
use App\Support\OutlookAddin\OutlookUniversalDiagnostics;
use App\Support\OutlookAddin\OutlookUniversalPayloadPresentation;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

/** Read-only review/download of the same admitted U2e projection as compose. */
final class OutlookUniversalDraftController extends Controller
{
    public function __invoke(Request $request, OutlookAddinUserSnapshotStore $snapshots, OutlookUniversalPayloadPresentation $projection, OutlookUniversalDiagnostics $diagnostics, OutlookUniversalDiagnosticFixtureStore $fixtures): Response
    {
        $actor = $request->user();
        abort_unless($actor instanceof User && $actor->isAdmin(), 403);
        $input = $request->validate([
            'mode' => ['sometimes', 'string', 'in:editor,send'],
            'template' => ['nullable', 'uuid'],
            'download' => ['sometimes', 'boolean'],
            'status' => ['sometimes', 'boolean'],
            'user' => ['sometimes', 'integer', 'min:1', 'exists:users,id'],
        ]);
        $mode = $input['mode'] ?? 'send';
        $subject = isset($input['user']) ? User::query()->findOrFail($input['user']) : $actor;
        try {
            $canonical = OutlookStaticLinkedTestProfiles::payload(
                OutlookClientPresentation::payload($snapshots->currentForUser($subject)), $subject,
            );
            $review = $projection->review($canonical, (new EmailTemplateBuilder($subject))->profileValues(), $input['template'] ?? null, $mode, $subject);
        } catch (Throwable) {
            $review = ['draft' => null, 'admission' => ['status' => 'fallback', 'reason' => 'desktop_unavailable',
                'phase' => 'desktop', 'code' => 'U2E_DESKTOP_UNAVAILABLE', 'sourceLength' => null, 'outputLength' => null]];
            $diagnostics->recordAdmission($subject, $review['admission']);
        }
        $draft = $review['draft'];
        $liveAdmission = OutlookUniversalPayloadPresentation::isPilot($subject) ? $review['admission']
            : ['status' => 'fallback', 'reason' => 'outside_pilot', 'phase' => 'pilot', 'code' => 'U2E_OUTSIDE_PILOT',
                'sourceLength' => null, 'outputLength' => null];
        if (! OutlookUniversalPayloadPresentation::isPilot($subject)) {
            $diagnostics->recordAdmission($subject, $liveAdmission);
        }
        if ($request->boolean('status')) {
            return response()->view('mail-documents.u2e-status', [
                'subject' => $subject, 'users' => User::query()->whereIn('role', ['admin', 'staff'])->orderBy('name')->get(['id', 'name', 'email']),
                'admission' => $liveAdmission, 'reviewAdmission' => $review['admission'], 'history' => $diagnostics->statusFor($subject),
                'mode' => $mode, 'templateId' => $input['template'] ?? null, 'previewAvailable' => $draft !== null,
                'fixtures' => $fixtures->listingFor($subject),
            ], 200, ['Cache-Control' => 'private, no-store, max-age=0', 'Referrer-Policy' => 'no-referrer', 'X-Content-Type-Options' => 'nosniff']);
        }
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
