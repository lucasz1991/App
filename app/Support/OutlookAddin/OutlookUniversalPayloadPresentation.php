<?php

declare(strict_types=1);

namespace App\Support\OutlookAddin;

use App\Models\User;
use RuntimeException;

/** Request-time projection after legacy cache and transport adapters. */
final class OutlookUniversalPayloadPresentation
{
    public function __construct(private readonly OutlookUniversalPresentation $renderer) {}

    /** Legacy fields stay intact for cached clients and older/custom sources.
     * Only the separately admitted U2e client consumes these additional fields.
     */
    public function append(array $wire, array $canonical, array $profileValues, ?User $user = null): array
    {
        unset($wire['universalSignature'], $wire['universalCompose']);
        if (is_array($wire['template'] ?? null)) {
            unset($wire['template']['universalSignature'], $wire['template']['universalCompose']);
        }
        foreach ($wire['templates'] ?? [] as $index => $entry) {
            unset($wire['templates'][$index]['universalCompose'], $wire['templates'][$index]['universalSignature']);
        }
        // Only the server-resolved account can enter the unaccepted U2e pilot.
        if (! self::isPilot($user)) {
            return $wire;
        }
        $source = $this->desktopSource($canonical);
        if ($source === null) {
            return $wire;
        }
        $signature = $this->project((string) ($source['signature']['html'] ?? ''), $profileValues);
        if ($signature !== null) {
            $rendered = $this->render($signature);
            $document = $rendered === null ? null : $this->document($rendered, 'signature');
            if ($document !== null) {
                $wire['universalSignature'] = $document;
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
                continue;
            }
            $projected = $this->project($this->combinedSource($original), $profileValues);
            if ($projected === null) {
                continue;
            }
            $rendered = $this->render($projected);
            if ($rendered === null) {
                continue;
            }
            foreach (['universalCompose' => 'combined', 'universalSignature' => 'signature'] as $field => $kind) {
                $document = $this->document($rendered, $kind);
                if ($document !== null) {
                    $wire['templates'][$index][$field] = $document;
                }
            }
        }

        return $wire;
    }

    public static function isPilot(?User $user): bool
    {
        return $user !== null && strtolower(trim((string) $user->email)) === 'it-technik@rail-time.de';
    }

    /** Same admitted source as compose, with a complete document for review. */
    public function draft(array $canonical, array $profileValues, ?string $templateId = null, string $mode = 'send'): ?array
    {
        if (! in_array($mode, ['editor', 'send'], true)) {
            throw new RuntimeException('Die U2e-Ausgabeart ist ungueltig.');
        }
        $source = $this->desktopSource($canonical);
        if ($source === null) {
            return null;
        }
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
        if ($selected === null) {
            return null;
        }
        $projected = $this->project($this->combinedSource($selected), $profileValues);
        if ($projected === null) {
            return null;
        }

        return $this->render($projected, $mode);
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

    private function project(string $html, array $profileValues): ?array
    {
        if ($html === '') {
            return null;
        }

        return OutlookUniversalSourceDescriptor::projectFromCompiled($html, $profileValues,
            hash('sha256', OutlookUniversalPresentation::REVISION."\0".$html));
    }

    private function render(array $projected, string $mode = 'editor'): ?array
    {
        try {
            return $this->renderer->render($projected['descriptor'], $projected['authorContent'],
                array_replace($projected['options'], ['mode' => $mode]));
        } catch (RuntimeException) {
            // A source/asset not admitted by the new finite renderer retains
            // the existing, already admitted output. It is never approximated.
            return null;
        }
    }

    private function document(array $rendered, string $kind): ?array
    {
        $html = $kind === 'signature' ? $rendered['signatureHtml'] : $rendered['bodyHtml'];
        if (intdiv(strlen(mb_convert_encoding($html, 'UTF-16LE', 'UTF-8')), 2) > 27000) {
            return null;
        }

        return [
            'schema' => 1, 'kind' => $kind, 'html' => $html, 'version' => $rendered['version'],
            'marker' => $rendered['marker'], 'header' => $rendered['header'],
            'sourceHash' => $rendered['sourceHash'], 'media' => [],
        ];
    }
}
