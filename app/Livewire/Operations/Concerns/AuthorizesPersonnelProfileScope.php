<?php

namespace App\Livewire\Operations\Concerns;

/**
 * PersonnelEnhancements – Einbettung im Mitarbeiterprofil: Dort ist die Komponente an
 * genau einen Mitarbeiter und einen Reiter gebunden und lässt nur dessen Aktionen zu.
 */
trait AuthorizesPersonnelProfileScope
{
    private function authorizeProfileRecord($record): void
    {
        abort_if($this->profileUserId !== null && (int) $record->user_id !== $this->profileUserId, 403);
    }

    private function authorizeProfileAction(string $kind): void
    {
        if ($this->profileUserId === null) {
            return;
        }

        $allowed = match ($this->profileTab) {
            'workflows' => ['workflow_run', 'task_complete'],
            'documents' => ['signature_request', 'signature_review', 'signature_download', 'document_download'],
            'emergency' => ['emergency_read'],
            'development' => ['development', 'feedback'],
            'sickness' => ['sickness', 'sickness_update'],
            default => [],
        };
        abort_unless(in_array($kind, $allowed, true), 403);
    }
}
