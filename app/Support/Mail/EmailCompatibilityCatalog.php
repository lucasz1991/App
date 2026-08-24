<?php

declare(strict_types=1);

namespace App\Support\Mail;

use DateTimeImmutable;
use JsonException;
use JsonSerializable;
use stdClass;

/**
 * Strikter Loader fuer den versionierten E-Mail-Kompatibilitaetskatalog.
 *
 * Die CSV ist Datenquelle, kein Programm: Handler- und Autofix-Namen bleiben
 * deklarative Bezeichner. Erst der Auditor ordnet bekannte Handler fest zu.
 */
final class EmailCompatibilityCatalog implements JsonSerializable
{
    /** @var list<string> */
    public const HEADER = [
        'schema_version',
        'catalog_version',
        'row_id',
        'rule_id',
        'rule_revision',
        'rule_status',
        'category',
        'subcategory',
        'title_de',
        'rule_text_de',
        'normative_level',
        'compatibility_level',
        'severity',
        'enforcement',
        'rationale_de',
        'document_kinds_json',
        'applies_to',
        'match_language',
        'match_target',
        'condition_json',
        'allowed_patterns_json',
        'forbidden_patterns_json',
        'validator_handler',
        'validator_config_json',
        'diagnostic_code',
        'diagnostic_message_de',
        'fix_guidance_de',
        'autofix_mode',
        'autofix_action',
        'autofix_config_json',
        'project_mapping_json',
        'valid_example',
        'invalid_example',
        'exception_condition_de',
        'exception_handling_de',
        'client_profile_id',
        'client_family',
        'client_platform',
        'rendering_engine',
        'client_version_selector',
        'support_status',
        'support_note_de',
        'test_method',
        'last_tested_at',
        'source_type',
        'source_title',
        'source_url',
        'source_locator',
        'source_published_at',
        'source_accessed_at',
        'evidence_summary_de',
        'confidence',
        'confidence_reason_de',
        'last_verified_in_project',
        'review_due_at',
        'secondary_sources_json',
        'tags_json',
        'notes_de',
    ];

    /** @var list<string> */
    public const REQUIRED_CLIENT_PROFILES = [
        'outlook-classic-windows',
        'outlook-new-windows',
        'outlook-web',
        'outlook-macos',
        'outlook-ios',
        'outlook-android',
        'gmail-web',
        'gmail-ios-google',
        'gmail-android-google',
        'gmail-ios-non-google',
        'gmail-android-non-google',
        'apple-mail-macos',
        'apple-mail-ios',
        'yahoo-web',
        'yahoo-ios',
        'yahoo-android',
        'aol-web',
        'aol-ios',
        'aol-android',
        'samsung-email-android',
        'thunderbird-desktop',
        'gmx-web',
        'webde-web',
        'ionos-webmail',
        't-online-web',
    ];

    /** @var list<string> */
    public const DEGRADATION_PROFILES = [
        'scenario-images-off',
        'scenario-head-css-off',
        'scenario-css-off',
        'scenario-dark-mode',
        'scenario-narrow-375',
        'scenario-plain-text',
    ];

    /** @var array<string, list<string>> */
    private const ENUMS = [
        'rule_status' => ['DRAFT', 'ACTIVE', 'DEPRECATED'],
        'normative_level' => ['MUST', 'MUST_NOT', 'SHOULD', 'SHOULD_NOT', 'MAY'],
        'compatibility_level' => ['SAFE', 'PROGRESSIVE', 'SEPARATE_PIPELINE'],
        'severity' => ['CRITICAL', 'MAJOR', 'MINOR', 'INFO'],
        'enforcement' => ['BLOCK', 'WARN', 'INFO', 'OFF'],
        'support_status' => ['SUPPORTED', 'PARTIAL', 'UNSUPPORTED', 'UNKNOWN', 'NOT_APPLICABLE'],
        'confidence' => ['HIGH', 'MEDIUM', 'LOW'],
        'autofix_mode' => ['NONE', 'SAFE', 'REVIEW_REQUIRED'],
        'source_type' => [
            'standard',
            'official_vendor',
            'original_client_test',
            'accessibility_standard',
            'project_policy',
            'project_runtime',
        ],
        'test_method' => [
            'standard_review',
            'vendor_documentation',
            'actual_client_test',
            'static_analysis',
            'output_inspection',
            'manual_degradation',
        ],
        'match_language' => ['mime', 'html', 'css', 'text', 'url', 'asset', 'manual'],
        'applies_to' => [
            'asset',
            'mime',
            'document',
            'html',
            'css',
            'image',
            'link',
            'content',
            'rendered_html',
            'plain_text',
            'manual_qa',
        ],
        'autofix_action' => [
            'NONE',
            'add_plain_text_part',
            'add_role_presentation',
            'add_lang_attribute',
            'add_alt_attribute',
            'normalize_image_attributes',
            'normalize_absolute_url',
            'remove_forbidden_features',
            'add_background_fallback',
            'add_safe_fallback',
        ],
    ];

