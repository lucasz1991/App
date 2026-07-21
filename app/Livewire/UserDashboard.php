<?php

namespace App\Livewire;

use App\Models\File;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UserDashboard extends Component
{
    public function mount(): void
    {
        if (auth()->user()->usesAdminLayout()) {
            $this->redirectRoute('admin.dashboard');
        }
    }

    /**
     * Download nur fuer Dateien, die dem Benutzer tatsaechlich bereitstehen.
     */
    public function downloadFile(int $fileId): StreamedResponse
    {
        abort_unless(in_array($fileId, auth()->user()->availableFileIds(), true), 403);

        $file = File::findOrFail($fileId);

        return $file->download($file->disk ?: 'private');
    }

    public function render()
    {
        $user = auth()->user();

        // Echte Daten: bereitgestellte Dateien + ungelesene Nachrichten
        $grouped = $user->availableFilesGrouped();
        $recentFiles = $grouped['personal']
            ->merge($grouped['company'])
            ->merge(collect($grouped['teams'])->flatMap(fn ($entry) => $entry['files']))
            ->unique('id')
            ->sortByDesc('created_at')
            ->take(6)
            ->values();

        $unreadMessages = $user->receivedMessages()->where('status', 1)->count();

        // ------------------------------------------------------------------
        // Beispiel-/Dummy-Daten fuer ein anschauliches Nutzer-Dashboard
        // (Schichten & Termine — spaeter durch echte Dienstplanung ersetzbar)
        // ------------------------------------------------------------------
        $shifts = [
            ['day' => 'Mo', 'date' => '21.07.', 'time' => '05:30 – 13:45', 'title' => 'Frühdienst · RB 48', 'route' => 'Köln Hbf → Wuppertal', 'role' => 'Zugbegleitung', 'tone' => 'sky'],
            ['day' => 'Di', 'date' => '22.07.', 'time' => '13:15 – 21:30', 'title' => 'Spätdienst · RE 7', 'route' => 'Krefeld → Rheine', 'role' => 'Zugbegleitung', 'tone' => 'amber'],
            ['day' => 'Mi', 'date' => '23.07.', 'time' => '06:00 – 14:10', 'title' => 'Frühdienst · S 11', 'route' => 'Düsseldorf → Bergisch Gladbach', 'role' => 'Kundenbetreuung', 'tone' => 'sky'],
            ['day' => 'Fr', 'date' => '25.07.', 'time' => 'frei', 'title' => 'Ruhetag', 'route' => '—', 'role' => '', 'tone' => 'slate'],
        ];

        $plans = [
            ['date' => '24.07.', 'title' => 'Sicherheitsunterweisung', 'meta' => 'Schulungsraum 2 · 09:00'],
            ['date' => '28.07.', 'title' => 'Teambesprechung', 'meta' => 'Online · 15:00'],
            ['date' => '02.08.', 'title' => 'Betriebsärztliche Untersuchung', 'meta' => 'Betriebsarzt · 11:30'],
        ];

        $nextShift = collect($shifts)->firstWhere('time', '!=', 'frei');

        // Neueste interne Nachrichten (Info fuer den Mitarbeiter)
        $latestMessages = $user->receivedMessages()
            ->with('sender:id,name,profile_photo_path')
            ->latest()
            ->limit(3)
            ->get();

        // Profil-Vollstaendigkeit: vollstaendige Kontaktdaten landen
        // automatisch in den E-Mail-Vorlagen (Profil-Tab).
        $profile = $user->profile;
        $profileChecks = [
            'phone' => filled($profile?->phone),
            'mobile' => filled($profile?->mobile),
            'position' => filled($profile?->position),
            'profile_photo' => filled($user->profile_photo_path),
        ];
        $profileCompletion = (int) round(
            100 * count(array_filter($profileChecks)) / count($profileChecks)
        );

        return view('livewire.user-dashboard', [
            'recentFiles' => $recentFiles,
            'unreadMessages' => $unreadMessages,
            'filesTotal' => $user->availableFileIds() ? count($user->availableFileIds()) : 0,
            'shifts' => $shifts,
            'plans' => $plans,
            'nextShift' => $nextShift,
            'latestMessages' => $latestMessages,
            'profileChecks' => $profileChecks,
            'profileCompletion' => $profileCompletion,
        ])->layout('layouts.master', ['area' => 'user']);
    }
}
