<?php

namespace App\Services\Ai;

use App\Models\User;
use App\Support\PageHelpCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

final class AssistantApplicationTools
{
    public const PAGE_STATUS_TOOL = 'get_railtime_page_status';

    public const OPEN_PAGE_TOOL = 'open_railtime_page';

    public const WAGON_GUIDE_TOOL = 'guide_wagon_list';

    public const WAGON_FIELD_TOOL = 'set_wagon_list_field';

    private const WAGON_ROUTES = ['operations.wagon-list', 'admin.operations.wagon-list'];

    /** @return array<int, array<string, mixed>> */
    public function toolDefinitions(User $user, string $currentRoute): array
    {
        $pages = $this->pagesFor($user);
        $pageKeys = array_keys($pages);
        $tools = [
            $this->functionTool(
                self::PAGE_STATUS_TOOL,
                'Ermittelt den sicheren redaktionellen Status der aktuellen oder einer freigegebenen RailTime-Seite. Liefert keine Live-Betriebs- oder Personendaten.',
                [
                    'type' => 'object',
                    'properties' => [
                        'page' => [
                            'type' => 'string',
                            'enum' => $pageKeys,
                            'description' => 'Optionaler stabiler Seitenschlüssel. Ohne Angabe wird die aktuelle Seite beschrieben.',
                        ],
                    ],
                    'additionalProperties' => false,
                ],
            ),
            $this->functionTool(
                self::OPEN_PAGE_TOOL,
                'Öffnet auf ausdrücklichen Wunsch des Benutzers eine serverseitig freigegebene RailTime-Seite. Niemals freie oder externe URLs verwenden.',
                [
                    'type' => 'object',
                    'properties' => [
                        'page' => [
                            'type' => 'string',
                            'enum' => $pageKeys,
                            'description' => 'Stabiler Schlüssel des gewünschten RailTime-Bereichs.',
                        ],
                    ],
                    'required' => ['page'],
                    'additionalProperties' => false,
                ],
            ),
        ];

        if ($this->canUseWagonTools($user, $currentRoute)) {
            $tools[] = $this->functionTool(
                self::WAGON_GUIDE_TOOL,
                'Steuert die lokale Wagenlistenführung. Verwende start zum Öffnen eines neuen Entwurfs und für den ersten Schritt, status zum Prüfen, next oder previous zum Wechseln, select_wagon zur Wagenwahl und save nur auf ausdrücklichen Speicherwunsch.',
                [
                    'type' => 'object',
                    'properties' => [
                        'action' => [
                            'type' => 'string',
                            'enum' => ['status', 'start', 'next', 'previous', 'select_wagon', 'save'],
                        ],
                        'wagon_index' => [
                            'type' => 'integer',
                            'minimum' => 1,
                            'maximum' => 40,
                            'description' => 'Nur bei select_wagon: sichtbare Wagennummer ab 1.',
                        ],
                    ],
                    'required' => ['action'],
                    'additionalProperties' => false,
                ],
            );
            $tools[] = $this->functionTool(
                self::WAGON_FIELD_TOOL,
                'Übernimmt genau einen vom Benutzer eindeutig genannten oder bestätigten Wert in den aktuellen lokalen Wagenlistenentwurf. Wagenfelder gelten ausschließlich für den aktuell ausgewählten Wagen. Niemals raten, ergänzen oder mehrere Werte in einen Aufruf packen.',
                [
                    'type' => 'object',
                    'properties' => [
                        'field' => [
                            'type' => 'string',
                            'enum' => array_keys($this->wagonFields()),
                        ],
                        'value' => [
                            'description' => 'Der ausdrücklich genannte Wert. Je nach Feld Text, Zahl oder boolescher Wert.',
                        ],
                    ],
                    'required' => ['field', 'value'],
                    'additionalProperties' => false,
                ],
            );
        }

        return $tools;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $wagonContext
     * @return array{payload:array<string, mixed>,effect:?array<string, mixed>}
     */
    public function execute(
        string $name,
        array $arguments,
        User $user,
        string $currentRoute,
        array $wagonContext = [],
    ): array {
        if (! in_array($name, $this->allowedToolNames($user, $currentRoute), true)) {
            return [
                'payload' => ['error' => 'unknown_tool', 'message' => 'Dieses Tool ist nicht freigegeben.'],
                'effect' => null,
            ];
        }

        return match ($name) {
            self::PAGE_STATUS_TOOL => $this->pageStatus($arguments, $user, $currentRoute, $wagonContext),
            self::OPEN_PAGE_TOOL => $this->openPage($arguments, $user, $currentRoute),
            self::WAGON_GUIDE_TOOL => $this->guideWagonList($arguments, $currentRoute, $wagonContext),
            self::WAGON_FIELD_TOOL => $this->setWagonField($arguments, $currentRoute, $wagonContext),
            default => [
                'payload' => ['error' => 'unknown_tool', 'message' => 'Dieses Tool ist nicht freigegeben.'],
                'effect' => null,
            ],
        };
    }

    /** @return array<string, array<string, mixed>> */
    public function pagesFor(User $user): array
    {
        $admin = $user->usesAdminLayout();
        $audience = $user->dashboardAudience();
        $pages = [
            'dashboard' => $this->page($admin ? 'admin.dashboard' : 'dashboard'),
            'messages' => $this->page($admin ? 'admin.messages' : 'messages'),
            'chat' => $this->page('chat'),
            'files' => $this->page($admin ? 'admin.files' : 'files'),
            'calls' => $this->page('calls.index', ability: 'calls.join'),
            'meetings' => $this->page('meetings', ability: 'calls.join'),
            'email_templates' => $this->page('email-templates.index'),
            'help' => $this->page('help'),
            'support' => $this->page('support'),
            'profile' => $this->page('profile.show'),
        ];

        if (in_array($audience, ['admin', 'administration', 'management', 'employee'], true)) {
            $pages['wagon_list'] = $this->page($admin ? 'admin.operations.wagon-list' : 'operations.wagon-list');
        }

        if ($admin) {
            $pages += [
                'employees' => $this->page('admin.employees', ability: 'employees.view'),
                'customers' => $this->page('admin.operations.preview', ['module' => 'customers']),
                'orders' => $this->page('admin.operations.preview', ['module' => 'orders']),
                'shift_management' => $this->page('admin.operations.preview', ['module' => 'shift-management']),
                'calendar' => $this->page('admin.operations.preview', ['module' => 'calendar']),
                'managed_documents' => $this->page('admin.managed-documents', ability: 'files.manage'),
                'mail_management' => $this->page('admin.mail-management', ability: 'manage.messages'),
                'settings' => $this->page('admin.settings', ability: 'settings.manage'),
            ];
        }

        return array_filter($pages, fn (array $page): bool => $this->canAccessPage($user, $page));
    }

    /** @return array<int, string> */
    public function pageKeysFor(User $user): array
    {
        return array_keys($this->pagesFor($user));
    }

    /**
     * Rebuild every browser effect from server-owned allowlists before it is
     * persisted or dispatched. Provider supplied URLs and arbitrary form paths
     * are intentionally ignored.
     *
     * @param  array<string, mixed>  $effect
     * @return array<string, mixed>|null
     */
    public function normalizeBrowserEffect(User $user, string $currentRoute, array $effect): ?array
    {
        $type = $this->argumentString($effect['type'] ?? null);

        if ($type === 'navigate') {
            $result = $this->openPage(
                ['page' => $this->argumentString($effect['page'] ?? null)],
                $user,
                $currentRoute,
            );

            return is_array($result['effect']) ? $result['effect'] : null;
        }

        if ($type !== 'wagon_list' || ! $this->canUseWagonTools($user, $currentRoute)) {
            return null;
        }

        $command = $this->argumentString($effect['command'] ?? null);
        $contextNonce = $this->contextNonce($effect);
        if ($contextNonce === null || ! in_array($command, [
            'start', 'next', 'previous', 'select_wagon', 'save', 'set_field',
        ], true)) {
            return null;
        }

        $normalized = [
            'type' => 'wagon_list',
            'command' => $command,
            'context_nonce' => $contextNonce,
        ];

        if ($command === 'select_wagon') {
            $wagonIndex = filter_var($effect['wagon_index'] ?? null, FILTER_VALIDATE_INT);
            if (! is_int($wagonIndex) || $wagonIndex < 1 || $wagonIndex > 40) {
                return null;
            }

            $normalized['wagon_index'] = $wagonIndex;
        }

        if ($command === 'set_field') {
            $field = $this->argumentString($effect['field'] ?? null);
            $definition = $this->wagonFields()[$field] ?? null;
            if ($definition === null || ! array_key_exists('value', $effect)) {
                return null;
            }

            $value = $this->normalizeWagonValue($effect['value'], $definition);
            if ($value === null) {
                return null;
            }

            $normalized['field'] = $field;
            $normalized['value'] = $value;

            if ($definition['group'] === 'wagon') {
                $wagonIndex = filter_var($effect['wagon_index'] ?? null, FILTER_VALIDATE_INT);
                if (! is_int($wagonIndex) || $wagonIndex < 1 || $wagonIndex > 40) {
                    return null;
                }

                $normalized['wagon_index'] = $wagonIndex;
            }
        }

        return $normalized;
    }

    /**
     * Validate a normalized browser effect against the latest value-free form
     * context before a pending action can become executable.
     *
     * @param  array<string, mixed>  $effect
     * @param  array<string, mixed>  $wagonContext
     */
    public function browserEffectMatchesContext(
        User $user,
        string $currentRoute,
        array $effect,
        array $wagonContext,
    ): bool {
        $effect = $this->normalizeBrowserEffect($user, $currentRoute, $effect);
        if ($effect === null) {
            return false;
        }

        if (($effect['type'] ?? null) !== 'wagon_list') {
            return true;
        }

        $effectNonce = $this->contextNonce($effect);
        $currentNonce = $this->contextNonce($wagonContext);
        if ($effectNonce === null || $currentNonce === null || ! hash_equals($currentNonce, $effectNonce)) {
            return false;
        }

        $command = $this->argumentString($effect['command'] ?? null);
        $editorOpen = (bool) ($wagonContext['editor_open'] ?? false);
        if ($command !== 'start' && ! $editorOpen) {
            return false;
        }

        if ($command === 'select_wagon') {
            $wagonIndex = filter_var($effect['wagon_index'] ?? null, FILTER_VALIDATE_INT);
            $visibleWagons = filter_var($wagonContext['visible_wagons'] ?? null, FILTER_VALIDATE_INT);

            return is_int($wagonIndex)
                && is_int($visibleWagons)
                && $visibleWagons >= 1
                && $visibleWagons <= 40
                && $wagonIndex >= 1
                && $wagonIndex <= $visibleWagons;
        }

        if ($command === 'set_field') {
            $field = $this->argumentString($effect['field'] ?? null);
            $definition = $this->wagonFields()[$field] ?? null;
            if ($definition === null) {
                return false;
            }

            if ($definition['group'] === 'wagon') {
                $expectedWagon = filter_var($effect['wagon_index'] ?? null, FILTER_VALIDATE_INT);
                $currentWagon = filter_var($wagonContext['current_wagon_index'] ?? null, FILTER_VALIDATE_INT);

                return is_int($expectedWagon)
                    && is_int($currentWagon)
                    && $expectedWagon === $currentWagon;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $wagonContext
     * @return array{payload:array<string, mixed>,effect:null}
     */
    private function pageStatus(array $arguments, User $user, string $currentRoute, array $wagonContext): array
    {
        $pages = $this->pagesFor($user);
        $requested = $this->argumentString($arguments['page'] ?? null);
        $key = $requested !== '' ? $requested : $this->currentPageKey($pages, $currentRoute);

        if ($key === null || ! isset($pages[$key])) {
            return [
                'payload' => [
                    'state' => 'unknown',
                    'message' => 'Die aktuelle Seite ist keinem freigegebenen Assistenten-Ziel zugeordnet.',
                ],
                'effect' => null,
            ];
        }

        $page = $pages[$key];
        $help = app(PageHelpCatalog::class)->forRoute($page['route_name']);
        $payload = [
            'state' => 'available',
            'page' => $key,
            'title' => $help['title'],
            'summary' => $help['summary'],
            'route_name' => $page['route_name'],
            'current' => $this->routeMatchesPage($currentRoute, $page),
            'navigation_available' => true,
            'live_data_available' => false,
        ];

        if ($key === 'wagon_list' && $payload['current']) {
            $payload['wagon_form'] = $this->safeWagonContext($wagonContext);
        }

        return ['payload' => $payload, 'effect' => null];
    }

    /** @return array{payload:array<string, mixed>,effect:?array<string, mixed>} */
    private function openPage(array $arguments, User $user, string $currentRoute): array
    {
        $pages = $this->pagesFor($user);
        $key = $this->argumentString($arguments['page'] ?? null);

        if ($key === '' || ! isset($pages[$key])) {
            return [
                'payload' => ['error' => 'page_not_allowed', 'message' => 'Dieses RailTime-Ziel ist nicht freigegeben.'],
                'effect' => null,
            ];
        }

        $page = $pages[$key];
        $help = app(PageHelpCatalog::class)->forRoute($page['route_name']);

        if ($this->routeMatchesPage($currentRoute, $page)) {
            return [
                'payload' => [
                    'state' => 'already_open',
                    'page' => $key,
                    'title' => $help['title'],
                    'message' => 'Die gewünschte Seite ist bereits geöffnet.',
                ],
                'effect' => null,
            ];
        }

        $effect = [
            'type' => 'navigate',
            'page' => $key,
            'label' => $help['title'],
            'url' => route($page['route_name'], $page['parameters'], false),
        ];

        return [
            'payload' => [
                'state' => 'scheduled',
                'page' => $key,
                'title' => $help['title'],
                'message' => 'Die Navigation wurde für den Browser vorbereitet und ist noch nicht als abgeschlossen zu behaupten.',
            ],
            'effect' => $effect,
        ];
    }

    /**
     * @param  array<string, mixed>  $wagonContext
     * @return array{payload:array<string, mixed>,effect:?array<string, mixed>}
     */
    private function guideWagonList(array $arguments, string $currentRoute, array $wagonContext): array
    {
        if (! $this->isWagonRoute($currentRoute)) {
            return [
                'payload' => ['error' => 'wrong_page', 'message' => 'Die Wagenlistenführung ist nur auf der Wagenlistenseite verfügbar.'],
                'effect' => null,
            ];
        }

        $action = $this->argumentString($arguments['action'] ?? null);
        if (! in_array($action, ['status', 'start', 'next', 'previous', 'select_wagon', 'save'], true)) {
            return [
                'payload' => ['error' => 'invalid_action', 'message' => 'Diese Wagenlistenaktion ist nicht freigegeben.'],
                'effect' => null,
            ];
        }

        if ($action === 'status') {
            return [
                'payload' => ['state' => 'ready', 'wagon_form' => $this->safeWagonContext($wagonContext)],
                'effect' => null,
            ];
        }

        $contextNonce = $this->contextNonce($wagonContext);
        if (
            $contextNonce === null
            || ($action !== 'start' && ! (bool) ($wagonContext['editor_open'] ?? false))
        ) {
            return [
                'payload' => ['error' => 'context_unavailable', 'message' => 'Der sichere Wagenlistenstatus ist noch nicht bereit.'],
                'effect' => null,
            ];
        }

        $effect = [
            'type' => 'wagon_list',
            'command' => $action,
            'context_nonce' => $contextNonce,
        ];
        if ($action === 'select_wagon') {
            $wagonIndex = filter_var($arguments['wagon_index'] ?? null, FILTER_VALIDATE_INT);
            $visible = max(1, min(40, (int) ($wagonContext['visible_wagons'] ?? 1)));
            if (! is_int($wagonIndex) || $wagonIndex < 1 || $wagonIndex > $visible) {
                return [
                    'payload' => ['error' => 'invalid_wagon', 'message' => 'Bitte einen Wagen zwischen 1 und 40 angeben.'],
                    'effect' => null,
                ];
            }
            $effect['wagon_index'] = $wagonIndex;
        }

        return [
            'payload' => [
                'state' => 'scheduled',
                'action' => $action,
                'message' => 'Die lokale Wagenlistenaktion wurde vorbereitet und muss vom Browser bestätigt werden.',
            ],
            'effect' => $effect,
        ];
    }

    /**
     * @param  array<string, mixed>  $wagonContext
     * @return array{payload:array<string, mixed>,effect:?array<string, mixed>}
     */
    private function setWagonField(array $arguments, string $currentRoute, array $wagonContext): array
    {
        if (! $this->isWagonRoute($currentRoute)) {
            return [
                'payload' => ['error' => 'wrong_page', 'message' => 'Wagenlistenfelder können nur auf der Wagenlistenseite gesetzt werden.'],
                'effect' => null,
            ];
        }

        if (! (bool) ($wagonContext['editor_open'] ?? false)) {
            return [
                'payload' => ['error' => 'editor_closed', 'message' => 'Zuerst muss ein Wagenlistenentwurf geöffnet werden.'],
                'effect' => null,
            ];
        }

        $field = $this->argumentString($arguments['field'] ?? null);
        $fieldDefinition = $this->wagonFields()[$field] ?? null;
        if (! $fieldDefinition || ! array_key_exists('value', $arguments)) {
            return [
                'payload' => ['error' => 'invalid_field', 'message' => 'Dieses Wagenlistenfeld ist nicht freigegeben.'],
                'effect' => null,
            ];
        }

        $value = $this->normalizeWagonValue($arguments['value'], $fieldDefinition);
        if ($value === null) {
            return [
                'payload' => ['error' => 'invalid_value', 'message' => 'Der Wert passt nicht zum ausgewählten Wagenlistenfeld.'],
                'effect' => null,
            ];
        }

        $contextNonce = $this->contextNonce($wagonContext);
        if ($contextNonce === null) {
            return [
                'payload' => ['error' => 'context_unavailable', 'message' => 'Der sichere Wagenlistenstatus ist noch nicht bereit.'],
                'effect' => null,
            ];
        }

        $wagonIndex = null;
        if ($fieldDefinition['group'] === 'wagon') {
            $visibleWagons = filter_var($wagonContext['visible_wagons'] ?? null, FILTER_VALIDATE_INT);
            $wagonIndex = filter_var($wagonContext['current_wagon_index'] ?? null, FILTER_VALIDATE_INT);
            if (
                ! is_int($visibleWagons)
                || ! is_int($wagonIndex)
                || $visibleWagons < 1
                || $visibleWagons > 40
                || $wagonIndex < 1
                || $wagonIndex > $visibleWagons
            ) {
                return [
                    'payload' => ['error' => 'context_unavailable', 'message' => 'Der ausgewählte Wagen ist nicht mehr eindeutig.'],
                    'effect' => null,
                ];
            }
        }

        $effect = [
            'type' => 'wagon_list',
            'command' => 'set_field',
            'context_nonce' => $contextNonce,
            'field' => $field,
            'value' => $value,
        ];
        if ($wagonIndex !== null) {
            $effect['wagon_index'] = $wagonIndex;
        }

        return [
            'payload' => [
                'state' => 'scheduled',
                'field' => $field,
                'wagon_index' => $wagonIndex,
                'message' => 'Der ausdrücklich genannte Wert wurde zur lokalen Übernahme vorbereitet. Die Browserbestätigung steht noch aus.',
            ],
            'effect' => $effect,
        ];
    }

    /** @return array<string, array{group:string,type:string,max?:int,values?:array<int, string>}> */
    private function wagonFields(): array
    {
        return [
            'meta.trainNumber' => ['group' => 'meta', 'type' => 'text', 'max' => 80],
            'meta.date' => ['group' => 'meta', 'type' => 'date'],
            'meta.origin' => ['group' => 'meta', 'type' => 'text', 'max' => 120],
            'meta.destination' => ['group' => 'meta', 'type' => 'text', 'max' => 120],
            'meta.reference' => ['group' => 'meta', 'type' => 'text', 'max' => 160],
            'wagon.number' => ['group' => 'wagon', 'type' => 'wagon_number'],
            'wagon.category' => ['group' => 'wagon', 'type' => 'text', 'max' => 80],
            'wagon.axlesEmpty' => ['group' => 'wagon', 'type' => 'number'],
            'wagon.axlesLoaded' => ['group' => 'wagon', 'type' => 'number'],
            'wagon.length' => ['group' => 'wagon', 'type' => 'number'],
            'wagon.wagonWeight' => ['group' => 'wagon', 'type' => 'number'],
            'wagon.loadWeight' => ['group' => 'wagon', 'type' => 'number'],
            'wagon.brakeG' => ['group' => 'wagon', 'type' => 'number'],
            'wagon.brakeP' => ['group' => 'wagon', 'type' => 'number'],
            'wagon.shippingStation' => ['group' => 'wagon', 'type' => 'text', 'max' => 120],
            'wagon.destinationStation' => ['group' => 'wagon', 'type' => 'text', 'max' => 120],
            'wagon.brakeType' => ['group' => 'wagon', 'type' => 'enum', 'values' => ['', 'K', 'L', 'LL']],
            'wagon.discBrake' => ['group' => 'wagon', 'type' => 'boolean'],
            'wagon.parkingBrake' => ['group' => 'wagon', 'type' => 'number'],
            'wagon.maxSpeed' => ['group' => 'wagon', 'type' => 'number'],
            'wagon.remark' => ['group' => 'wagon', 'type' => 'text', 'max' => 255],
            'brakeSheet.tractionWeight' => ['group' => 'brake_sheet', 'type' => 'number'],
            'brakeSheet.tractionBrakeWeight' => ['group' => 'brake_sheet', 'type' => 'number'],
            'brakeSheet.tractionAxles' => ['group' => 'brake_sheet', 'type' => 'number'],
            'brakeSheet.minimumBrakePercentage' => ['group' => 'brake_sheet', 'type' => 'number'],
            'brakeSheet.brakedAxles' => ['group' => 'brake_sheet', 'type' => 'number'],
            'brakeSheet.lowerVehicleSpeed' => ['group' => 'brake_sheet', 'type' => 'number'],
            'brakeSheet.nbuepBrake' => ['group' => 'brake_sheet', 'type' => 'yes_no'],
            'brakeSheet.emergencyBrakeBridge' => ['group' => 'brake_sheet', 'type' => 'yes_no'],
            'brakeSheet.passengerFeatureHzee' => ['group' => 'brake_sheet', 'type' => 'yes_no'],
            'brakeSheet.passengerFeatureNOe' => ['group' => 'brake_sheet', 'type' => 'yes_no'],
            'brakeSheet.passengerFeatureTb0' => ['group' => 'brake_sheet', 'type' => 'yes_no'],
            'brakeSheet.passengerFeatureOZub' => ['group' => 'brake_sheet', 'type' => 'yes_no'],
            'brakeSheet.passengerFeatureOther' => ['group' => 'brake_sheet', 'type' => 'yes_no'],
            'brakeSheet.dangerousGoods' => ['group' => 'brake_sheet', 'type' => 'yes_no'],
            'brakeSheet.epBrake' => ['group' => 'brake_sheet', 'type' => 'yes_no'],
            'brakeSheet.issuerName' => ['group' => 'brake_sheet', 'type' => 'text', 'max' => 120],
        ];
    }

    /** @param array{group:string,type:string,max?:int,values?:array<int, string>} $definition */
    private function normalizeWagonValue(mixed $value, array $definition): string|float|bool|null
    {
        return match ($definition['type']) {
            'text' => $this->boundedText($value, (int) ($definition['max'] ?? 120)),
            'date' => $this->dateValue($value),
            'wagon_number' => $this->wagonNumberValue($value),
            'number' => $this->numberValue($value),
            'boolean' => $this->booleanValue($value),
            'enum' => $this->enumValue($value, $definition['values'] ?? []),
            'yes_no' => $this->yesNoValue($value),
            default => null,
        };
    }

    private function boundedText(mixed $value, int $maximum): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return mb_strlen($value) <= $maximum ? $value : null;
    }

    private function dateValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (! preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $value)) {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);

            return $date?->format('Y-m-d') === $value ? $value : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function wagonNumberValue(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $source = trim((string) $value);
        if ($source === '') {
            return '';
        }

        if (! preg_match('/\A[0-9\s-]{1,32}\z/', $source)) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $source);

        return strlen((string) $digits) === 12 ? $digits : null;
    }

    private function numberValue(mixed $value): string|float|null
    {
        if (! is_int($value) && ! is_float($value) && ! is_string($value)) {
            return null;
        }

        $normalized = str_replace(',', '.', trim((string) $value));
        if ($normalized === '') {
            return '';
        }

        if (! preg_match('/\A(?:\d{1,6}(?:\.\d{1,3})?|\.\d{1,3})\z/', $normalized)) {
            return null;
        }

        $number = (float) $normalized;

        return is_finite($number) && $number >= 0 && $number <= 999999 ? $number : null;
    }

    private function booleanValue(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $normalized = is_string($value) ? mb_strtolower(trim($value)) : $value;

        return match ($normalized) {
            1, '1', 'true', 'yes', 'ja' => true,
            0, '0', 'false', 'no', 'nein' => false,
            default => null,
        };
    }

    /** @param array<int, string> $values */
    private function enumValue(mixed $value, array $values): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = mb_strtoupper(trim($value));

        return in_array($normalized, $values, true) ? $normalized : null;
    }