    /** @var list<string> */
    private const DOCUMENT_KINDS = [
        'template',
        'signature',
        'system_mail',
        'html_download',
        'eml',
        'outlook_package',
        'marketing',
    ];

    /** @var list<string> */
    private const JSON_LIST_COLUMNS = [
        'document_kinds_json',
        'allowed_patterns_json',
        'forbidden_patterns_json',
        'secondary_sources_json',
        'tags_json',
    ];

    /** @var list<string> */
    private const JSON_OBJECT_COLUMNS = [
        'condition_json',
        'validator_config_json',
        'autofix_config_json',
    ];

    /** @var list<string> */
    private const REQUIRED_COLUMNS = [
        'schema_version',
        'catalog_version',
        'row_id',
        'rule_id',
        'rule_revision',
        'rule_status',
        'category',
        'title_de',
        'rule_text_de',
        'normative_level',
        'compatibility_level',
        'severity',
        'enforcement',
        'rationale_de',
        'document_kinds_json',
        'applies_to',
        'match_language',
        'condition_json',
        'allowed_patterns_json',
        'forbidden_patterns_json',
        'validator_config_json',
        'diagnostic_code',
        'diagnostic_message_de',
        'fix_guidance_de',
        'autofix_mode',
        'autofix_action',
        'autofix_config_json',
        'project_mapping_json',
        'client_profile_id',
        'support_status',
        'test_method',
        'source_type',
        'source_title',
        'source_url',
        'source_accessed_at',
        'evidence_summary_de',
        'confidence',
        'confidence_reason_de',
        'secondary_sources_json',
        'tags_json',
    ];

    /** @var list<string> */
    private const DATE_COLUMNS = [
        'last_tested_at',
        'source_published_at',
        'source_accessed_at',
        'last_verified_in_project',
        'review_due_at',
    ];

    /**
     * Definitionsteil, der fuer alle Clientzeilen derselben Regelrevision
     * bytegleich bleiben muss.
     *
     * @var list<string>
     */
    private const RULE_DEFINITION_COLUMNS = [
        'rule_id',
        'rule_revision',
        'rule_status',
        'category',
        'subcategory',
        'title_de',
        'rule_text_de',
        'normative_level',
        'compatibility_level',
        'severity',
        'enforcement',
        'rationale_de',
        'document_kinds_json',
        'applies_to',
        'match_language',
        'match_target',
        'condition_json',
        'allowed_patterns_json',
        'forbidden_patterns_json',
        'validator_handler',
        'validator_config_json',
        'diagnostic_code',
        'diagnostic_message_de',
        'fix_guidance_de',
        'autofix_mode',
        'autofix_action',
        'autofix_config_json',
        'project_mapping_json',
        'valid_example',
        'invalid_example',
    ];

    /**
     * @var array<string, array{
     *     fingerprint: string,
     *     schema_version: string,
     *     catalog_version: string,
     *     rows: list<array<string, string>>
     * }>
     */
    private static array $cache = [];

    public readonly string $path;

    public function __construct(?string $path = null)
    {
        $this->path = $path ?? self::defaultPath();

        if (trim($this->path) === '') {
            throw new EmailCompatibilityCatalogException(
                'catalog_path_empty',
                $this->path,
                'Der Pfad zum E-Mail-Kompatibilitaetskatalog ist leer.',
            );
        }
    }

