<dl class="employee-profile__status-group" @if($phoneStatus !== null) data-profile-phone-status aria-label="Gerätestatus" wire:poll.visible.60s="refreshStatus" @else hidden @endif>
    @if($phoneStatus !== null)
        @foreach($phoneStatus['rows'] as $statusKey => $status)
            <div class="employee-profile__status-item" wire:key="{{ $profilePrefix }}-status-phone-{{ $statusKey }}" data-phone-status="{{ $statusKey }}" data-status="{{ $status['state'] }}">
                <dt>
                    @include('livewire.admin.user-profile.partials.status-indicator', [
                        'indicatorKey' => 'phone-'.$statusKey,
                        'indicatorLabel' => $status['label'],
                        'indicatorValue' => $status['value'],
                        'indicatorIcon' => $status['icon'],
                        'indicatorState' => $status['state'],
                        'indicatorNote' => $statusKey === 'installation' ? $phoneStatus['device_label'] : null,
                        'indicatorEvidence' => $statusKey === 'installation' ? $phoneStatus['evidence_note'].' '.($status['evidence'] ?? '') : ($status['evidence'] ?? null),
                        'indicatorPhoneCount' => $statusKey === 'installation' ? $phoneStatus['assigned_phone_count'] : null,
                    ])
                </dt>
                <dd class="sr-only">{{ $status['value'] }}</dd>
            </div>
        @endforeach
    @endif
</dl>
