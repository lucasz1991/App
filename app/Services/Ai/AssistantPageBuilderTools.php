<?php

namespace App\Services\Ai;

use App\Models\File;
use App\Models\MailDocument;
use App\Models\MarketingCreative;
use App\Models\MarketingCreativeVariant;
use App\Models\User;
use App\Services\Marketing\MarketingFileSourceService;
use Illuminate\Support\Str;
use Throwable;

/**
 * Narrow, value-free server contract between RailTime Assist and an active
 * LMZ editor. The browser adapter remains the only code allowed to touch a
 * GrapesJS instance; this service only validates context and emits commands.
 */
final class AssistantPageBuilderTools
{
    public const STATUS_TOOL = 'get_pagebuilder_status';

    public const SELECTION_TOOL = 'inspect_pagebuilder_selection';

    public const VALIDATION_TOOL = 'validate_pagebuilder_document';

    public const IMAGE_SEARCH_TOOL = 'search_pagebuilder_images';

    public const GUIDE_TOOL = 'guide_pagebuilder';

    public const TEXT_TOOL = 'edit_pagebuilder_text';

    public const STYLE_TOOL = 'set_pagebuilder_style';

    public const IMAGE_TOOL = 'replace_pagebuilder_image';

    public const BLOCK_TOOL = 'add_pagebuilder_block';

    public const HISTORY_TOOL = 'control_pagebuilder_history';

    public const PREVIEW_TOOL = 'control_pagebuilder_preview';

    public const ANIMATION_TOOL = 'set_pagebuilder_animation';

    public const SAVE_TOOL = 'save_pagebuilder_document';

    public const EFFECT_TYPE = 'pagebuilder';

    private const ROUTES = [
        'admin.marketing.creatives.editor' => 'marketing',
        'admin.mail-documents.editor' => 'mail',
    ];

    private const PANELS = [
        'blocks', 'layers', 'styles', 'traits', 'assets', 'spacing', 'media', 'animation',
    ];

    private const MARKETING_BLOCKS = [
        'rt-marketing-logo-light', 'rt-marketing-logo-dark', 'rt-marketing-hero',
        'rt-marketing-kicker', 'rt-marketing-headline', 'rt-marketing-facts',
        'rt-marketing-tasks', 'rt-marketing-profile', 'rt-marketing-benefits',
        'rt-marketing-contact', 'rt-marketing-cta', 'rt-marketing-qr',
    ];

    private const MAIL_BLOCKS = [
        'rt-mail-section', 'rt-mail-two-columns', 'rt-mail-heading',
        'rt-mail-paragraph', 'rt-mail-button', 'rt-mail-divider', 'rt-mail-spacer',
    ];

    private const BASE_CAPABILITIES = [
        'open_fullscreen', 'open_panel', 'focus_selection', 'edit_text', 'set_style',
        'add_block', 'undo', 'redo', 'preview', 'save', 'animation', 'gif_preview',
    ];

    private const MARKETING_CAPABILITIES = [
        'open_fullscreen', 'open_panel', 'focus_selection', 'edit_text', 'set_style',
        'replace_image', 'add_block', 'undo', 'redo', 'preview', 'save', 'animation',
        'gif_preview',
    ];

    private const SELECTION_COMMANDS = [
        'focus_selection', 'edit_text', 'set_style', 'replace_image', 'add_block',
        'set_animation', 'restart_gif',
    ];

    private const IMMUTABLE_MAIL_BLOCK_PREFIXES = [
        'rt-mail-signature', 'rt-mail-person-', 'rt-mail-token', 'rt-mail-field',
    ];

    private const STRUCTURAL_TAGS = [
        'html', 'head', 'body', 'style', 'table', 'tbody', 'thead', 'tfoot', 'tr', 'td', 'th',
    ];

    private const MARKETING_STYLE_PROPERTIES = [
        'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
        'margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left',
        'width', 'height', 'max-width', 'min-height', 'font-size', 'font-weight',
        'line-height', 'letter-spacing', 'text-align', 'color', 'background-color',
        'border', 'border-radius', 'opacity', 'object-fit', 'object-position',
        'display', 'gap', 'justify-content', 'align-items',
    ];

    private const MAIL_STYLE_PROPERTIES = [
        'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
        'margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left',
        'width', 'max-width', 'height', 'font-size', 'font-weight', 'line-height',
        'text-align', 'vertical-align', 'color', 'background-color', 'border', 'border-radius',
    ];

    private const MOTIONS = [
        '', 'none', 'fade-up', 'fade-down', 'fade-left', 'fade-right', 'scale',
        'reveal', 'parallax', 'split-lines',
    ];

    private const EASES = [
        '', 'none', 'power3.out', 'power2.out', 'power4.out', 'power2.inOut',
        'power3.inOut', 'sine.inOut', 'back.out(1.4)',
    ];

    private const STARTS = [
        '', 'top 90%', 'top 85%', 'top 80%', 'top 75%', 'top center', 'center center',
    ];

    private const ENDS = [
        '', 'bottom 10%', 'bottom 20%', 'bottom center', '+=300', '+=500', '+=800',
    ];

    private const SCRUBS = ['', 'false', '0.25', '0.5', '0.8', '1.2', '2'];

    public function __construct(private readonly MarketingFileSourceService $marketingFiles) {}

    public function canUse(User $user, string $currentRoute): bool
    {
        return $user->isAdmin() && isset(self::ROUTES[$currentRoute]);
    }

