<?php

namespace App\Livewire;

use App\Support\PageHelpCatalog;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Component;

class HelpCenter extends Component
{
    public string $query = '';

    public function render(PageHelpCatalog $catalog): View
    {
        $user = auth()->user();
        $query = Str::lower(trim($this->query));
        $topics = collect($catalog->forUser($user))
            ->map(function (array $topic): array {
                $topic['href'] = match ($topic['route']) {
                    'admin.operations.preview' => route('admin.operations.preview', ['module' => 'orders']),
                    null => route('help'),
                    default => route($topic['route']),
                };

                return $topic;
            })
            ->filter(function (array $topic) use ($query): bool {
                if ($query === '') {
                    return true;
                }

                return Str::contains(
                    Str::lower(implode(' ', [
                        $topic['title'],
                        $topic['summary'],
                        $topic['group'],
                        implode(' ', $topic['points']),
                    ])),
                    $query,
                );
            })
            ->values();

        return view('livewire.help-center', [
            'topics' => $topics,
            'topicGroups' => $topics->groupBy('group'),
            'faqs' => $this->faqs(),
        ])->layout('layouts.master', [
            'area' => $user->usesAdminLayout() ? 'admin' : 'user',
        ]);
    }

    /**
     * @return array<int, array{question:string, answer:string}>
     */
    private function faqs(): array
    {
        if (app()->getLocale() !== 'de') {
            return [
                ['question' => 'Why am I not receiving push notifications?', 'answer' => 'Install RailTime on the device, allow notifications in Profile > Settings and check the notification permissions of the operating system.'],
                ['question' => 'Where can I continue a wagon list?', 'answer' => 'Existing drafts appear first on the wagon list page. Open a draft to continue exactly where you stopped.'],
                ['question' => 'How do I move a file?', 'answer' => 'Drag the file card onto a folder or onto one of the breadcrumbs above the file grid.'],
                ['question' => 'How do I contact support?', 'answer' => 'Open Support from My area and include the steps, expected result and, if possible, a screenshot.'],
            ];
        }

        return [
            [
                'question' => 'Warum erhalte ich keine Push-Benachrichtigungen?',
                'answer' => 'Installieren Sie RailTime auf dem Gerät, erlauben Sie Benachrichtigungen unter Profil > Einstellungen und prüfen Sie zusätzlich die Mitteilungsrechte des Betriebssystems.',
            ],
            [
                'question' => 'Wo kann ich eine Wagenliste fortsetzen?',
                'answer' => 'Auf der Wagenlistenseite stehen vorhandene Entwürfe zuerst. Öffnen Sie einen Entwurf, um exakt an der letzten Stelle weiterzuarbeiten.',
            ],
            [
                'question' => 'Wie verschiebe ich eine Datei?',
                'answer' => 'Ziehen Sie die Dateikarte auf einen Ordner oder auf einen der Brotkrümel oberhalb des Dateirasters.',
            ],
            [
                'question' => 'Wie erreiche ich den Support?',
                'answer' => 'Öffnen Sie unter Mein Bereich den Support und nennen Sie Ablauf, erwartetes Ergebnis und möglichst einen Screenshot.',
            ],
        ];
    }
}
