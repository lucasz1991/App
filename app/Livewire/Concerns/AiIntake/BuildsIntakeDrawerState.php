<?php

namespace App\Livewire\Concerns\AiIntake;

use App\Models\AiIntake;
use App\Models\Shift;
use App\Support\Operations\AiDispositionSettings;
use App\Support\Operations\AiIntakeSchema;
use App\Support\Operations\OperationsPages;

/**
 * Fußleiste der Formularbearbeitung: Haupt-, Neben- und weitere Aktionen.
 *
 * Teil von App\Livewire\Operations\AiIntakeInbox; Zustand (Properties), actor(),
 * scope(), selected() und perform() liegen in der Komponente.
 */
trait BuildsIntakeDrawerState
{
    private function legacyDrawerState(?AiIntake $record, bool $canApprove): array
    {
        $state = ['action' => '', 'label' => '', 'status' => 'Noch nicht übernommen', 'reason' => '',
            'disabled' => false, 'editing' => false, 'customerDirty' => false, 'stale' => false,
            'workPending' => false, 'hasDirectOrders' => false, 'canAdd' => false];
        if (! $record) {
            return $state;
        }
        $state['stale'] = $record->revision !== $this->selectedRevision
            || $record->source_revision !== $this->selectedSourceRevision
            || $this->selectedSettingsRevision !== (int) AiDispositionSettings::all(true)['revision'];
        $state['editing'] = $this->manualEditorOpen || $this->editingProposalId !== null;
        $state['customerDirty'] = $this->hasUnsavedAssignment($record);
        $blocked = $record->paused_at || in_array($record->status, ['paused', 'not_inquiry', 'completed'], true);
        $proposals = $record->proposals->where('source_revision', $record->source_revision)->where('status', '!=', 'stale');
        $state['hasDirectOrders'] = $proposals->contains(fn ($proposal) => ($proposal->payload['business_path'] ?? 'inquiry') === 'direct_order' && ! $proposal->inquiry?->order_id);
        $state['workPending'] = $record->processing_mode !== 'manual' || $state['hasDirectOrders']
            || $proposals->contains(fn ($proposal) => ! $proposal->inquiry || empty($proposal->payload['review_confirmation']['actor_id'])
                || ($proposal->payload['review_confirmation']['source_revision'] ?? null) !== $record->source_revision
                || $proposal->inquiry_revision !== $proposal->inquiry?->revision || $proposal->inquiry?->customer_id !== $record->customer_id);
        $state['canAdd'] = AiIntakeSchema::manualReady() && ! $blocked && ! $state['stale'] && ! $state['editing']
            && ! $state['customerDirty'] && $this->planningProposalId === null;
        if ($state['stale']) {
            return array_replace($state, ['action' => 'select('.$record->id.')', 'label' => 'Aktuellen Stand laden',
                'status' => 'Stand inzwischen geändert', 'reason' => 'Vor einer Änderung bitte den aktuellen Stand laden.']);
        }
        if ($this->detailTab !== 'review') {
            return array_replace($state, ['action' => "setDetailTab('review')", 'label' => 'Zur Bearbeitung',
                'status' => ($state['editing'] || $this->planningProposalId !== null) ? 'Entwurf in Bearbeitung'
                    : ($record->processing_mode === 'manual' ? 'Formularbearbeitung' : (self::LABELS[$record->status] ?? 'Prüfung nötig'))]);
        }
        if ($state['editing']) {
            return array_replace($state, ['action' => 'save-position', 'label' => 'Position speichern',
                'status' => 'Entwurf bearbeiten', 'reason' => 'Speichern erhält den Entwurf. Die Übernahme erfolgt erst danach.']);
        }
        if ($this->planningProposalId !== null) {
            return array_replace($state, ['action' => 'preparePlanning', 'label' => 'Schichtentwürfe erstellen',
                'status' => 'Schichten vorbereiten', 'reason' => 'Es werden unbesetzte, unveröffentlichte Entwürfe erstellt.']);
        }
        if ($this->customerEditorOpen || $state['customerDirty']) {
            return array_replace($state, ['action' => 'assignCustomer', 'label' => 'Zuordnung speichern',
                'status' => 'Kundenzuordnung bearbeiten']);
        }
        if ($blocked) {
            return array_replace($state, ['status' => self::LABELS[$record->status] ?? 'Prüfung nötig']);
        }
        if (! AiIntakeSchema::manualReady()) {
            return array_replace($state, ['action' => 'openAssist', 'label' => 'Mit AI-Assist öffnen',
                'reason' => 'Die Formularbearbeitung benötigt noch die Datenbankerweiterung.']);
        }
        if (! $record->customer_id) {
            return array_replace($state, ['action' => 'editCustomerAssignment', 'label' => 'Kunde zuordnen']);
        }
        if ($proposals->isEmpty()) {
            return array_replace($state, ['action' => 'addManualPosition', 'label' => 'Position per Formular erfassen']);
        }
        if ($proposals->contains(fn ($proposal) => ! in_array($proposal->status, ['applied', 'stale'], true)
            && (! $proposal->inquiry || in_array($proposal->inquiry->status, ['new', 'verified'], true))
            && ($proposal->status === 'failed' || $this->proposalNeedsCorrection($proposal->payload ?? [])))) {
            return array_replace($state, ['action' => 'reviewNextPosition', 'label' => 'Angaben ergänzen']);
        }
        if ($state['workPending']) {
            return array_replace($state, ['action' => 'confirmManual',
                'label' => $state['hasDirectOrders'] ? 'Bestätigen und Direktaufträge annehmen' : 'Positionen bestätigen und übernehmen',
                'reason' => 'Kunde, Positionen und Original persönlich prüfen.', 'disabled' => $state['hasDirectOrders'] && ! $canApprove]);
        }

        return array_replace($state, ['status' => 'Positionen übernommen', 'reason' => '']);
    }