    private function yesNoValue(mixed $value): ?string
    {
        if (is_string($value) && trim($value) === '') {
            return '';
        }

        $boolean = $this->booleanValue($value);

        return $boolean === null ? null : ($boolean ? 'yes' : 'no');
    }

    /** @param array<string, mixed> $wagonContext */
    private function safeWagonContext(array $wagonContext): array
    {
        $editorOpen = (bool) ($wagonContext['editor_open'] ?? false);
        $visibleWagons = $editorOpen
            ? max(1, min(40, (int) ($wagonContext['visible_wagons'] ?? 1)))
            : 0;
        $currentWagon = $editorOpen
            ? max(1, min($visibleWagons, (int) ($wagonContext['current_wagon_index'] ?? 1)))
            : 0;
        $steps = ['train', 'identity', 'vehicle', 'brakes', 'route', 'calculation', 'special', 'review'];
        $currentStep = $this->argumentString($wagonContext['current_step'] ?? null);
        if (! in_array($currentStep, $steps, true)) {
            $currentStep = $editorOpen ? 'train' : '';
        }

        $fields = $this->wagonFields();
        $metaFields = array_keys(array_filter($fields, fn (array $field): bool => $field['group'] === 'meta'));
        $wagonFields = array_keys(array_filter($fields, fn (array $field): bool => $field['group'] === 'wagon'));
        $brakeSheetFields = array_keys(array_filter($fields, fn (array $field): bool => $field['group'] === 'brake_sheet'));
        $presence = is_array($wagonContext['presence'] ?? null) ? $wagonContext['presence'] : [];

        return [
            'editor_open' => $editorOpen,
            'current_step' => $currentStep,
            'current_step_index' => $editorOpen
                ? max(0, min(7, (int) ($wagonContext['current_step_index'] ?? array_search($currentStep, $steps, true))))
                : null,
            'current_wagon_index' => $currentWagon,
            'visible_wagons' => $visibleWagons,
            'filled_wagons' => max(0, min($visibleWagons, (int) ($wagonContext['filled_wagons'] ?? 0))),
            'present_meta_fields' => $this->presentFields($presence['meta'] ?? [], $metaFields),
            'present_current_wagon_fields' => $this->presentFields(
                $wagonContext['current_wagon_fields'] ?? [],
                $wagonFields,
            ),
            'present_brake_sheet_fields' => $this->presentFields(
                $presence['brake_sheet'] ?? [],
                $brakeSheetFields,
            ),
        ];
    }