    public static function defaultPath(): string
    {
        if (function_exists('resource_path')) {
            return resource_path('data/email-html-compatibility-rules.csv');
        }

        return dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR
            .'data'.DIRECTORY_SEPARATOR.'email-html-compatibility-rules.csv';
    }

    public static function clearCache(?string $path = null): void
    {
        if ($path === null) {
            self::$cache = [];

            return;
        }

        unset(self::$cache[self::cacheKey($path)]);
    }

    public function schemaVersion(): string
    {
        return $this->payload()['schema_version'];
    }

    public function catalogVersion(): string
    {
        return $this->payload()['catalog_version'];
    }

    /** @return list<array<string, string>> */
    public function rows(): array
    {
        return $this->payload()['rows'];
    }

    /**
     * Liefert jede aktive Regelrevision genau einmal samt Clientbelegen.
     *
     * @return list<array{
     *     definition: array<string, string>,
     *     profiles: list<array<string, string>>
     * }>
     */
    public function activeRuleGroups(): array
    {
        $groups = [];

        foreach ($this->rows() as $row) {
            if ($row['rule_status'] !== 'ACTIVE') {
                continue;
            }

            $key = $row['rule_id'].'@'.$row['rule_revision'];
            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'definition' => $row,
                    'profiles' => [],
                ];
            }
            $groups[$key]['profiles'][] = $row;
        }

        return array_values($groups);
    }

    /**
     * Sicherer UI-Ausschnitt ohne Validator-Konfiguration, Match-Muster,
     * Quellbeispiele, Autofix-Aktionen oder Evidenz-URLs.
     *
     * @return array{
     *     schema_version: string,
     *     catalog_version: string,
     *     rules: list<array<string, mixed>>
     * }
     */
    public function manifestForJs(): array
    {
        $rules = [];

        foreach ($this->activeRuleGroups() as $group) {
            $rule = $group['definition'];
            $profiles = array_map(
                static fn (array $profile): array => [
                    'id' => $profile['client_profile_id'],
                    'support_status' => $profile['support_status'],
                    'support_note_de' => $profile['support_note_de'],
                ],
                $group['profiles'],
            );

            $rules[] = [
                'rule_id' => $rule['rule_id'],
                'rule_revision' => $rule['rule_revision'],
                'category' => $rule['category'],
                'subcategory' => $rule['subcategory'],
                'title_de' => $rule['title_de'],
                'rule_text_de' => $rule['rule_text_de'],
                'normative_level' => $rule['normative_level'],
                'compatibility_level' => $rule['compatibility_level'],
                'severity' => $rule['severity'],
                'enforcement' => $rule['enforcement'],
                'diagnostic_code' => $rule['diagnostic_code'],
                'diagnostic_message_de' => $rule['diagnostic_message_de'],
                'fix_guidance_de' => $rule['fix_guidance_de'],
                'autofix_mode' => $rule['autofix_mode'],
                'client_support' => $profiles,
            ];
        }

        return [
            'schema_version' => $this->schemaVersion(),
            'catalog_version' => $this->catalogVersion(),
            'rules' => $rules,
        ];
    }

    /**
     * Kompakter Builder-Ausschnitt. Die vollstaendige Evidenzmatrix bleibt
     * serverseitig und wuerde die eingebettete Livewire-Konfiguration um
     * mehrere hundert Kilobyte aufblasen. Auch diese Projektion wird direkt
     * aus derselben CSV erzeugt; es gibt keine zweite JS-Regelliste.
     *
     * @return array{schema_version: string, catalog_version: string, controls: list<array<string, string>>}
     */
    public function builderManifestForJs(): array
    {
        $controls = [];
        $supportRank = [
            'NOT_APPLICABLE' => 0,
            'SUPPORTED' => 1,
            'PARTIAL' => 2,
            'UNKNOWN' => 3,
            'UNSUPPORTED' => 4,
        ];

        foreach ($this->activeRuleGroups() as $group) {
            $rule = $group['definition'];
            // Der Pagebuilder ist per Vertrag SAFE-by-default. Nur Regeln,
            // die davon abweichen, muessen als progressive oder getrennte
            // Steuerelemente in die Browserkonfiguration gelangen.
            if ($rule['compatibility_level'] === 'SAFE') {
                continue;
            }
            $supportStatus = 'NOT_APPLICABLE';
            foreach ($group['profiles'] as $profile) {
                $candidate = $profile['support_status'];
                if (($supportRank[$candidate] ?? 0) > ($supportRank[$supportStatus] ?? 0)) {
                    $supportStatus = $candidate;
                }
            }

            $controls[] = [
                'rule_id' => $rule['rule_id'],
                'compatibility_level' => $rule['compatibility_level'],
                'enforcement' => $rule['enforcement'],
                'autofix_mode' => $rule['autofix_mode'],
                'support_status' => $supportStatus,
            ];
        }

        return [
            'schema_version' => $this->schemaVersion(),
            'catalog_version' => $this->catalogVersion(),
            'controls' => $controls,
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->manifestForJs();
    }

    /** @return array<string, mixed> */
    public static function decodeJson(array $row, string $column): array
    {
        if (! in_array($column, array_merge(self::JSON_LIST_COLUMNS, self::JSON_OBJECT_COLUMNS, ['project_mapping_json']), true)) {
            throw new \InvalidArgumentException("{$column} ist kein bekanntes JSON-Feld des Kompatibilitaetskatalogs.");
        }

        $decoded = json_decode($row[$column] ?? '', true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array{
     *     fingerprint: string,
     *     schema_version: string,
     *     catalog_version: string,
     *     rows: list<array<string, string>>
     * }
     */
    private function payload(): array
    {
        if (! is_file($this->path)) {
            throw new EmailCompatibilityCatalogException(
                'catalog_missing',
                $this->path,
                'Der E-Mail-Kompatibilitaetskatalog fehlt.',
            );
        }

        if (! is_readable($this->path)) {
            throw new EmailCompatibilityCatalogException(
                'catalog_unreadable',
                $this->path,
                'Der E-Mail-Kompatibilitaetskatalog ist nicht lesbar.',
            );
        }

        $stat = @stat($this->path);
        if (! is_array($stat)) {
            throw new EmailCompatibilityCatalogException(
                'catalog_stat_failed',
                $this->path,
                'Der Zustand des E-Mail-Kompatibilitaetskatalogs konnte nicht gelesen werden.',
            );
        }

        $fingerprint = implode(':', [(string) ($stat['mtime'] ?? ''), (string) ($stat['size'] ?? '')]);
        $cacheKey = self::cacheKey($this->path);
        if ((self::$cache[$cacheKey]['fingerprint'] ?? null) === $fingerprint) {
            return self::$cache[$cacheKey];
        }

        $contents = @file_get_contents($this->path);
        if (! is_string($contents)) {
            throw new EmailCompatibilityCatalogException(
                'catalog_read_failed',
                $this->path,
                'Der E-Mail-Kompatibilitaetskatalog konnte nicht gelesen werden.',
            );
        }

        $parsed = $this->parse($contents);
        $payload = [
            'fingerprint' => $fingerprint,
            'schema_version' => $parsed['schema_version'],
            'catalog_version' => $parsed['catalog_version'],
            'rows' => $parsed['rows'],
        ];
        self::$cache[$cacheKey] = $payload;

        return $payload;
    }

    /**
     * @return array{
     *     schema_version: string,
     *     catalog_version: string,
     *     rows: list<array<string, string>>
     * }
     */
    private function parse(string $contents): array
    {
        if ($contents === '') {
            throw $this->error('catalog_empty', 'Der E-Mail-Kompatibilitaetskatalog ist leer.');
        }
        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            throw $this->error('catalog_bom_forbidden', 'Der Katalog muss als UTF-8 ohne BOM gespeichert sein.');
        }
        if (! mb_check_encoding($contents, 'UTF-8')) {
            throw $this->error('catalog_invalid_utf8', 'Der Katalog enthaelt kein gueltiges UTF-8.');
        }
        if (str_contains($contents, "\0")) {
            throw $this->error('catalog_nul_byte', 'Der Katalog enthaelt ein unzulaessiges NUL-Byte.');
        }
        $hasCrLf = str_contains($contents, "\r\n");
        $hasBareLf = preg_match('/(?<!\r)\n/', $contents) === 1;
        $hasBareCr = preg_match('/\r(?!\n)/', $contents) === 1;
        if ($hasBareCr || ($hasCrLf && $hasBareLf)) {
            throw $this->error(
                'catalog_line_endings',
                'Der Katalog muss durchgehend einheitliche LF- oder CRLF-Zeilenenden verwenden.',
            );
        }

        // Git speichert Textdateien kanonisch mit LF, bestehende Windows-
        // Checkouts koennen dieselbe Datei durch core.autocrlf als CRLF
        // liefern. Ab hier arbeitet der Parser auf einer einzigen internen
        // Darstellung; gemischte oder einzelne CR wurden oben abgewiesen.
        $contents = str_replace("\r\n", "\n", $contents);

        $stream = fopen('php://temp', 'r+b');
        if ($stream === false) {
            throw $this->error('catalog_stream_failed', 'Der Katalog konnte nicht in den CSV-Parser geladen werden.');
        }

        try {
            fwrite($stream, $contents);
            rewind($stream);
            $header = fgetcsv($stream, null, ',', '"', '');
            if ($header !== self::HEADER) {
                throw $this->error(
                    'catalog_invalid_header',
                    'Der CSV-Header entspricht nicht dem verbindlichen Schema.',
                    1,
                );
            }

            $rows = [];
            $record = 1;
            while (($values = fgetcsv($stream, null, ',', '"', '')) !== false) {
                $record++;
                if ($values === [null] || $values === []) {
                    throw $this->error('catalog_blank_row', 'Der Katalog enthaelt eine leere Datenzeile.', $record);
                }
                if (count($values) !== count(self::HEADER)) {
                    throw $this->error(
                        'catalog_invalid_column_count',
                        sprintf(
                            'Datenzeile %d enthaelt %d statt %d Spalten.',
                            $record,
                            count($values),
                            count(self::HEADER),
                        ),
                        $record,
                    );
                }

                $values = array_map(static fn ($value): string => is_string($value) ? $value : '', $values);
                /** @var array<string, string> $row */
                $row = array_combine(self::HEADER, $values);
                $this->validateRow($row, $record);
                $rows[] = $row;
            }
        } finally {
            fclose($stream);
        }

        if ($rows === []) {
            throw $this->error('catalog_without_rows', 'Der Katalog enthaelt keine Regelzeilen.');
        }

        $this->validateCollection($rows);

        return [
            'schema_version' => $rows[0]['schema_version'],
            'catalog_version' => $rows[0]['catalog_version'],
            'rows' => $rows,
        ];
    }

    /** @param array<string, string> $row */
    private function validateRow(array $row, int $record): void
    {
        foreach (self::REQUIRED_COLUMNS as $column) {
            if (trim($row[$column]) === '') {
                throw $this->error(
                    'catalog_required_value_missing',
                    "Das Pflichtfeld {$column} ist leer.",
                    $record,
                    $column,
                );
            }
        }

        foreach (self::ENUMS as $column => $allowed) {
            if (! in_array($row[$column], $allowed, true)) {
                throw $this->error(
                    'catalog_invalid_enum',
                    "Der Wert {$row[$column]} ist fuer {$column} nicht erlaubt.",
                    $record,
                    $column,
                );
            }
        }

        foreach (['schema_version', 'catalog_version'] as $column) {
            if (preg_match('/^\d+\.\d+\.\d+$/D', $row[$column]) !== 1) {
                throw $this->error(
                    'catalog_invalid_version',
                    "{$column} muss eine dreiteilige semantische Version sein.",
                    $record,
                    $column,
                );
            }
        }
        if (preg_match('/^[1-9]\d*$/D', $row['rule_revision']) !== 1) {
            throw $this->error(
                'catalog_invalid_revision',
                'rule_revision muss eine positive Ganzzahl sein.',
                $record,
                'rule_revision',
            );
        }
        foreach (['row_id', 'rule_id', 'diagnostic_code', 'client_profile_id'] as $column) {
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:@-]*$/D', $row[$column]) !== 1) {
                throw $this->error(
                    'catalog_invalid_identifier',
                    "{$column} enthaelt keinen gueltigen stabilen Bezeichner.",
                    $record,
                    $column,
                );
            }
        }
        if ($row['validator_handler'] !== ''
            && preg_match('/^[a-z][a-z0-9_]*$/D', $row['validator_handler']) !== 1) {
            throw $this->error(
                'catalog_invalid_handler_name',
                'validator_handler darf nur einen deklarativen snake_case-Bezeichner enthalten.',
                $record,
                'validator_handler',
            );
        }

        foreach (self::JSON_LIST_COLUMNS as $column) {
            $decoded = $this->decodeForValidation($row[$column], $record, $column);
            if (! is_array($decoded)) {
                throw $this->error(
                    'catalog_json_list_required',
                    "{$column} muss ein JSON-Array sein.",
                    $record,
                    $column,
                );
            }
        }
        foreach (self::JSON_OBJECT_COLUMNS as $column) {
            $decoded = $this->decodeForValidation($row[$column], $record, $column);
            if (! $decoded instanceof stdClass) {
                throw $this->error(
                    'catalog_json_object_required',
                    "{$column} muss ein JSON-Objekt sein.",
                    $record,
                    $column,
                );
            }
        }
        $projectMapping = $this->decodeForValidation($row['project_mapping_json'], $record, 'project_mapping_json');
        if (! is_array($projectMapping) && ! $projectMapping instanceof stdClass) {
            throw $this->error(
                'catalog_json_container_required',
                'project_mapping_json muss ein JSON-Array oder -Objekt sein.',
                $record,
                'project_mapping_json',
            );
        }

        /** @var list<mixed> $documentKinds */
        $documentKinds = json_decode($row['document_kinds_json'], true, 512, JSON_THROW_ON_ERROR);
        if ($documentKinds === []) {
            throw $this->error(
                'catalog_document_kinds_empty',
                'document_kinds_json darf nicht leer sein.',
                $record,
                'document_kinds_json',
            );
        }
        foreach ($documentKinds as $kind) {
            if (! is_string($kind) || ! in_array($kind, self::DOCUMENT_KINDS, true)) {
                throw $this->error(
                    'catalog_invalid_document_kind',
                    'document_kinds_json enthaelt eine unbekannte Dokumentart.',
                    $record,
                    'document_kinds_json',
                );
            }
        }

        foreach (self::DATE_COLUMNS as $column) {
            if ($row[$column] !== '' && ! self::isIsoDate($row[$column])) {
                throw $this->error(
                    'catalog_invalid_date',
                    "{$column} muss ein ISO-Datum im Format YYYY-MM-DD sein.",
                    $record,
                    $column,
                );
            }
        }
        if (filter_var($row['source_url'], FILTER_VALIDATE_URL) === false
            || ! in_array(strtolower((string) parse_url($row['source_url'], PHP_URL_SCHEME)), ['http', 'https'], true)) {
            throw $this->error(
                'catalog_invalid_source_url',
                'source_url muss eine absolute HTTP(S)-Quelle sein.',
                $record,
                'source_url',
            );
        }

        if ($row['support_status'] === 'PARTIAL' && trim($row['exception_handling_de']) === '') {
            throw $this->error(
                'catalog_partial_without_fallback',
                'PARTIAL-Unterstuetzung benoetigt einen dokumentierten Fallback in exception_handling_de.',
                $record,
                'exception_handling_de',
            );
        }
        if ($row['autofix_mode'] === 'NONE' && $row['autofix_action'] !== 'NONE') {
            throw $this->error(
                'catalog_inconsistent_autofix',
                'autofix_mode NONE darf keine Autofix-Aktion freigeben.',
                $record,
                'autofix_action',
            );
        }
    }

    /** @param list<array<string, string>> $rows */
    private function validateCollection(array $rows): void
    {
        $schemaVersion = $rows[0]['schema_version'];
        $catalogVersion = $rows[0]['catalog_version'];
        $rowIds = [];
        $profileKeys = [];
        $definitions = [];
        $groups = [];
        $activeRevisions = [];

        foreach ($rows as $offset => $row) {
            $record = $offset + 2;
            if ($row['schema_version'] !== $schemaVersion || $row['catalog_version'] !== $catalogVersion) {
                throw $this->error(
                    'catalog_mixed_versions',
                    'Alle Zeilen muessen dieselbe Schema- und Katalogversion verwenden.',
                    $record,
                );
            }
            if (isset($rowIds[$row['row_id']])) {
                throw $this->error(
                    'catalog_duplicate_row_id',
                    "row_id {$row['row_id']} ist nicht eindeutig.",
                    $record,
                    'row_id',
                );
            }
            $rowIds[$row['row_id']] = true;

            $ruleKey = $row['rule_id'].'@'.$row['rule_revision'];
            $profileKey = $ruleKey.'|'.$row['client_profile_id'];
            if (isset($profileKeys[$profileKey])) {
                throw $this->error(
                    'catalog_duplicate_rule_profile',
                    'Eine Regelrevision darf je Clientprofil nur eine Zeile besitzen.',
                    $record,
                    'client_profile_id',
                );
            }
            $profileKeys[$profileKey] = true;

            $definition = array_intersect_key($row, array_flip(self::RULE_DEFINITION_COLUMNS));
            if (isset($definitions[$ruleKey])) {
                foreach (self::RULE_DEFINITION_COLUMNS as $column) {
                    if ($definitions[$ruleKey][$column] !== $definition[$column]) {
                        throw $this->error(
                            'catalog_inconsistent_revision',
                            "Die Definition von {$ruleKey} weicht zwischen Clientzeilen ab.",
                            $record,
                            $column,
                        );
                    }
                }
            } else {
                $definitions[$ruleKey] = $definition;
            }

            $groups[$ruleKey][] = $row;
            if ($row['rule_status'] === 'ACTIVE') {
                $activeRevisions[$row['rule_id']][$row['rule_revision']] = true;
            }
        }

        foreach ($activeRevisions as $ruleId => $revisions) {
            if (count($revisions) > 1) {
                throw $this->error(
                    'catalog_multiple_active_revisions',
                    "{$ruleId} besitzt mehr als eine aktive Revision.",
                    null,
                    'rule_revision',
                );
            }
        }

        foreach ($groups as $ruleKey => $groupRows) {
            $profiles = array_column($groupRows, 'client_profile_id');
            if (in_array('all', $profiles, true)) {
                if (count($profiles) !== 1) {
                    throw $this->error(
                        'catalog_all_profile_mixed',
                        "{$ruleKey} mischt das Sammelprofil all mit konkreten Profilen.",
                        null,
                        'client_profile_id',
                    );
                }

                continue;
            }

            if ($groupRows[0]['compatibility_level'] !== 'SAFE') {
                continue;
            }

            $byProfile = [];
            foreach ($groupRows as $groupRow) {
                $byProfile[$groupRow['client_profile_id']] = $groupRow;
            }
            foreach (self::REQUIRED_CLIENT_PROFILES as $profile) {
                if (! isset($byProfile[$profile])) {
                    throw $this->error(
                        'catalog_safe_profile_missing',
                        "SAFE-Regel {$ruleKey} hat keinen Beleg fuer {$profile}.",
                        null,
                        'client_profile_id',
                    );
                }
                if ($byProfile[$profile]['support_status'] === 'UNKNOWN') {
                    throw $this->error(
                        'catalog_safe_profile_unknown',
                        "SAFE-Regel {$ruleKey} darf {$profile} nicht als UNKNOWN ausweisen.",
                        null,
                        'support_status',
                    );
                }
            }
        }
    }

    private function decodeForValidation(string $json, int $record, string $column): mixed
    {
        try {
            return json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw $this->error(
                'catalog_invalid_json',
                "{$column} enthaelt ungueltiges JSON: {$exception->getMessage()}",
                $record,
                $column,
                $exception,
            );
        }
    }

    private static function isIsoDate(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }

    private static function cacheKey(string $path): string
    {
        $resolved = realpath($path);

        return strtolower(str_replace('\\', '/', $resolved === false ? $path : $resolved));
    }

    private function error(
        string $code,
        string $message,
        ?int $row = null,
        ?string $column = null,
        ?\Throwable $previous = null,
    ): EmailCompatibilityCatalogException {
        return new EmailCompatibilityCatalogException(
            $code,
            $this->path,
            $message,
            $row,
            $column,
            $previous,
        );
    }
}
