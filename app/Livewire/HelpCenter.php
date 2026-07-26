<?php

namespace App\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Component;

class HelpCenter extends Component
{
    public string $query = '';

    public function render(): View
    {
        $user = auth()->user();
        $query = Str::lower(trim($this->query));
        $topics = collect($this->topics())
            ->filter(function (array $topic) use ($query): bool {
                if ($query === '') {
                    return true;
                }

                return Str::contains(
                    Str::lower(implode(' ', [
                        $topic['title'],
                        $topic['description'],
                        implode(' ', $topic['keywords']),
                    ])),
                    $query,
                );
            })
            ->values();

        return view('livewire.help-center', [
            'topics' => $topics,
        ])->layout('layouts.master', [
            'area' => $user->usesAdminLayout() ? 'admin' : 'user',
        ]);
    }

    /**
     * @return array<int, array{
     *     title: string,
     *     description: string,
     *     icon: string,
     *     href: string,
     *     keywords: array<int, string>
     * }>
     */
    private function topics(): array
    {
        $topics = [
            [
                'title' => __('app.help_topic_profile_title'),
                'description' => __('app.help_topic_profile_description'),
                'icon' => 'user',
                'href' => route('profile.show'),
                'keywords' => ['profil', 'passwort', 'sicherheit', 'profile', 'password'],
            ],
            [
                'title' => __('app.help_topic_messages_title'),
                'description' => __('app.help_topic_messages_description'),
                'icon' => 'message-square',
                'href' => route('messages'),
                'keywords' => ['nachrichten', 'chat', 'message', 'kommunikation'],
            ],
            [
                'title' => __('app.help_topic_files_title'),
                'description' => __('app.help_topic_files_description'),
                'icon' => 'folder',
                'href' => auth()->user()->usesAdminLayout() ? route('admin.files') : route('files'),
                'keywords' => ['dateien', 'download', 'ordner', 'files'],
            ],
        ];

        if (in_array(
            auth()->user()->dashboardAudience(),
            ['admin', 'employee', 'management', 'administration'],
            true,
        )) {
            $topics[] = [
                'title' => __('app.help_topic_wagon_title'),
                'description' => __('app.help_topic_wagon_description'),
                'icon' => 'clipboard',
                'href' => auth()->user()->usesAdminLayout()
                    ? route('admin.operations.wagon-list')
                    : route('operations.wagon-list'),
                'keywords' => ['wagenliste', 'warenliste', 'entwurf', 'export', 'wagon'],
            ];
        }

        return $topics;
    }
}
