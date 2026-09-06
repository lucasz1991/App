<?php

declare(strict_types=1);

namespace App\Support\Mail;

use JsonSerializable;

/** Unveraenderlicher Ergebnisvertrag des Mail-Kompatibilitaetsaudits. */
final class EmailCompatibilityReport implements JsonSerializable
{
    /**
     * @param  array{block: int, warn: int, info: int}  $counts
     * @param  array{required: int, supported: int, unknown: int}  $coverage
     * @param  list<array{
     *     rule_id: string,
     *     diagnostic_code: string,
     *     enforcement: string,
     *     message: string,
     *     fix: string,
     *     client_profiles: list<string>
     * }>  $findings
     * @param  array{automated: int, manual: int, manual_rule_ids: list<string>}  $checks
     */
    public function __construct(
        public readonly string $catalogVersion,
        public readonly array $counts,
        public readonly array $coverage,
        public readonly int $htmlBytes,
        public readonly int $styleBytes,
        public readonly array $findings = [],
        public readonly array $checks = ['automated' => 0, 'manual' => 0, 'manual_rule_ids' => []],
    ) {}

    /** Fail-closed API-Bericht, wenn der Katalog selbst nicht verfuegbar ist. */
    public static function unavailable(
        EmailCompatibilityCatalogException $exception,
        int $htmlBytes = 0,
        int $styleBytes = 0,
    ): self {
        return new self(
            'unavailable',
            ['block' => 1, 'warn' => 0, 'info' => 0],
            ['required' => 0, 'supported' => 0, 'unknown' => 0],
            $htmlBytes,
            $styleBytes,
            [[
                'rule_id' => 'EMAIL-CATALOG-UNAVAILABLE',
                'diagnostic_code' => 'EMAIL_CATALOG_UNAVAILABLE',
                'enforcement' => 'BLOCK',
                'message' => $exception->getMessage().' ('.$exception->errorCode.')',
                'fix' => 'Den versionierten Kompatibilitaetskatalog pruefen und erneut laden.',
                'client_profiles' => ['all'],
            ]],
        );
    }

    public function status(): string
    {
        if ($this->counts['block'] > 0) {
            return 'block';
        }

        if ($this->counts['warn'] > 0) {
            return 'warn';
        }

        return 'pass';
    }

    public function blocksPublication(): bool
    {
        return $this->counts['block'] > 0;
    }

    /**
     * @return array{
     *     catalog_version: string,
     *     status: string,
     *     counts: array{block: int, warn: int, info: int},
     *     coverage: array{required: int, supported: int, unknown: int},
     *     html_bytes: int,
     *     style_bytes: int,
     *     findings: list<array{
     *         rule_id: string,
     *         diagnostic_code: string,
     *         enforcement: string,
     *         message: string,
     *         fix: string,
     *         client_profiles: list<string>
     *     }>,
     *     checks: array{automated: int, manual: int, manual_rule_ids: list<string>},
     *     rendering_verified: false
     * }
     */
    public function toArray(): array
    {
        return [
            'catalog_version' => $this->catalogVersion,
            'status' => $this->status(),
            'counts' => $this->counts,
            'coverage' => $this->coverage,
            'html_bytes' => $this->htmlBytes,
            'style_bytes' => $this->styleBytes,
            'findings' => $this->findings,
            // A static report is never evidence from a received mail client.
            'checks' => $this->checks,
            'rendering_verified' => false,
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
