<?php

declare(strict_types=1);

namespace App\Support\OutlookAddin;

use App\Models\User;
use RuntimeException;

/** Request-time projection after legacy cache and transport adapters. */
final class OutlookUniversalPayloadPresentation
{
    public function __construct(private readonly OutlookUniversalPresentation $renderer, private readonly ?OutlookUniversalDiagnostics $diagnostics = null) {}

    /** Legacy fields stay intact for cached clients and older/custom sources.
     * Only the separately admitted U2e client consumes these additional fields.
     */
    public function append(array $wire, array $canonical, array $profileValues, ?User $user = null, array $context = []): array
    {
        unset($wire['universalSignature'], $wire['universalCompose']);
        if (is_array($wire['template'] ?? null)) {
            unset($wire['template']['universalSignature'], $wire['template']['universalCompose']);
        }
        foreach ($wire['templates'] ?? [] as $index => $entry) {
            unset($wire['templates'][$index]['universalCompose'], $wire['templates'][$index]['universalSignature']);
        }
        // Source admission only: Microsoft identity has already been resolved.
        // The separate IT-only export, transit and fixture scope stays intact.
        if (! self::isSourceAllowed($user)) {
            $this->record($user, $this->event('outside_pilot', 'pilot', 'U2E_OUTSIDE_PILOT'), $context);

            return $wire;
        }
        $source = $this->desktopSource($canonical);
        if ($source === null) {
            $this->record($user, $this->event('desktop_unavailable', 'desktop', 'U2E_DESKTOP_UNAVAILABLE'), $context);

            return $wire;
        }
        $signatureSource = (string) ($source['signature']['html'] ?? '');
        $signatureContext = $context + ['sourceLength' => $this->utf16($signatureSource)];
        $signature = $this->project($signatureSource, $profileValues, $user, $signatureContext);
        if ($signature !== null) {
            $rendered = $this->render($signature, 'editor', $user, $signatureContext);
            $document = $rendered === null ? null : $this->document($rendered, 'signature', $user, $signatureContext);
            if ($document !== null) {
                $wire['universalSignature'] = $document;
                $this->record($user, $this->event('admitted', 'source', 'U2E_ADMITTED', $this->utf16($signatureSource), $this->utf16($document['html'])), $context);
            }
        }

        $sources = [];
        foreach ($source['templates'] ?? [] as $entry) {
            if (is_string($entry['id'] ?? null)) {
                $sources[$entry['id']] = $entry;
            }
        }
        foreach ($wire['templates'] ?? [] as $index => $entry) {
            $original = $sources[$entry['id'] ?? ''] ?? null;
            if ($original === null) {
                $this->record($user, $this->event('template_unavailable', 'template', 'U2E_TEMPLATE_UNAVAILABLE'), $context);

                continue;
            }
            $combinedSource = $this->combinedSource($original);
            $templateContext = $context + ['sourceLength' => $this->utf16($combinedSource)];
            $projected = $this->project($combinedSource, $profileValues, $user, $templateContext);
            if ($projected === null) {
                continue;
            }
            $rendered = $this->render($projected, 'editor', $user, $templateContext);
            if ($rendered === null) {
                continue;
            }
            foreach (['universalCompose' => 'combined', 'universalSignature' => 'signature'] as $field => $kind) {
                $document = $this->document($rendered, $kind, $user, $templateContext);
                if ($document !== null) {
                    $wire['templates'][$index][$field] = $document;
                    $this->record($user, $this->event('admitted', 'source', 'U2E_ADMITTED', $this->utf16($combinedSource), $this->utf16($document['html'])), $context);
                }
            }
        }

        return $wire;
    }

    public static function isPilot(?User $user): bool
    {
        return $user !== null && strtolower(trim((string) $user->email)) === 'it-technik@rail-time.de';
    }

    /** Source rollout is separate from the IT-only transit/export/fixture pilot. */
    public static function isSourceAllowed(?User $user): bool
    {
        $mode = config('outlook_addin.universal_source.mode');
        if ($mode === 'eligible_linked_employees') {
            return app(OutlookAddinEmployeeEligibility::class)->permits($user);
        }
        if ($mode !== 'explicit_accounts') {
            return false;
        }
        $accounts = config('outlook_addin.universal_source.allowed_accounts', []);
        if ($user === null || ! is_array($accounts)) {
            return false;
        }
        $address = strtolower(trim((string) $user->email));
        if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }
        foreach ($accounts as $allowed) {
            if (is_string($allowed) && $address === strtolower(trim($allowed))) {
                return true;
            }
        }