    /** @return list<array<string, mixed>> */
    public function toolDefinitions(User $user, string $currentRoute): array
    {
        if (! $this->canUse($user, $currentRoute)) {
            return [];
        }

        $tools = [
            $this->tool(self::STATUS_TOOL, 'Liest nur den sicheren Status des aktuell geöffneten LMZ-Editors, niemals HTML, CSS oder Builder-Rohdaten.', $this->emptySchema()),
            $this->tool(self::SELECTION_TOOL, 'Beschreibt die aktuelle Editor-Auswahl mit begrenztem Text, erlaubten Stilen und Schutzstatus.', $this->emptySchema()),
            $this->tool(self::VALIDATION_TOOL, 'Liest die bereits lokal berechnete, begrenzte Validierungszusammenfassung des Motivs oder Maildokuments.', $this->emptySchema()),
            $this->tool(self::GUIDE_TOOL, 'Öffnet nach Bestätigung den Vollbildeditor, fokussiert die aktuelle Auswahl oder öffnet eine freigegebene Editor-Seitenleiste.', [
                'type' => 'object',
                'properties' => [
                    'action' => ['type' => 'string', 'enum' => ['open_fullscreen', 'focus_selection', 'open_panel']],
                    'panel' => ['type' => 'string', 'enum' => self::PANELS],
                ],
                'required' => ['action'],
                'additionalProperties' => false,
            ]),
            $this->tool(self::TEXT_TOOL, 'Ersetzt nach Bestätigung ausschließlich den Klartext der aktuellen, editierbaren Auswahl. Kein HTML und keine Platzhalter.', [
                'type' => 'object',
                'properties' => ['text' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 1000]],
                'required' => ['text'],
                'additionalProperties' => false,
            ]),
            $this->tool(self::STYLE_TOOL, 'Setzt nach Bestätigung genau eine freigegebene Stil-Eigenschaft der aktuellen Auswahl. Keine freien CSS-Regeln.', [
                'type' => 'object',
                'properties' => [
                    'property' => ['type' => 'string', 'enum' => $this->styleProperties(self::ROUTES[$currentRoute])],
                    'value' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 80],
                ],
                'required' => ['property', 'value'],
                'additionalProperties' => false,
            ]),
            $this->tool(self::BLOCK_TOOL, 'Fügt nach Bestätigung einen sicheren RailTime-Block relativ zur aktuellen Auswahl ein.', [
                'type' => 'object',
                'properties' => [
                    'block_id' => ['type' => 'string', 'enum' => $this->blocks(self::ROUTES[$currentRoute])],
                    'position' => ['type' => 'string', 'enum' => ['before', 'after', 'inside']],
                ],
                'required' => ['block_id', 'position'],
                'additionalProperties' => false,
            ]),
            $this->tool(self::HISTORY_TOOL, 'Führt nach Bestätigung genau einen lokalen Undo- oder Redo-Schritt aus.', [
                'type' => 'object',
                'properties' => ['action' => ['type' => 'string', 'enum' => ['undo', 'redo']]],
                'required' => ['action'],
                'additionalProperties' => false,
            ]),
            $this->tool(self::PREVIEW_TOOL, 'Schaltet nach Bestätigung die Editor-Vorschau um. Bei einer ausgewählten GIF-Datei darf ausschließlich die Vorschau neu gestartet werden.', [
                'type' => 'object',
                'properties' => ['action' => ['type' => 'string', 'enum' => ['toggle', 'on', 'off', 'restart_gif']]],
                'required' => ['action'],
                'additionalProperties' => false,
            ]),
            $this->tool(self::ANIMATION_TOOL, 'Ändert nach Bestätigung genau ein vom LMZ-Core freigegebenes Motion-Feld der dafür freigegebenen Auswahl. Keine Frame- oder Framerate-Bearbeitung.', [
                'type' => 'object',
                'properties' => [
                    'field' => ['type' => 'string', 'enum' => ['motion', 'duration', 'delay', 'distance', 'scale', 'stagger', 'ease', 'start', 'end', 'scrub', 'reverse', 'once']],
                    'value' => ['type' => ['string', 'number', 'boolean']],
                ],
                'required' => ['field', 'value'],
                'additionalProperties' => false,
            ]),
            $this->tool(self::SAVE_TOOL, 'Speichert nach Bestätigung nur den aktuellen Arbeitsstand. Dieses Tool veröffentlicht, genehmigt und exportiert niemals.', $this->emptySchema()),
        ];

        if (self::ROUTES[$currentRoute] === 'marketing') {
            $tools[] = $this->tool(self::IMAGE_SEARCH_TOOL, 'Sucht höchstens zwölf Bilder ausschließlich im für Marketing freigegebenen Firmen-Dateipool. Liefert nur IDs und Metadaten, keine URLs.', [
                'type' => 'object',
                'properties' => ['query' => ['type' => 'string', 'maxLength' => 80]],
                'additionalProperties' => false,
            ]);
            $tools[] = $this->tool(self::IMAGE_TOOL, 'Ersetzt nach Bestätigung das ausgewählte Marketing-Bild durch eine geprüfte Datei aus der begrenzten Marketing-Dateiquelle.', [
                'type' => 'object',
                'properties' => ['file_id' => ['type' => 'integer', 'minimum' => 1]],
                'required' => ['file_id'],
                'additionalProperties' => false,
            ]);
        }

        return $tools;
    }

    /** @return list<string> */
    public function toolNames(User $user, string $currentRoute): array
    {
        return array_values(array_map(
            static fn (array $tool): string => (string) data_get($tool, 'function.name'),
            $this->toolDefinitions($user, $currentRoute),
        ));
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $context
     * @return array{payload:array<string, mixed>,effect:?array<string, mixed>}
     */
    public function execute(string $name, array $arguments, User $user, string $currentRoute, array $context): array
    {
        if (! in_array($name, $this->toolNames($user, $currentRoute), true)) {
            return $this->error('unknown_tool', 'Dieses PageBuilder-Tool ist auf dieser Seite nicht freigegeben.');
        }

        $safe = $this->normalizeContext($user, $currentRoute, $context);
        if ($safe === []) {
            return $this->error('context_unavailable', 'Der sichere Editor-Kontext ist nicht bereit oder bereits veraltet.');
        }

        return match ($name) {
            self::STATUS_TOOL => $this->readStatus($safe),
            self::SELECTION_TOOL => $this->readSelection($safe),
            self::VALIDATION_TOOL => ['payload' => ['state' => 'ready', 'validation' => $safe['validation']], 'effect' => null],
            self::IMAGE_SEARCH_TOOL => $this->searchImages($arguments, $safe),
            self::GUIDE_TOOL => $this->guide($arguments, $safe),
            self::TEXT_TOOL => $this->editText($arguments, $safe),
            self::STYLE_TOOL => $this->setStyle($arguments, $safe),
            self::IMAGE_TOOL => $this->replaceImage($arguments, $safe),
            self::BLOCK_TOOL => $this->addBlock($arguments, $safe),
            self::HISTORY_TOOL => $this->history($arguments, $safe),
            self::PREVIEW_TOOL => $this->preview($arguments, $safe),
            self::ANIMATION_TOOL => $this->animation($arguments, $safe),
            self::SAVE_TOOL => $this->save($safe),
            default => $this->error('unknown_tool', 'Dieses PageBuilder-Tool ist nicht freigegeben.'),
        };
    }

    /**
     * Strip all provider-controlled fields from a browser effect. Database and
     * FilePool state are rechecked each time this method is called.
     *
     * @param  array<string, mixed>  $effect
     * @return array<string, mixed>|null
     */
    public function normalizeBrowserEffect(User $user, string $currentRoute, array $effect): ?array
    {
        if (! $this->canUse($user, $currentRoute)
            || $this->string($effect['type'] ?? null) !== self::EFFECT_TYPE
            || $this->string($effect['route_name'] ?? null) !== $currentRoute) {
            return null;
        }

        $mode = self::ROUTES[$currentRoute];
        if ($this->string($effect['mode'] ?? null) !== $mode) {
            return null;
        }
        $resourceId = $this->uuid($effect['resource_id'] ?? null);
        $scope = $this->scope($mode, $effect['format_or_kind'] ?? null);
        $workspaceNonce = $this->nonce($effect['workspace_nonce'] ?? null);
        $persistedHash = $this->hash($effect['persisted_content_hash'] ?? null);
        $persistedVersion = $this->revision($effect['persisted_version'] ?? null);
        $clientRevision = $this->revision($effect['client_revision'] ?? null);
        if ($resourceId === null || $scope === null || $workspaceNonce === null || $persistedHash === null
            || $persistedVersion === null || $clientRevision === null
            || ! $this->documentMatches($mode, $resourceId, $scope, $persistedHash, $persistedVersion)) {
            return null;
        }

        $command = $this->string($effect['command'] ?? null);
        if (! in_array($command, [
            'open_fullscreen', 'focus_selection', 'open_panel', 'edit_text', 'set_style',
            'replace_image', 'add_block', 'undo', 'redo', 'preview', 'restart_gif',
            'set_animation', 'save',
        ], true)) {
            return null;
        }

        $normalized = [
            'type' => self::EFFECT_TYPE,
            'command' => $command,
            'route_name' => $currentRoute,
            'mode' => $mode,
            'resource_id' => $resourceId,
            'format_or_kind' => $scope,
            'workspace_nonce' => $workspaceNonce,
            'persisted_content_hash' => $persistedHash,
            'persisted_version' => $persistedVersion,
            'client_revision' => $clientRevision,
        ];

        if (in_array($command, self::SELECTION_COMMANDS, true)) {
            $selectionNonce = $this->nonce($effect['selection_nonce'] ?? null);
            $fingerprint = $this->hash($effect['selection_fingerprint'] ?? null);
            if ($selectionNonce === null || $fingerprint === null) {
                return null;
            }
            $normalized['selection_nonce'] = $selectionNonce;
            $normalized['selection_fingerprint'] = $fingerprint;
        }

        if ($command === 'open_panel') {
            $panel = $this->string($effect['panel'] ?? null);
            if (! in_array($panel, self::PANELS, true)) {
                return null;
            }
            $normalized['panel'] = $panel;
        } elseif ($command === 'edit_text') {
            $text = $this->plainText($effect['text'] ?? null);
            if ($text === null || ($mode === 'mail' && (str_contains($text, '{{') || str_contains($text, '}}')))) {
                return null;
            }
            $normalized['text'] = $text;
        } elseif ($command === 'set_style') {
            $style = $this->normalizeStyle($mode, $effect['property'] ?? null, $effect['value'] ?? null);
            if ($style === null) {
                return null;
            }
            $normalized += $style;
        } elseif ($command === 'replace_image') {
            if ($mode !== 'marketing' || ($fileId = $this->allowedFileId($effect['file_id'] ?? null)) === null) {
                return null;
            }
            $normalized['file_id'] = $fileId;
        } elseif ($command === 'add_block') {
            $block = $this->string($effect['block_id'] ?? null);
            $position = $this->string($effect['position'] ?? null);
            if (! in_array($block, $this->blocks($mode), true) || ! in_array($position, ['before', 'after', 'inside'], true)) {
                return null;
            }
            $normalized['block_id'] = $block;
            $normalized['position'] = $position;
        } elseif ($command === 'preview') {
            $state = $this->string($effect['state'] ?? null);
            if (! in_array($state, ['toggle', 'on', 'off'], true)) {
                return null;
            }
            $normalized['state'] = $state;
        } elseif ($command === 'set_animation') {
            $motion = $this->normalizeAnimation($effect['field'] ?? null, $effect['value'] ?? null);
            if ($motion === null) {
                return null;
            }
            $normalized += $motion;
        }

        return $normalized;
    }

    /** @param array<string, mixed> $effect @param array<string, mixed> $context */
    public function browserEffectMatchesContext(User $user, string $currentRoute, array $effect, array $context): bool
    {
        $effect = $this->normalizeBrowserEffect($user, $currentRoute, $effect);
        $safe = $this->normalizeContext($user, $currentRoute, $context);
        if ($effect === null || $safe === []) {
            return false;
        }

        foreach (['route_name', 'mode', 'resource_id', 'format_or_kind', 'workspace_nonce', 'persisted_content_hash', 'persisted_version', 'client_revision'] as $key) {
            if (! array_key_exists($key, $effect) || ! array_key_exists($key, $safe)
                || (string) $effect[$key] !== (string) $safe[$key]) {
                return false;
            }
        }

        $command = (string) $effect['command'];
        if ($command !== 'open_fullscreen' && (! $safe['editor_ready'] || ! $safe['fullscreen_open'] || $safe['read_only'])) {
            return false;
        }
        if ($command === 'open_fullscreen' && (! $safe['editor_ready'] || $safe['read_only'])) {
            return false;
        }

        if (! $this->capabilityAllowsCommand($safe['capabilities'], $command)) {
            return false;
        }

        if (in_array($command, self::SELECTION_COMMANDS, true)) {
            $selection = $safe['selection'];
            if (! is_array($selection)
                || ! hash_equals((string) $selection['selection_nonce'], (string) $effect['selection_nonce'])
                || ! hash_equals((string) $selection['fingerprint'], (string) $effect['selection_fingerprint'])) {
                return false;
            }

            if (in_array($command, ['edit_text', 'set_style', 'replace_image', 'add_block', 'set_animation'], true)
                && $selection['protected']) {
                return false;
            }
            if ($command === 'replace_image' && $selection['tag'] !== 'img') {
                return false;
            }
            if ($command === 'set_animation' && ! $selection['motion_allowed']) {
                return false;
            }
            if ($command === 'restart_gif' && ! $selection['gif']) {
                return false;
            }
        }

        if ($command === 'add_block' && ! in_array((string) $effect['block_id'], $safe['available_block_ids'], true)) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function normalizeContext(User $user, string $currentRoute, array $context): array
    {
        if (! $this->canUse($user, $currentRoute)
            || (int) ($context['version'] ?? 0) !== 1
            || $this->string($context['route_name'] ?? null) !== $currentRoute) {
            return [];
        }

        $mode = self::ROUTES[$currentRoute];
        if ($this->string($context['mode'] ?? null) !== $mode) {
            return [];
        }

        $resourceId = $this->uuid($context['resource_id'] ?? null);
        $scope = $this->scope($mode, $context['format_or_kind'] ?? null);
        $workspaceNonce = $this->nonce($context['workspace_nonce'] ?? null);
        $persistedHash = $this->hash($context['persisted_content_hash'] ?? null);
        $persistedVersion = $this->revision($context['persisted_version'] ?? null);
        $clientRevision = $this->revision($context['client_revision'] ?? null);
        if ($resourceId === null || $scope === null || $workspaceNonce === null || $persistedHash === null
            || $persistedVersion === null || $clientRevision === null
            || ! $this->documentMatches($mode, $resourceId, $scope, $persistedHash, $persistedVersion)) {
            return [];
        }

        $allowedCapabilities = $mode === 'marketing' ? self::MARKETING_CAPABILITIES : self::BASE_CAPABILITIES;
        $capabilities = $this->strings($context['capabilities'] ?? [], $allowedCapabilities, 24);
        $availableBlocks = $this->strings($context['available_block_ids'] ?? [], $this->blocks($mode), 24);
        $selection = $this->normalizeSelection($mode, $context['selection'] ?? null);

        return [
            'version' => 1,
            'route_name' => $currentRoute,
            'mode' => $mode,
            'resource_id' => $resourceId,
            'format_or_kind' => $scope,
            'workspace_nonce' => $workspaceNonce,
            'fullscreen_open' => (bool) ($context['fullscreen_open'] ?? false),
            'editor_ready' => (bool) ($context['editor_ready'] ?? false),
            'read_only' => (bool) ($context['read_only'] ?? false),
            'persisted_content_hash' => $persistedHash,
            'persisted_version' => $persistedVersion,
            'client_revision' => $clientRevision,
            'unsaved' => (bool) ($context['unsaved'] ?? false),
            'selection' => $selection,
            'capabilities' => $capabilities,
            'available_block_ids' => $availableBlocks,
            'validation' => $this->validation($context['validation'] ?? null),
        ];
    }

    /** @param array<string, mixed> $safe */
    private function readStatus(array $safe): array
    {
        return ['payload' => [
            'state' => $safe['editor_ready'] ? 'ready' : 'loading',
            'mode' => $safe['mode'],
            'format_or_kind' => $safe['format_or_kind'],
            'fullscreen_open' => $safe['fullscreen_open'],
            'read_only' => $safe['read_only'],
            'unsaved' => $safe['unsaved'],
            'selection_available' => $safe['selection'] !== null,
            'capabilities' => $safe['capabilities'],
            'validation' => $safe['validation'],
            'message' => $safe['fullscreen_open']
                ? 'Der sichere LMZ-Vollbildeditor ist bereit.'
                : 'Die Vorschau ist geöffnet. Zum Bearbeiten muss der Vollbildeditor geöffnet werden.',
        ], 'effect' => null];
    }

    /** @param array<string, mixed> $safe */
    private function readSelection(array $safe): array
    {
        return ['payload' => [
            'state' => $safe['selection'] === null ? 'none' : 'selected',
            'selection' => $safe['selection'],
        ], 'effect' => null];
    }

    /** @param array<string, mixed> $arguments @param array<string, mixed> $safe */
    private function searchImages(array $arguments, array $safe): array
    {
        if ($safe['mode'] !== 'marketing') {
            return $this->error('image_scope_denied', 'Maildokumente dürfen nur ihre festen RailTime-Markenmedien verwenden.');
        }

        $query = mb_strtolower(mb_substr($this->string($arguments['query'] ?? null), 0, 80));
        $images = [];
        foreach ($this->marketingFiles->editorAssetLibrary()['assets'] as $asset) {
            $haystack = mb_strtolower((string) ($asset['name'] ?? '').' '.(string) ($asset['category'] ?? ''));
            if ($query !== '' && ! str_contains($haystack, $query)) {
                continue;
            }
            if (! preg_match(MarketingFileSourceService::FILE_PATTERN, (string) ($asset['src'] ?? ''), $matches)) {
                continue;
            }
            $images[] = [
                'file_id' => (int) $matches[1],
                'name' => mb_substr((string) ($asset['name'] ?? 'Bild'), 0, 120),
                'category' => mb_substr((string) ($asset['category'] ?? 'Grundverzeichnis'), 0, 160),
                'width' => max(1, (int) ($asset['width'] ?? 1)),
                'height' => max(1, (int) ($asset['height'] ?? 1)),
            ];
            if (count($images) === 12) {
                break;
            }
        }

        return ['payload' => ['state' => 'ready', 'images' => $images, 'count' => count($images), 'limit' => 12], 'effect' => null];
    }

    /** @param array<string, mixed> $arguments @param array<string, mixed> $safe */
    private function guide(array $arguments, array $safe): array
    {
        $action = $this->string($arguments['action'] ?? null);
        if ($action === 'open_fullscreen') {
            return $this->scheduled('open_fullscreen', [], $safe, false, 'Der Vollbildeditor wird nach Bestätigung geöffnet.');
        }
        if ($action === 'focus_selection') {
            return $this->scheduled('focus_selection', [], $safe, true, 'Die aktuelle Auswahl wird nach Bestätigung im Editor fokussiert.');
        }
        if ($action === 'open_panel') {
            $panel = $this->string($arguments['panel'] ?? null);
            if (! in_array($panel, self::PANELS, true)) {
                return $this->error('invalid_panel', 'Diese Editor-Seitenleiste ist nicht freigegeben.');
            }

            return $this->scheduled('open_panel', ['panel' => $panel], $safe, false, 'Die Editor-Seitenleiste wird nach Bestätigung geöffnet.');
        }

        return $this->error('invalid_action', 'Diese Editor-Führung ist nicht freigegeben.');
    }

    /** @param array<string, mixed> $arguments @param array<string, mixed> $safe */
    private function editText(array $arguments, array $safe): array
    {
        $text = $this->plainText($arguments['text'] ?? null);
        if ($text === null || ($safe['mode'] === 'mail' && (str_contains($text, '{{') || str_contains($text, '}}')))) {
            return $this->error('invalid_text', 'Nur Klartext ohne HTML, Skripte oder Mail-Platzhalter ist erlaubt.');
        }

        return $this->scheduled('edit_text', ['text' => $text], $safe, true, 'Der Text der aktuellen Auswahl wird nach Bestätigung ersetzt.');
    }

    /** @param array<string, mixed> $arguments @param array<string, mixed> $safe */
    private function setStyle(array $arguments, array $safe): array
    {
        $style = $this->normalizeStyle($safe['mode'], $arguments['property'] ?? null, $arguments['value'] ?? null);
        if ($style === null) {
            return $this->error('invalid_style', 'Diese Stil-Eigenschaft oder dieser Wert ist nicht freigegeben.');
        }

        return $this->scheduled('set_style', $style, $safe, true, 'Der Stil der aktuellen Auswahl wird nach Bestätigung geändert.');
    }

    /** @param array<string, mixed> $arguments @param array<string, mixed> $safe */
    private function replaceImage(array $arguments, array $safe): array
    {
        $fileId = $safe['mode'] === 'marketing' ? $this->allowedFileId($arguments['file_id'] ?? null) : null;
        if ($fileId === null) {
            return $this->error('image_scope_denied', 'Das Bild gehört nicht zur freigegebenen Marketing-Dateiquelle.');
        }

        return $this->scheduled('replace_image', ['file_id' => $fileId], $safe, true, 'Das ausgewählte Bild wird nach Bestätigung ersetzt.');
    }

    /** @param array<string, mixed> $arguments @param array<string, mixed> $safe */
    private function addBlock(array $arguments, array $safe): array
    {
        $block = $this->string($arguments['block_id'] ?? null);
        $position = $this->string($arguments['position'] ?? null);
        if (! in_array($block, $safe['available_block_ids'], true) || ! in_array($position, ['before', 'after', 'inside'], true)) {
            return $this->error('invalid_block', 'Dieser Block ist im aktiven Editor nicht freigegeben.');
        }

        return $this->scheduled('add_block', ['block_id' => $block, 'position' => $position], $safe, true, 'Der RailTime-Block wird nach Bestätigung eingefügt.');
    }

    /** @param array<string, mixed> $arguments @param array<string, mixed> $safe */
    private function history(array $arguments, array $safe): array
    {
        $action = $this->string($arguments['action'] ?? null);
        if (! in_array($action, ['undo', 'redo'], true)) {
            return $this->error('invalid_history_action', 'Erlaubt sind nur Undo und Redo.');
        }

        return $this->scheduled($action, [], $safe, false, $action === 'undo'
            ? 'Der letzte lokale Editorschritt wird nach Bestätigung rückgängig gemacht.'
            : 'Der nächste lokale Editorschritt wird nach Bestätigung wiederhergestellt.');
    }

    /** @param array<string, mixed> $arguments @param array<string, mixed> $safe */
    private function preview(array $arguments, array $safe): array
    {
        $action = $this->string($arguments['action'] ?? null);
        if ($action === 'restart_gif') {
            return $this->scheduled('restart_gif', [], $safe, true, 'Die GIF-Vorschau der aktuellen Auswahl wird nach Bestätigung neu gestartet.');
        }
        if (! in_array($action, ['toggle', 'on', 'off'], true)) {
            return $this->error('invalid_preview_action', 'Diese Vorschauaktion ist nicht freigegeben.');
        }

        return $this->scheduled('preview', ['state' => $action], $safe, false, 'Die Editor-Vorschau wird nach Bestätigung umgeschaltet.');
    }

    /** @param array<string, mixed> $arguments @param array<string, mixed> $safe */
    private function animation(array $arguments, array $safe): array
    {
        $motion = $this->normalizeAnimation($arguments['field'] ?? null, $arguments['value'] ?? null);
        if ($motion === null) {
            return $this->error('invalid_animation', 'Dieses LMZ-Motion-Feld oder sein Wert ist nicht freigegeben.');
        }

        return $this->scheduled('set_animation', $motion, $safe, true, 'Die freigegebene Animationseigenschaft wird nach Bestätigung geändert.');
    }

    /** @param array<string, mixed> $safe */
    private function save(array $safe): array
    {
        return $this->scheduled('save', [], $safe, false, 'Der aktuelle Arbeitsstand wird nach Bestätigung gespeichert. Es erfolgt keine Veröffentlichung oder Freigabe.');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $safe
     * @return array{payload:array<string, mixed>,effect:?array<string, mixed>}
     */
    private function scheduled(string $command, array $payload, array $safe, bool $requiresSelection, string $message): array
    {
        if (! $safe['editor_ready'] || $safe['read_only']) {
            return $this->error('editor_unavailable', 'Der Editor ist noch nicht bereit oder nur lesbar.');
        }
        if ($command !== 'open_fullscreen' && ! $safe['fullscreen_open']) {
            return $this->error('fullscreen_required', 'Öffnen Sie zuerst den Vollbildeditor.');
        }
        if (! $this->capabilityAllowsCommand($safe['capabilities'], $command)) {
            return $this->error('capability_unavailable', 'Diese Funktion ist im aktiven Editor nicht verfügbar.');
        }

        $selection = $safe['selection'];
        if ($requiresSelection && ! is_array($selection)) {
            return $this->error('selection_required', 'Wählen Sie zuerst ein Element im Editor aus.');
        }
        if ($requiresSelection && in_array($command, ['edit_text', 'set_style', 'replace_image', 'add_block', 'set_animation'], true)
            && $selection['protected']) {
            return $this->error('protected_selection', 'Diese Auswahl enthält geschützte Mail-, Signatur- oder Strukturinhalte.');
        }
        if ($command === 'set_animation' && ! $selection['motion_allowed']) {
            return $this->error('animation_not_allowed', 'Der LMZ-Core hat diese Auswahl nicht für Animationen freigegeben.');
        }
        if ($command === 'restart_gif' && ! $selection['gif']) {
            return $this->error('gif_required', 'Die aktuelle Auswahl ist keine GIF-Vorschau.');
        }
        if ($command === 'replace_image' && $selection['tag'] !== 'img') {
            return $this->error('image_selection_required', 'Wählen Sie zuerst ein ersetzbares Marketing-Bild aus.');
        }

        $effect = [
            'type' => self::EFFECT_TYPE,
            'command' => $command,
            'route_name' => $safe['route_name'],
            'mode' => $safe['mode'],
            'resource_id' => $safe['resource_id'],
            'format_or_kind' => $safe['format_or_kind'],
            'workspace_nonce' => $safe['workspace_nonce'],
            'persisted_content_hash' => $safe['persisted_content_hash'],
            'persisted_version' => $safe['persisted_version'],
            'client_revision' => $safe['client_revision'],
        ] + $payload;

        if ($requiresSelection) {
            $effect['selection_nonce'] = $selection['selection_nonce'];
            $effect['selection_fingerprint'] = $selection['fingerprint'];
        }

        return ['payload' => ['state' => 'scheduled', 'command' => $command, 'message' => $message], 'effect' => $effect];
    }

    /** @param array<string, mixed>|mixed $selection @return array<string, mixed>|null */
    private function normalizeSelection(string $mode, mixed $selection): ?array
    {
        if (! is_array($selection)) {
            return null;
        }
        $selectionNonce = $this->nonce($selection['selection_nonce'] ?? null);
        $fingerprint = $this->hash($selection['fingerprint'] ?? null);
        if ($selectionNonce === null || $fingerprint === null) {
            return null;
        }

        $tag = strtolower(preg_replace('/[^a-z0-9-]/i', '', $this->string($selection['tag'] ?? null)) ?? '');
        $blockId = preg_replace('/[^a-zA-Z0-9_-]/', '', $this->string($selection['block_id'] ?? null)) ?? '';
        $text = $this->boundedDisplayText($selection['text'] ?? null, 600);
        $protected = (bool) ($selection['protected'] ?? false);
        if ($mode === 'mail') {
            $protected = $protected
                || in_array($tag, self::STRUCTURAL_TAGS, true)
                || str_contains($text, '{{')
                || collect(self::IMMUTABLE_MAIL_BLOCK_PREFIXES)->contains(
                    static fn (string $prefix): bool => str_starts_with($blockId, $prefix),
                );
        }

        $styles = [];
        foreach (is_array($selection['styles'] ?? null) ? $selection['styles'] : [] as $property => $value) {
            $style = $this->normalizeStyle($mode, $property, $value);
            if ($style !== null) {
                $styles[$style['property']] = $style['value'];
            }
            if (count($styles) === 24) {
                break;
            }
        }

        return [
            'selection_nonce' => $selectionNonce,
            'fingerprint' => $fingerprint,
            'tag' => mb_substr($tag, 0, 20),
            'block_id' => mb_substr($blockId, 0, 80),
            'text' => $text,
            'styles' => $styles,
            'image_file_id' => $mode === 'marketing' ? $this->positiveInt($selection['image_file_id'] ?? null) : null,
            'protected' => $protected,
            'motion_allowed' => ! $protected && (bool) ($selection['motion_allowed'] ?? false),
            'gif' => ! $protected && (bool) ($selection['gif'] ?? false),
        ];
    }

    /** @return array{state:string,issues:list<string>} */
    private function validation(mixed $validation): array
    {
        $validation = is_array($validation) ? $validation : [];
        $state = $this->string($validation['state'] ?? null);
        if (! in_array($state, ['valid', 'warning', 'error', 'unknown'], true)) {
            $state = 'unknown';
        }

        $issues = [];
        foreach (is_array($validation['issues'] ?? null) ? $validation['issues'] : [] as $issue) {
            $value = $this->boundedDisplayText($issue, 160);
            if ($value !== '') {
                $issues[] = $value;
            }
            if (count($issues) === 8) {
                break;
            }
        }

        return ['state' => $state, 'issues' => $issues];
    }

    private function documentMatches(string $mode, string $resourceId, string $scope, string $hash, int $revision): bool
    {
        if ($mode === 'marketing') {
            $creative = MarketingCreative::query()->where('public_id', $resourceId)->first();
            $variant = $creative?->variants()->where('format', $scope)->first();

            return $variant instanceof MarketingCreativeVariant
                && (int) $variant->version === $revision
                && is_string($variant->content_hash)
                && hash_equals(strtolower($variant->content_hash), $hash);
        }

        $document = MailDocument::query()
            ->where('public_id', $resourceId)
            ->where('kind', $scope)
            ->first();

        return $document instanceof MailDocument
            && (int) $document->version === $revision
            && is_string($document->content_hash)
            && hash_equals(strtolower($document->content_hash), $hash);
    }

    /** @return array{property:string,value:string}|null */
    private function normalizeStyle(string $mode, mixed $property, mixed $value): ?array
    {
        $property = strtolower($this->string($property));
        $value = $this->string($value);
        if (! in_array($property, $this->styleProperties($mode), true)
            || $value === '' || strlen($value) > 80
            || preg_match('/[{};]|url\s*\(|var\s*\(|calc\s*\(|!important|expression/i', $value)) {
            return null;
        }

        $valid = match ($property) {
            'color', 'background-color' => preg_match('/\A(?:#[0-9a-f]{3,8}|rgba?\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}(?:\s*,\s*(?:0|1|0?\.\d+))?\s*\)|transparent)\z/i', $value) === 1,
            'font-weight' => preg_match('/\A(?:normal|bold|[1-9]00)\z/i', $value) === 1,
            'text-align' => in_array(strtolower($value), ['left', 'center', 'right', 'justify'], true),
            'vertical-align' => in_array(strtolower($value), ['top', 'middle', 'bottom', 'baseline'], true),
            'object-fit' => in_array(strtolower($value), ['cover', 'contain', 'fill', 'none', 'scale-down'], true),
            'object-position' => preg_match('/\A(?:left|center|right|top|bottom|\d{1,3}(?:\.\d+)?%)(?:\s+(?:left|center|right|top|bottom|\d{1,3}(?:\.\d+)?%))?\z/i', $value) === 1,
            'display' => in_array(strtolower($value), ['block', 'inline', 'inline-block', 'flex', 'grid', 'none'], true),
            'justify-content' => in_array(strtolower($value), ['start', 'center', 'end', 'space-between', 'space-around'], true),
            'align-items' => in_array(strtolower($value), ['start', 'center', 'end', 'stretch'], true),
            'opacity' => is_numeric($value) && (float) $value >= 0 && (float) $value <= 1,
            'border' => preg_match('/\A(?:0|none|\d{1,2}(?:\.\d+)?px\s+(?:solid|dashed|dotted)\s+#[0-9a-f]{3,8})\z/i', $value) === 1,
            'line-height' => preg_match('/\A(?:normal|(?:0(?:\.\d+)?|[1-4](?:\.\d+)?)(?:px|em|rem|%)?)\z/i', $value) === 1,
            default => preg_match('/\A(?:0|auto|-?\d{1,4}(?:\.\d+)?(?:px|%|em|rem|vw|vh))\z/i', $value) === 1,
        };

        return $valid ? ['property' => $property, 'value' => $value] : null;
    }

    /** @return array{field:string,value:string|float|bool}|null */
    private function normalizeAnimation(mixed $field, mixed $value): ?array
    {
        $field = $this->string($field);

        return match ($field) {
            'motion' => in_array($this->string($value), self::MOTIONS, true) ? ['field' => $field, 'value' => $this->string($value)] : null,
            'ease' => in_array($this->string($value), self::EASES, true) ? ['field' => $field, 'value' => $this->string($value)] : null,
            'start' => in_array($this->string($value), self::STARTS, true) ? ['field' => $field, 'value' => $this->string($value)] : null,
            'end' => in_array($this->string($value), self::ENDS, true) ? ['field' => $field, 'value' => $this->string($value)] : null,
            'scrub' => in_array($this->string($value), self::SCRUBS, true) ? ['field' => $field, 'value' => $this->string($value)] : null,
            'duration' => $this->boundedNumber($value, .1, 5, $field),
            'delay' => $this->boundedNumber($value, 0, 5, $field),
            'distance' => $this->boundedNumber($value, 0, 300, $field),
            'scale' => $this->boundedNumber($value, .5, 1.5, $field),
            'stagger' => $this->boundedNumber($value, 0, 1, $field),
            'reverse', 'once' => $this->boolean($value) === null ? null : ['field' => $field, 'value' => $this->boolean($value)],
            default => null,
        };
    }

    /** @return array{field:string,value:float}|null */
    private function boundedNumber(mixed $value, float $min, float $max, string $field): ?array
    {
        if (! is_numeric($value) || (float) $value < $min || (float) $value > $max) {
            return null;
        }

        return ['field' => $field, 'value' => round((float) $value, 3)];
    }

    private function capabilityAllowsCommand(array $capabilities, string $command): bool
    {
        $capability = match ($command) {
            'open_fullscreen' => 'open_fullscreen',
            'open_panel' => 'open_panel',
            'focus_selection' => 'focus_selection',
            'edit_text' => 'edit_text',
            'set_style' => 'set_style',
            'replace_image' => 'replace_image',
            'add_block' => 'add_block',
            'undo' => 'undo',
            'redo' => 'redo',
            'preview' => 'preview',
            'restart_gif' => 'gif_preview',
            'set_animation' => 'animation',
            'save' => 'save',
            default => '',
        };

        return $capability !== '' && in_array($capability, $capabilities, true);
    }

    private function allowedFileId(mixed $value): ?int
    {
        $id = $this->positiveInt($value);
        $file = $id ? File::query()->find($id) : null;
        if (! $file || ! $this->marketingFiles->fileIsAllowed($file)) {
            return null;
        }

        try {
            $this->marketingFiles->validatedSnapshot($file);
        } catch (Throwable) {
            return null;
        }

        return $id;
    }

    /** @return list<string> */
    private function styleProperties(string $mode): array
    {
        return $mode === 'marketing' ? self::MARKETING_STYLE_PROPERTIES : self::MAIL_STYLE_PROPERTIES;
    }

    /** @return list<string> */
    private function blocks(string $mode): array
    {
        return $mode === 'marketing' ? self::MARKETING_BLOCKS : self::MAIL_BLOCKS;
    }

    private function scope(string $mode, mixed $value): ?string
    {
        $value = $this->string($value);
        $allowed = $mode === 'marketing' ? ['story', 'post', 'web'] : ['template', 'signature'];

        return in_array($value, $allowed, true) ? $value : null;
    }

    private function uuid(mixed $value): ?string
    {
        $value = strtolower($this->string($value));

        return Str::isUuid($value) ? $value : null;
    }

    private function nonce(mixed $value): ?string
    {
        $value = $this->string($value);

        return preg_match('/\A[a-zA-Z0-9_-]{22,96}\z/', $value) === 1 ? $value : null;
    }

    private function hash(mixed $value): ?string
    {
        $value = strtolower($this->string($value));

        return preg_match('/\A[a-f0-9]{64}\z/', $value) === 1 ? $value : null;
    }

    private function revision(mixed $value): ?int
    {
        $value = filter_var($value, FILTER_VALIDATE_INT);

        return is_int($value) && $value >= 0 && $value <= 2_147_483_647 ? $value : null;
    }

    private function positiveInt(mixed $value): ?int
    {
        $value = filter_var($value, FILTER_VALIDATE_INT);

        return is_int($value) && $value > 0 ? $value : null;
    }

    private function plainText(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim(str_replace(["\r\n", "\r"], "\n", $value));
        if ($value === '' || mb_strlen($value) > 1000 || preg_match('/[<>\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value)) {
            return null;
        }

        return $value;
    }

    private function boundedDisplayText(mixed $value, int $length): string
    {
        if (! is_string($value)) {
            return '';
        }

        return mb_substr(trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? ''), 0, $length);
    }

    /** @param list<string> $allowed @return list<string> */
    private function strings(mixed $values, array $allowed, int $limit): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_slice(array_unique(array_filter(
            array_map(fn (mixed $value): string => $this->string($value), $values),
            static fn (string $value): bool => in_array($value, $allowed, true),
        )), 0, $limit));
    }

    private function string(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    private function boolean(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (in_array($value, ['true', 1, '1'], true)) {
            return true;
        }
        if (in_array($value, ['false', 0, '0'], true)) {
            return false;
        }

        return null;
    }

    /** @return array{payload:array<string, mixed>,effect:null} */
    private function error(string $error, string $message): array
    {
        return ['payload' => ['error' => $error, 'message' => $message], 'effect' => null];
    }

    /** @return array<string, mixed> */
    private function emptySchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass, 'additionalProperties' => false];
    }

    /** @param array<string, mixed> $parameters @return array<string, mixed> */
    private function tool(string $name, string $description, array $parameters): array
    {
        return ['type' => 'function', 'function' => [
            'name' => $name,
            'description' => $description,
            'parameters' => $parameters,
        ]];
    }
}