    /**
     * @param  array<int, string>  $allowedFields
     * @return array<int, string>
     */
    private function presentFields(mixed $presence, array $allowedFields): array
    {
        if (! is_array($presence)) {
            return [];
        }

        return array_values(array_filter(
            $allowedFields,
            static fn (string $field): bool => ($presence[$field] ?? false) === true,
        ));
    }

    /** @param array<string, mixed> $parameters */
    private function functionTool(string $name, string $description, array $parameters): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $name,
                'description' => $description,
                'parameters' => $parameters,
            ],
        ];
    }

    /** @param array<string, mixed> $wagonContext */
    private function contextNonce(array $wagonContext): ?string
    {
        $nonce = $this->argumentString($wagonContext['context_nonce'] ?? null);

        return preg_match('/\A[a-zA-Z0-9_-]{16,96}\z/', $nonce) ? $nonce : null;
    }

    /** @param array<string, string> $parameters */
    private function page(string $routeName, array $parameters = [], ?string $ability = null): array
    {
        return [
            'route_name' => $routeName,
            'parameters' => $parameters,
            'ability' => $ability,
        ];
    }

    /** @param array<string, mixed> $page */
    private function canAccessPage(User $user, array $page): bool
    {
        if (! Route::has((string) ($page['route_name'] ?? ''))) {
            return false;
        }

        $ability = $page['ability'] ?? null;

        return ! is_string($ability)
            || $ability === ''
            || Gate::forUser($user)->allows($ability);
    }

    /** @return array<int, string> */
    private function allowedToolNames(User $user, string $currentRoute): array
    {
        $names = [self::PAGE_STATUS_TOOL, self::OPEN_PAGE_TOOL];

        if ($this->canUseWagonTools($user, $currentRoute)) {
            $names[] = self::WAGON_GUIDE_TOOL;
            $names[] = self::WAGON_FIELD_TOOL;
        }

        return $names;
    }

    private function canUseWagonTools(User $user, string $currentRoute): bool
    {
        $wagonPage = $this->pagesFor($user)['wagon_list'] ?? null;

        return is_array($wagonPage)
            && $this->isWagonRoute($currentRoute)
            && $this->routeMatchesPage($currentRoute, $wagonPage);
    }

    /** @param array<string, array<string, mixed>> $pages */
    private function currentPageKey(array $pages, string $currentRoute): ?string
    {
        foreach ($pages as $key => $page) {
            if ($this->routeMatchesPage($currentRoute, $page)) {
                return $key;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $page */
    private function routeMatchesPage(string $currentRoute, array $page): bool
    {
        return ($page['parameters'] ?? []) === []
            && $currentRoute === $page['route_name'];
    }

    private function isWagonRoute(string $route): bool
    {
        return in_array($route, self::WAGON_ROUTES, true);
    }

    private function argumentString(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }
}