        return false;
    }

    /** Same admitted data source and opaque author bytes, for administrative review. */
    public function draft(array $canonical, array $profileValues, ?string $templateId = null, string $mode = 'send', ?User $user = null): ?array
    {
        return $this->review($canonical, $profileValues, $templateId, $mode, $user)['draft'];
    }

    /** Fresh review status is separate from retained historical failures. */
    public function review(array $canonical, array $profileValues, ?string $templateId = null, string $mode = 'send', ?User $user = null): array
    {
        if (! in_array($mode, ['editor', 'send'], true)) {
            throw new RuntimeException('U2E_MODE_INVALID');
        }
        $source = $this->desktopSource($canonical);
        $diagnostic = $this->event('desktop_unavailable', 'desktop', 'U2E_DESKTOP_UNAVAILABLE');
        $draft = null;
        if ($source !== null) {
            $selected = null;
            foreach ($source['templates'] ?? [] as $entry) {
                if ($templateId !== null && in_array($templateId, [$entry['id'] ?? null, $entry['key'] ?? null], true)) {
                    $selected = $entry;
                    break;
                }
                if ($templateId === null && (($entry['id'] ?? null) === ($source['automaticTemplateId'] ?? null)
                    || ($selected === null && ($entry['active'] ?? false) === true))) {
                    $selected = $entry;
                }
            }
            $diagnostic = $this->event('template_unavailable', 'template', 'U2E_TEMPLATE_UNAVAILABLE');
            if ($selected !== null) {
                $html = $this->combinedSource($selected);
                $inspection = OutlookUniversalSourceDescriptor::inspectFromCompiled($html, $profileValues,
                    hash('sha256', OutlookUniversalPresentation::REVISION."\0".$html));
                $diagnostic = $inspection['diagnostic'];
                if ($inspection['projection'] !== null) {
                    $draft = $this->render($inspection['projection'], $mode);
                    $diagnostic = $draft === null
                        ? $this->event('render_unavailable', 'render', 'U2E_RENDER_UNAVAILABLE', $this->utf16($html))
                        : $this->event('admitted', 'source', 'U2E_ADMITTED', $this->utf16($html), $draft['utf16Length']);
                    if ($draft !== null) {
                        $editor = $mode === 'editor' ? $draft : $this->render($inspection['projection'], 'editor');
                        if ($editor === null) {
                            $diagnostic = $this->event('render_unavailable', 'render', 'U2E_RENDER_UNAVAILABLE', $this->utf16($html));
                            $draft = null;
                        } elseif ($this->utf16($editor['bodyHtml']) > 27000) {
                            $diagnostic = $this->event('budget_exceeded', 'budget', 'U2E_BUDGET_EXCEEDED', $this->utf16($html), $this->utf16($editor['bodyHtml']));
                            $draft = null;
                        }
                    }
                }
            }
        }
        $this->record($user, $diagnostic);

        return ['draft' => $draft, 'admission' => $diagnostic];
    }

    private function combinedSource(array $entry): string
    {
        return is_string($entry['combinedComposeHtml'] ?? null) ? $entry['combinedComposeHtml'] : '';
    }

    private function desktopSource(array $canonical): ?array
    {
        $signature = (string) ($canonical['signature']['html'] ?? '');
        foreach ([OutlookV30ReferencePresentation::FLOW, OutlookCompactReferencePresentation::FLOW] as $flow) {
            if (str_contains($signature, 'data-rt-desktop-flow="'.$flow.'"')) {
                // The factory still performs full canonical admission. Avoid
                // feeding an already compiled source into its legacy compiler.
                return $canonical;
            }
        }

        try {
            return OutlookClientPresentation::payload($canonical);
        } catch (RuntimeException) {
            return null;
        }
    }

    private function project(string $html, array $profileValues, ?User $user = null, array $context = []): ?array
    {
        $inspection = OutlookUniversalSourceDescriptor::inspectFromCompiled($html, $profileValues,
            hash('sha256', OutlookUniversalPresentation::REVISION."\0".$html));
        if ($inspection['projection'] === null) {
            $this->record($user, $inspection['diagnostic'], $context);
        }

        return $inspection['projection'];
    }

    private function render(array $projected, string $mode = 'editor', ?User $user = null, array $context = []): ?array
    {
        try {
            return $this->renderer->render($projected['descriptor'], $projected['authorContent'],
                array_replace($projected['options'], ['mode' => $mode]));
        } catch (RuntimeException) {
            $this->record($user, $this->event('render_unavailable', 'render', 'U2E_RENDER_UNAVAILABLE'), $context);

            return null;
        }
    }

    private function document(array $rendered, string $kind, ?User $user = null, array $context = []): ?array
    {
        $html = $kind === 'signature' ? $rendered['signatureHtml'] : $rendered['bodyHtml'];
        if ($this->utf16($html) > 27000) {
            $this->record($user, $this->event('budget_exceeded', 'budget', 'U2E_BUDGET_EXCEEDED', null, $this->utf16($html)), $context);

            return null;
        }

        return [
            'schema' => 1, 'kind' => $kind, 'html' => $html, 'version' => $rendered['version'],
            'marker' => $rendered['marker'], 'header' => $rendered['header'],
            'sourceHash' => $rendered['sourceHash'], 'media' => [],
        ];
    }

    private function event(string $reason, string $phase, string $code, ?int $sourceLength = null, ?int $outputLength = null): array
    {
        return ['status' => $reason === 'admitted' ? 'admitted' : 'fallback', 'reason' => $reason,
            'phase' => $phase, 'code' => $code, 'sourceLength' => $sourceLength, 'outputLength' => $outputLength];
    }

    private function record(?User $user, array $event, array $context = []): void
    {
        if ($user === null) {
            return;
        }
        if ($event['sourceLength'] === null && isset($context['sourceLength'])) {
            $event['sourceLength'] = $context['sourceLength'];
        }
        $host = in_array($context['host'] ?? null, OutlookUniversalDiagnostics::HOSTS, true) ? $context['host'] : 'server';
        ($this->diagnostics ?? app(OutlookUniversalDiagnostics::class))->recordAdmission($user,
            $event + ['host' => $host], $context['mailbox'] ?? null);
    }

    private function utf16(string $html): int
    {
        return intdiv(strlen(mb_convert_encoding($html, 'UTF-16LE', 'UTF-8')), 2);
    }
}
