<?php

namespace App\Livewire\Tools;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Livewire\Component;

class GlobalSearch extends Component
{
    public string $query = '';

    public bool $showResults = false;

    public function mount(): void
    {
        abort_unless(auth()->check(), 403);
    }

    public function updatedQuery(string $value): void
    {
        if (mb_strlen($value) > 80) {
            $this->query = mb_substr($value, 0, 80);
        }
    }

    public function openResults(?string $query = null): void
    {
        if ($query !== null) {
            $this->query = mb_substr($query, 0, 80);
        }

        $this->query = Str::squish($this->query);
        $this->showResults = true;
    }

    public function closeResults(): void
    {
        $this->showResults = false;
    }

    public function clearSearch(): void
    {
        $this->reset('query', 'showResults');
    }

    /**
     * Nur Ergebnisse erzeugen, deren Ziel der aktuelle Benutzer auch direkt
     * aufrufen darf. Modellabfragen bleiben immer auf ihn bzw. seine Gates
     * begrenzt.
     *
     * @return array<int, array{group: string, title: string, description: string, url: string, icon: string}>
     */
    private function results(): array
    {
        $needle = mb_strtolower(Str::squish($this->query));

        if (mb_strlen($needle) < 2) {
            return [];
        }

        /** @var User $user */
        $user = auth()->user();

        return [
            ...$this->navigationResults($user, $needle),
            ...$this->messageResults($user, $needle),
            ...$this->chatResults($user, $needle),
            ...$this->employeeResults($user, $needle),
        ];
    }