    /** Native footer actions always belong to the explicitly displayed position. */
    private function drawerState(?AiIntake $record, bool $canApprove): array
    {
        $state = $this->legacyDrawerState($record, $canApprove) + [
            'activeProposalId' => null, 'positionEdit' => null, 'primary' => null, 'secondary' => null, 'more' => [], 'cancelAction' => null,
        ];
        if (! $record) {
            return $state;
        }
        $proposals = $record->proposals->where('source_revision', $record->source_revision)->where('status', '!=', 'stale')->sortBy('position_index');
        $proposal = $proposals->firstWhere('id', $this->drawerProposalId) ?? $proposals->first();
        $state['activeProposalId'] = $proposal?->id;
        $editing = $state['editing'] || $this->planningProposalId !== null || $this->customerEditorOpen || $state['customerDirty'];
        $blocked = (bool) $record->paused_at || in_array($record->status, ['paused', 'not_inquiry', 'completed'], true);
        $disabled = $editing || $state['stale'];
        $action = fn (string $key, string $label, ?string $command = null, ?string $href = null, bool $disable = false, ?string $confirm = null): array => [
            'key' => $key, 'label' => $label, 'action' => $command, 'href' => $href, 'disabled' => $disable, 'confirm' => $confirm,
        ];
        if ($record->status !== 'not_inquiry') {
            $state['more'][] = $action('assist', 'Mit AI-Assist öffnen', 'openAssist', disable: $disabled);
        }
        if ($record->deliveries->contains(fn ($delivery) => $delivery->status === 'draft' && ! ($delivery->metadata['manual_mailbox_test'] ?? false))) {
            $state['more'][] = $action('outbox', 'Kundennachrichten im AI-Assistenten prüfen', 'openOutbox', disable: $disabled);
        }
        if (! $blocked) {
            $state['more'][] = $action('customer', 'Kundenzuordnung ändern', 'editCustomerAssignment', disable: $disabled);
            if (AiIntakeSchema::manualReady()) {
                $state['more'][] = $action('add', 'Position per Formular erfassen', 'addManualPosition', disable: $disabled);
            }
            if (AiIntakeSchema::manualReady() && $proposal && ! in_array($proposal->status, ['applied', 'stale'], true)
                && (! $proposal->inquiry || in_array($proposal->inquiry->status, ['new', 'verified'], true))) {
                $state['positionEdit'] = $action('edit', 'Leistung bearbeiten', 'editProposal('.$proposal->id.')', disable: $disabled);
            }
            if ($proposals->count() > 1 && $state['workPending']) {
                $state['more'][] = $action('confirm-all', $state['hasDirectOrders'] ? 'Alle Positionen gemäß Übernahmeweg bestätigen' : 'Alle Positionen als Anfragen übernehmen', 'confirmManual',
                    disable: $disabled || ($state['hasDirectOrders'] && ! $canApprove),
                    confirm: $state['hasDirectOrders'] ? 'Alle aktuellen Positionen übernehmen und die als Direktauftrag markierten Leistungen als Auftrag annehmen?' : 'Alle aktuellen Positionen als Anfragen übernehmen?');
            }
            $state['more'][] = $action('pause', 'Pausieren', 'pause', disable: $disabled);
            $state['more'][] = $action('reanalyze', 'Erneut prüfen', 'reanalyze', disable: $disabled || $record->processing_mode === 'manual'
                || (! AiDispositionSettings::enabled() && $record->source_type !== 'email') || $record->status === 'analyzing');
        }
        if (! $record->customer_id && $canApprove && ! $this->customerId && ! $blocked) {
            $state['more'][] = $action('new-customer', 'Neuen Kunden prüfen und anlegen', 'prepareCustomer', disable: $state['stale'] || $state['editing'] || $this->planningProposalId !== null);
        }
        if ($proposal && $canApprove && $record->processing_mode !== 'manual' && in_array($proposal->status, ['approved', 'failed'], true)) {
            $state['more'][] = $action('retry', 'Vorbereitung erneut prüfen', 'retryPreparation('.$proposal->id.','.$proposal->revision.')', disable: $disabled || $blocked);
        }
        $state['more'][] = $action('refresh', 'Aktuellen Stand laden', 'select('.$record->id.')', disable: $editing);

        if ($state['stale']) {
            $state['primary'] = $action('refresh', 'Aktuellen Stand laden', 'select('.$record->id.')', disable: $editing);

            return $state;
        }
        if ($this->detailTab !== 'review') {
            $state['primary'] = $action('review', 'Zur Bearbeitung', "setDetailTab('review')");

            return $state;
        }
        if ($state['editing']) {
            $state['primary'] = $action('save-position', 'Position speichern', 'save-position');
            $state['cancelAction'] = 'cancelPositionEdit';

            return $state;
        }
        if ($this->planningProposalId !== null) {
            $state['primary'] = $action('prepare', 'Schichtentwürfe erstellen', 'preparePlanning');
            $state['cancelAction'] = 'closePlanning';

            return $state;
        }
        if ($this->customerEditorOpen || $state['customerDirty']) {
            $state['primary'] = $action('save-customer', 'Zuordnung speichern', 'assignCustomer');
            $state['cancelAction'] = 'cancelCustomerAssignment';

            return $state;
        }
        if ($blocked && ! ($record->status === 'completed' && $proposal?->inquiry?->order_id)) {
            if ($proposal?->inquiry) {
                $inquiry = $proposal->inquiry;
                $state['primary'] = $inquiry->order_id && $canApprove
                    ? $action('order', 'Auftragsplanung öffnen', href: OperationsPages::url('cases', ['view' => 'orders', 'section' => 'planning', 'order' => $inquiry->order_id]))
                    : $action('inquiry', 'Anfrage / Angebot öffnen', href: OperationsPages::url('cases', ['view' => 'inbox', 'inquiry' => $inquiry->id]));
                foreach (Shift::whereIn('id', $proposal->applied_shift_ids ?? [])->orderBy('starts_at')->get() as $shift) {
                    $state['more'][] = $action('shift-'.$shift->id, 'Schicht öffnen · '.$shift->starts_at->setTimezone($shift->timezone)->format('d.m. H:i'),
                        href: OperationsPages::url('cases', ['view' => 'shifts', 'section' => 'plan', 'shift' => $shift->id,
                            'from' => $shift->starts_at->setTimezone($shift->timezone)->format('Y-m-d'),
                            'until' => $shift->starts_at->setTimezone($shift->timezone)->addDays(6)->format('Y-m-d'), 'distribution' => 'true']));
                }
            }

            return $state;
        }
        if ((! AiIntakeSchema::manualReady() && ! $proposal?->inquiry) || ! $record->customer_id || ! $proposal) {
            $state['primary'] = $action('next', $state['label'], $state['action'] ?: null, disable: $state['disabled']);

            return $state;
        }
        $args = $proposal->id.','.$proposal->revision;
        if ($proposal->error_code === 'reply_position_review') {
            $state['primary'] = $action('mapping', 'Positionszuordnung bestätigen', 'mapProposal('.$args.')');

            return $state;
        }
        $inquiry = $proposal->inquiry;
        if (! $inquiry || in_array($inquiry->status, ['new', 'verified'], true)) {
            if ($proposal->status === 'failed' || $this->proposalNeedsCorrection($proposal->payload ?? [])) {
                $state['primary'] = $action('correct', 'Angaben ergänzen', 'editProposal('.$proposal->id.')');
            } elseif (! $inquiry || empty($proposal->payload['review_confirmation']['actor_id'])
                || ($proposal->payload['review_confirmation']['source_revision'] ?? null) !== $record->source_revision
                || $proposal->inquiry_revision !== $inquiry->revision || $inquiry->customer_id !== $record->customer_id) {
                $state['primary'] = $action('confirm-inquiry', 'Als Anfrage übernehmen', "confirmDrawerPosition('inquiry')");
                $state['secondary'] = $action('confirm-direct', 'Direktauftrag annehmen', "confirmDrawerPosition('direct_order')", disable: ! $canApprove);
            } else {
                $state['primary'] = $action('inquiry', 'Anfrage / Angebot öffnen', 'continueInquiry('.$args.')');
                $state['secondary'] = $action('direct', 'Direktauftrag annehmen', 'takeDirectOrder('.$args.','.$inquiry->revision.')', disable: ! $canApprove);
            }

            return $state;
        }
        if (! $inquiry->order_id) {
            $state['primary'] = $action('inquiry', $inquiry->status === 'accepted' ? 'Auftrag anlegen' : 'Anfrage / Angebot öffnen', 'continueInquiry('.$args.')');
            $state['secondary'] = $action('direct', 'Direktauftrag annehmen', 'takeDirectOrder('.$args.','.$inquiry->revision.')', disable: ! $canApprove);

            return $state;
        }
        $orderUrl = OperationsPages::url('cases', ['view' => 'orders', 'section' => 'planning', 'order' => $inquiry->order_id]);
        if ($canApprove) {
            $state['secondary'] = $action('order', 'Auftragsplanung öffnen', href: $orderUrl);
        }
        if ($proposal->status !== 'applied') {
            if ($canApprove) {
                $state['primary'] = $action('plan', 'Schichten vorbereiten', 'openPlanning('.$args.')');
            }

            return $state;
        }
        $shifts = Shift::whereIn('id', $proposal->applied_shift_ids ?? [])->orderBy('starts_at')->get();
        $allPublished = $shifts->isNotEmpty() && $shifts->every(fn ($shift) => $shift->published_revision === $shift->revision
            && ! in_array($shift->status->value, ['draft', 'cancelled', 'completed'], true));
        $shiftActions = $shifts->map(fn ($shift) => $action('shift-'.$shift->id,
            ($allPublished ? 'Besetzung öffnen' : 'Einsatzort und Entwurf prüfen').' · '.$shift->starts_at->setTimezone($shift->timezone)->format('d.m. H:i'),
            href: OperationsPages::url('cases', ['view' => 'shifts', 'section' => 'plan', 'shift' => $shift->id,
                'from' => $shift->starts_at->setTimezone($shift->timezone)->format('Y-m-d'),
                'until' => $shift->starts_at->setTimezone($shift->timezone)->addDays(6)->format('Y-m-d'), 'distribution' => 'true'])))->all();
        if ($allPublished) {
            $state['primary'] = $shiftActions[0] ?? null;
            if ($state['primary']) {
                $state['primary']['label'] = 'Besetzung öffnen';
            }
        } elseif ($shifts->isNotEmpty() && $canApprove) {
            $state['primary'] = $action('publish', 'Zur Besetzung freigeben', 'publishPlanning('.$args.')',
                confirm: 'Diese Schichten nach Prüfung von Zeiten, Pausen und Einsatzort zur Besetzung freigeben?');
            $state['secondary'] = $shiftActions[0];
            $state['secondary']['label'] = 'Schichtentwürfe prüfen';
            $state['more'][] = $action('order', 'Auftragsplanung öffnen', href: $orderUrl);
        }
        $state['more'] = array_merge($state['more'], $shiftActions);

        return $state;
    }
}
