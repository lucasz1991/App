<?php

namespace App\Livewire\Operations;

use Livewire\Component;

class WagonListPrototype extends Component
{
    public function mount(): void
    {
        $user = auth()->user();

        abort_unless($user && in_array($user->dashboardAudience(), [
            'admin',
            'administration',
            'management',
            'employee',
        ], true), 403);

    }

    public function render()
    {
        $user = auth()->user();
        $labels = app()->getLocale() === 'de'
            ? [
                'overviewTitle' => 'Bisherige Eintragungen',
                'overviewDescription' => 'Lokale Entwürfe auf diesem Gerät öffnen, fortsetzen oder bewusst löschen.',
                'newDraft' => 'Wagenliste ausfüllen',
                'newDraftDescription' => 'Neue Wagen- und Bremsdaten in einer ablenkungsfreien Vollbildansicht erfassen.',
                'emptyTitle' => 'Noch keine Wagenliste angelegt',
                'emptyText' => 'Beginne eine neue Wagenliste. Deine Eingaben werden während der Bearbeitung automatisch auf diesem Gerät gespeichert.',
                'continueDraft' => 'Fortsetzen',
                'deleteDraft' => 'Entwurf löschen',
                'deleteAll' => 'Alle Entwürfe löschen',
                'deleteTitle' => 'Entwurf wirklich löschen?',
                'deleteText' => 'Diese lokal gespeicherte Wagenliste kann danach nicht wiederhergestellt werden.',
                'deleteConfirm' => 'Entwurf löschen',
                'deleteAllTitle' => 'Alle Entwürfe löschen?',
                'deleteAllText' => 'Alle lokal gespeicherten Wagenlisten auf diesem Gerät werden endgültig entfernt.',
                'deleteAllConfirm' => 'Alle löschen',
                'saveAndClose' => 'Speichern & schließen',
                'saveDraft' => 'Jetzt speichern',
                'draftSaved' => 'Entwurf lokal gespeichert',
                'saveError' => 'Der Entwurf konnte in diesem Browser nicht gespeichert werden.',
                'closeEditor' => 'Vollbildansicht schließen',
                'closeHint' => 'Schließen und Abbrechen behalten den aktuellen Entwurf.',
                'localDraft' => 'Lokaler Entwurf',
                'trainLabel' => 'Zug',
                'untitledDraft' => 'Wagenliste ohne Zugnummer',
                'noRoute' => 'Laufweg noch nicht eingetragen',
                'createdAt' => 'Angelegt',
                'updatedAt' => 'Zuletzt bearbeitet',
                'filledWagons' => 'ausgefüllte Wagen',
                'drafts' => 'Entwürfe',
            ]
            : [
                'overviewTitle' => 'Previous entries',
                'overviewDescription' => 'Open, continue or deliberately delete local drafts stored on this device.',
                'newDraft' => 'Complete wagon list',
                'newDraftDescription' => 'Capture new wagon and brake data in a focused full-screen view.',
                'emptyTitle' => 'No wagon list yet',
                'emptyText' => 'Start a new wagon list. Your entries are saved automatically on this device while you work.',
                'continueDraft' => 'Continue',
                'deleteDraft' => 'Delete draft',
                'deleteAll' => 'Delete all drafts',
                'deleteTitle' => 'Delete this draft?',
                'deleteText' => 'This locally stored wagon list cannot be recovered afterwards.',
                'deleteConfirm' => 'Delete draft',
                'deleteAllTitle' => 'Delete all drafts?',
                'deleteAllText' => 'All wagon lists stored locally on this device will be removed permanently.',
                'deleteAllConfirm' => 'Delete all',
                'saveAndClose' => 'Save & close',
                'saveDraft' => 'Save now',
                'draftSaved' => 'Draft saved locally',
                'saveError' => 'The draft could not be saved in this browser.',
                'closeEditor' => 'Close full-screen view',
                'closeHint' => 'Closing or cancelling keeps the current draft.',
                'localDraft' => 'Local draft',
                'trainLabel' => 'Train',
                'untitledDraft' => 'Wagon list without train number',
                'noRoute' => 'Route not entered yet',
                'createdAt' => 'Created',
                'updatedAt' => 'Last edited',
                'filledWagons' => 'completed wagons',
                'drafts' => 'Drafts',
            ];

        return view('livewire.operations.wagon-list-prototype', [
            'storageKey' => sprintf('rt-wagon-list-prototype:v1:%d', $user->id),
            'labels' => $labels,
        ])->layout('layouts.master', ['area' => $user->usesAdminLayout() ? 'admin' : 'user']);
    }
}