    /**
     * @return array<int, array{group: string, title: string, description: string, url: string, icon: string}>
     */
    private function navigationResults(User $user, string $needle): array
    {
        $isAdminLayout = $user->usesAdminLayout();
        $items = [
            [
                'title' => __('app.dashboard'),
                'description' => __('app.overview'),
                'url' => route($isAdminLayout ? 'admin.dashboard' : 'dashboard'),
                'icon' => 'home',
                'keywords' => 'start übersicht overview home kpi',
                'allowed' => true,
            ],
            [
                'title' => __('app.chat'),
                'description' => __('app.chat_and_messages'),
                'url' => route('chat'),
                'icon' => 'message-circle',
                'keywords' => 'unterhaltung conversation kommunikation',
                'allowed' => true,
            ],
            [
                'title' => __('app.messages'),
                'description' => __('app.chat_and_messages'),
                'url' => route($isAdminLayout ? 'admin.messages' : 'messages'),
                'icon' => 'mail',
                'keywords' => 'posteingang inbox nachricht',
                'allowed' => true,
            ],
            [
                'title' => __('app.profile'),
                'description' => __('app.profile_and_support'),
                'url' => route('profile.show'),
                'icon' => 'user',
                'keywords' => 'konto account passwort push app',
                'allowed' => true,
            ],
            [
                'title' => __('app.it_support'),
                'description' => __('app.profile_and_support'),
                'url' => route('support'),
                'icon' => 'life-buoy',
                'keywords' => 'support problem ticket',
                'allowed' => true,
            ],
            [
                'title' => __('app.help'),
                'description' => __('app.profile_and_support'),
                'url' => Route::has('help') ? route('help') : route('support'),
                'icon' => 'help-circle',
                'keywords' => 'hilfe anleitung app installieren installation ios android push pwa',
                'allowed' => Route::has('help'),
            ],
            [
                'title' => __('app.email_templates'),
                'description' => __('app.files_and_templates'),
                'url' => route('email-templates.index'),
                'icon' => 'file-text',
                'keywords' => 'vorlagen signatur templates',
                'allowed' => true,
            ],
            [
                'title' => $isAdminLayout ? __('app.download_files') : __('app.download_center'),
                'description' => __('app.files_and_templates'),
                'url' => route($isAdminLayout ? 'admin.files' : 'files'),
                'icon' => 'folder',
                'keywords' => 'datei dokument download ordner files',
                'allowed' => ! $isAdminLayout || Gate::allows('files.manage'),
            ],
            [
                'title' => __('app.employees'),
                'description' => __('app.management'),
                'url' => route($isAdminLayout ? 'admin.employees' : 'employees.index'),
                'icon' => 'users',
                'keywords' => 'mitarbeiter benutzer personal team users',
                'allowed' => Gate::allows('employees.view'),
            ],
            [
                'title' => __('app.wagon_list'),
                'description' => __('app.operations'),
                'url' => route($isAdminLayout ? 'admin.operations.wagon-list' : 'operations.wagon-list'),
                'icon' => 'list',
                'keywords' => 'wagen güterzug betrieb erfassen export',
                'allowed' => $isAdminLayout
                    || in_array($user->dashboardAudience(), ['employee', 'management', 'administration'], true),
            ],
            [
                'title' => __('app.settings'),
                'description' => __('app.company'),
                'url' => $isAdminLayout ? route('admin.settings') : route('profile.show'),
                'icon' => 'settings',
                'keywords' => 'einstellungen firma system settings',
                'allowed' => $isAdminLayout && Gate::allows('settings.manage'),
            ],
            [
                'title' => __('app.mail_management'),
                'description' => __('app.management'),
                'url' => $isAdminLayout ? route('admin.mail-management') : route('messages'),
                'icon' => 'send',
                'keywords' => 'mail email versand protokoll',
                'allowed' => $isAdminLayout && Gate::allows('manage.messages'),
            ],
        ];

        return collect($items)
            ->filter(fn (array $item): bool => $item['allowed'])
            ->filter(function (array $item) use ($needle): bool {
                $haystack = mb_strtolower(implode(' ', [
                    $item['title'],
                    $item['description'],
                    $item['keywords'],
                ]));

                return str_contains($haystack, $needle);
            })
            ->take(6)
            ->map(fn (array $item): array => [
                'group' => __('app.navigation'),
                'title' => $item['title'],
                'description' => $item['description'],
                'url' => $item['url'],
                'icon' => $item['icon'],
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{group: string, title: string, description: string, url: string, icon: string}>
     */
    private function messageResults(User $user, string $needle): array
    {
        $route = $user->usesAdminLayout() ? 'admin.messages' : 'messages';

        return $user->receivedMessages()
            ->with('sender:id,name')
            ->latest()
            ->limit(200)
            ->get()
            ->filter(function ($message) use ($needle): bool {
                try {
                    $haystack = mb_strtolower(implode(' ', [
                        $message->subject,
                        $message->sender?->name,
                        strip_tags($message->message),
                    ]));

                    return str_contains($haystack, $needle);
                } catch (\Throwable) {
                    return false;
                }
            })
            ->take(5)
            ->map(fn ($message): array => [
                'group' => __('app.messages'),
                'title' => (string) $message->subject,
                'description' => trim(implode(' · ', array_filter([
                    $message->sender?->name,
                    $message->created_at?->translatedFormat('d.m.Y H:i'),
                ]))),
                'url' => route($route, ['open' => $message->id]),
                'icon' => 'mail',
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{group: string, title: string, description: string, url: string, icon: string}>
     */
    private function chatResults(User $user, string $needle): array
    {
        return $user->chats()
            ->with('participants:id,name,profile_photo_path')
            ->where('chats.type', '!=', 'call')
            ->latest('chats.updated_at')
            ->limit(100)
            ->get()
            ->filter(fn ($chat): bool => str_contains(
                mb_strtolower($chat->displayNameFor($user)),
                $needle,
            ))
            ->take(5)
            ->map(fn ($chat): array => [
                'group' => __('app.chat'),
                'title' => $chat->displayNameFor($user),
                'description' => $chat->isGroup() ? __('app.group_chat') : __('app.direct_chat'),
                'url' => route('chat', ['chat' => $chat->id]),
                'icon' => 'message-circle',
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{group: string, title: string, description: string, url: string, icon: string}>
     */
    private function employeeResults(User $user, string $needle): array
    {
        if (! Gate::allows('employees.view')) {
            return [];
        }

        $route = $user->usesAdminLayout() ? 'admin.user-profile' : 'employees.show';

        return User::query()
            ->whereKeyNot(1)
            ->whereIn('role', ['admin', 'staff'])
            ->orderBy('name')
            ->limit(250)
            ->get(['id', 'name', 'email'])
            ->filter(fn (User $employee): bool => str_contains(
                mb_strtolower($employee->name.' '.$employee->email),
                $needle,
            ))
            ->take(5)
            ->map(fn (User $employee): array => [
                'group' => __('app.employees'),
                'title' => $employee->name,
                'description' => $employee->email,
                'url' => route($route, ['userId' => $employee->id]),
                'icon' => 'user',
            ])
            ->all();
    }

    public function render()
    {
        return view('livewire.tools.global-search', [
            'results' => $this->showResults ? $this->results() : [],
            'queryReady' => mb_strlen(Str::squish($this->query)) >= 2,
        ]);
    }
}
