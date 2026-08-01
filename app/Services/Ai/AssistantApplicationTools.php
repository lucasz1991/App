<?php

namespace App\Services\Ai;

use App\Models\User;
use App\Support\PageHelpCatalog;
use Carbon\CarbonImmutable;
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

        if ($this->isWagonRoute($currentRoute)) {
            $tools[] = $this->functionTool(
                self::WAGON_GUIDE_TOOL,
                'Steuert die lokale Wagenlistenführung. Verwende start zum Öffnen eines neuen Entwurfs, status zum Prüfen, next oder previous zum Wechseln, select_wagon zur Wagenwahl und save nur auf ausdrücklichen Speicherwunsch.',
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
                'Übernimmt genau einen vom Benutzer eindeutig genannten oder bestätigten Wert in den aktuellen lokalen Wagenlistenentwurf. Niemals raten, ergänzen oder mehrere Werte in einen Aufruf packen.',
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
                        'wagon_index' => [
                            'type' => 'integer',
                            'minimum' => 1,
                            'maximum' => 40,
                            'description' => 'Für Wagenfelder erforderlich; Wagennummer ab 1.',
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
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $wagonContext
     * @return array{payload:array<string, mixed>,effect:?array<string, mixed>}
     */
    public function execute(
        string $name,
        array $arguments,
        User $user,
        string $currentRoute,
        array $wagonContext = [],
    ): array {
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
            'calls' => $this->page('calls.index'),
            'meetings' => $this->page('meetings'),
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
                'employees' => $this->page('admin.employees'),
                'customers' => $this->page('admin.operations.preview', ['module' => 'customers']),
                'orders' => $this->page('admin.operations.preview', ['module' => 'orders']),
                'shift_management' => $this->page('admin.operations.preview', ['module' => 'shift-management']),
                'calendar' => $this->page('admin.operations.preview', ['module' => 'calendar']),
                'managed_documents' => $this->page('admin.managed-documents'),
                'mail_management' => $this->page('admin.mail-management'),
                'settings' => $this->page('admin.settings'),
            ];
        }

        return array_filter($pages, fn (array $page): bool => Route::has($page['route_name']));
    }

    /** @return array<int, string> */
    public function pageKeysFor(User $user): array
    {
        return array_keys($this->pagesFor($user));
    }

    /**
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $wagonContext
     * @return array{payload:array<string, mixed>,effect:null}
     */
    private function pageStatus(array $arguments, User $user, string $currentRoute, array $wagonContext): array
    {
        $pages = $this->pagesFor($user);
        $requested = trim((string) ($arguments['page'] ?? ''));
        $key = $requested !== '' ? $requested : $this->currentPageKey($pages, $currentRoute);

        if ($key === null || ! isset($pages[$key])) {
            return [
                'payload' => [
                    'state' => 'unknown',
                    'current_route' => $currentRoute,
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
        $key = trim((string) ($arguments['page'] ?? ''));

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
     * @param array<string, mixed> $wagonContext
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

        $action = trim((string) ($arguments['action'] ?? ''));
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
        if ($contextNonce === null) {
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
            if (! is_int($wagonIndex) || $wagonIndex < 1 || $wagonIndex > 40) {
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
     * @param array<string, mixed> $wagonContext
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

        $field = trim((string) ($arguments['field'] ?? ''));
        $fieldDefinition = $this->wagonFields()[$field] ?? null;
        if (! $fieldDefinition || ! array_key_exists('value', $arguments)) {
            return [
                'payload' => ['error' => 'invalid_field', 'message' => 'Dieses Wagenlistenfeld ist nicht freigegeben.'],
                'effect' => null,
            ];
        }

        $wagonIndex = null;
        if ($fieldDefinition['group'] === 'wagon') {
            $wagonIndex = filter_var($arguments['wagon_index'] ?? null, FILTER_VALIDATE_INT);
            $visible = max(1, min(40, (int) ($wagonContext['visible_wagons'] ?? 3)));
            if (! is_int($wagonIndex) || $wagonIndex < 1 || $wagonIndex > $visible) {
                return [
                    'payload' => ['error' => 'invalid_wagon', 'message' => 'Der gewählte Wagen ist im aktuellen Entwurf nicht sichtbar.'],
                    'effect' => null,
                ];
            }
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
            'train_number' => ['group' => 'meta', 'type' => 'text', 'max' => 80],
            'date' => ['group' => 'meta', 'type' => 'date'],
            'origin' => ['group' => 'meta', 'type' => 'text', 'max' => 120],
            'destination' => ['group' => 'meta', 'type' => 'text', 'max' => 120],
            'reference' => ['group' => 'meta', 'type' => 'text', 'max' => 120],
            'wagon_number' => ['group' => 'wagon', 'type' => 'wagon_number'],
            'category' => ['group' => 'wagon', 'type' => 'text', 'max' => 80],
            'axles_empty' => ['group' => 'wagon', 'type' => 'number'],
            'axles_loaded' => ['group' => 'wagon', 'type' => 'number'],
            'length' => ['group' => 'wagon', 'type' => 'number'],
            'wagon_weight' => ['group' => 'wagon', 'type' => 'number'],
            'load_weight' => ['group' => 'wagon', 'type' => 'number'],
            'brake_weight_g' => ['group' => 'wagon', 'type' => 'number'],
            'brake_weight_p' => ['group' => 'wagon', 'type' => 'number'],
            'shipping_station' => ['group' => 'wagon', 'type' => 'text', 'max' => 120],
            'destination_station' => ['group' => 'wagon', 'type' => 'text', 'max' => 120],
            'brake_type' => ['group' => 'wagon', 'type' => 'enum', 'values' => ['', 'G', 'P']],
            'disc_brake' => ['group' => 'wagon', 'type' => 'boolean'],
            'parking_brake' => ['group' => 'wagon', 'type' => 'number'],
            'max_speed' => ['group' => 'wagon', 'type' => 'number'],
            'remark' => ['group' => 'wagon', 'type' => 'text', 'max' => 500],
            'traction_weight' => ['group' => 'brake_sheet', 'type' => 'number'],
            'traction_brake_weight' => ['group' => 'brake_sheet', 'type' => 'number'],
            'traction_axles' => ['group' => 'brake_sheet', 'type' => 'number'],
            'minimum_brake_percentage' => ['group' => 'brake_sheet', 'type' => 'number'],
            'braked_axles' => ['group' => 'brake_sheet', 'type' => 'number'],
            'lower_vehicle_speed' => ['group' => 'brake_sheet', 'type' => 'number'],
            'nbuep_brake' => ['group' => 'brake_sheet', 'type' => 'enum', 'values' => ['', 'no', 'yes']],
            'emergency_brake_bridge' => ['group' => 'brake_sheet', 'type' => 'enum', 'values' => ['', 'no', 'yes']],
            'dangerous_goods' => ['group' => 'brake_sheet', 'type' => 'enum', 'values' => ['', 'no', 'yes']],
            'ep_brake' => ['group' => 'brake_sheet', 'type' => 'enum', 'values' => ['', 'no', 'yes']],
            'issuer_name' => ['group' => 'brake_sheet', 'type' => 'text', 'max' => 120],
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
            'boolean' => is_bool($value) ? $value : null,
            'enum' => in_array((string) $value, $definition['values'] ?? [], true) ? (string) $value : null,
            default => null,
        };
    }

    private function boundedText(mixed $value, int $maximum): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value));

        return $value !== '' && mb_strlen($value) <= $maximum ? $value : null;
    }

    private function dateValue(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $value)) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', $value)?->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    private function wagonNumberValue(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', (string) $value);

        return strlen((string) $digits) === 12 ? $digits : null;
    }

    private function numberValue(mixed $value): ?float
    {
        if (! is_int($value) && ! is_float($value) && ! is_string($value)) {
            return null;
        }

        $normalized = str_replace(',', '.', trim((string) $value));
        if ($normalized === '' || ! is_numeric($normalized)) {
            return null;
        }

        $number = (float) $normalized;

        return is_finite($number) && $number >= 0 && $number <= 999999 ? $number : null;
    }

    /** @param array<string, mixed> $wagonContext */
    private function safeWagonContext(array $wagonContext): array
    {
        return [
            'context_nonce' => $this->contextNonce($wagonContext) ?? '',
            'editor_open' => (bool) ($wagonContext['editor_open'] ?? false),
            'active_step' => mb_substr(trim((string) ($wagonContext['active_step'] ?? 'overview')), 0, 40),
            'selected_wagon' => max(1, min(40, (int) ($wagonContext['selected_wagon'] ?? 1))),
            'visible_wagons' => max(1, min(40, (int) ($wagonContext['visible_wagons'] ?? 3))),
            'completed_wagons' => max(0, min(40, (int) ($wagonContext['completed_wagons'] ?? 0))),
            'train_number_present' => (bool) ($wagonContext['train_number_present'] ?? false),
            'origin_present' => (bool) ($wagonContext['origin_present'] ?? false),
            'destination_present' => (bool) ($wagonContext['destination_present'] ?? false),
            'current_wagon_fields' => array_values(array_slice(array_filter(
                (array) ($wagonContext['current_wagon_fields'] ?? []),
                fn (mixed $field): bool => is_string($field) && isset($this->wagonFields()[$field]),
            ), 0, 24)),
        ];
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
        $nonce = trim((string) ($wagonContext['context_nonce'] ?? ''));

        return preg_match('/\A[a-zA-Z0-9_-]{16,96}\z/', $nonce) ? $nonce : null;
    }

    /** @param array<string, string> $parameters */
    private function page(string $routeName, array $parameters = []): array
    {
        return ['route_name' => $routeName, 'parameters' => $parameters];
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
        return $currentRoute === $page['route_name'];
    }

    private function isWagonRoute(string $route): bool
    {
        return in_array($route, self::WAGON_ROUTES, true);
    }
}
