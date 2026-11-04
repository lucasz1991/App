@extends('layouts.master')

@section('title', 'U2e · Zulassung und Rücklesen')

@section('content')
    <x-ui.page title="U2e · Zulassung und Rücklesen" eyebrow="E-Mail-Vorlagen"
        description="Aktuelle Zulassung und die letzten Diagnoseereignisse je Postfach."
        :back-url="route('admin.mail-documents.editor')" back-label="Zu den Vorlagen" :auto-intro="false" content-class="space-y-5">
        <x-ui.surface.card class="p-5 sm:p-6">
            <form method="get" action="{{ route('admin.mail-documents.u2e-draft') }}" class="flex flex-wrap items-end gap-3">
                <input type="hidden" name="status" value="1">
                <label class="grid min-w-0 flex-1 gap-2 text-sm font-semibold text-rt-text dark:text-rt-dark-text" for="u2e-mailbox">
                    Postfach
                    <select id="u2e-mailbox" name="user" class="min-h-11 w-full rounded-xl border border-rt-border bg-rt-surface px-3 text-sm dark:border-rt-dark-border dark:bg-rt-dark-surface">
                        @foreach ($users as $user)
                            <option value="{{ $user->id }}" @selected($user->id === $subject->id)>{{ $user->name }} · {{ $user->email }}</option>
                        @endforeach
                    </select>
                </label>
                <x-ui.buttons.button-basic type="submit" mode="primary" class="min-h-11">Status prüfen</x-ui.buttons.button-basic>
            </form>
            <div class="mt-5 space-y-2" role="status" data-u2e-admission="{{ $admission['status'] }}">
                <h2 class="text-lg font-semibold text-rt-text dark:text-rt-dark-text">
                    @if ($admission['status'] === 'admitted')
                        U2e zugelassen
                    @else
                        Rückfall V17 ({{ \App\Support\OutlookAddin\OutlookUniversalDiagnostics::reasonLabel($admission['reason']) }})
                    @endif
                </h2>
                <p class="text-sm text-rt-muted dark:text-rt-dark-muted">{{ $subject->email }} · <code>{{ $admission['reason'] }}</code></p>
                @if ($admission['reason'] === 'outside_pilot')
                    <p class="text-sm text-rt-muted dark:text-rt-dark-muted">Die U2e-Ansicht dient hier der Prüfung. Outlook erhält für dieses Postfach den bisherigen Pfad.</p>
                @endif
                <p class="text-sm text-rt-muted dark:text-rt-dark-muted">Quellprüfung: {{ \App\Support\OutlookAddin\OutlookUniversalDiagnostics::reasonLabel($reviewAdmission['reason']) }} · <code>{{ $reviewAdmission['reason'] }}</code></p>
            </div>
            @if ($previewAvailable)
                <div class="mt-4 flex flex-wrap gap-3">
                    @foreach (['editor' => 'Editorfassung ansehen', 'send' => 'Versandfassung ansehen'] as $previewMode => $label)
                        <a class="text-sm font-semibold text-rt-red underline underline-offset-4 dark:text-rt-dark-accent" target="_blank" rel="noopener"
                            href="{{ route('admin.mail-documents.u2e-draft', array_filter(['user' => $subject->id, 'template' => $templateId, 'mode' => $previewMode])) }}">{{ $label }}</a>
                    @endforeach
                </div>
            @endif
        </x-ui.surface.card>

        <x-ui.surface.card class="space-y-4 p-5 sm:p-6">
            <h2 class="text-base font-semibold text-rt-text dark:text-rt-dark-text">Letzte Nichtzulassung</h2>
            @if ($failure = $history['lastAdmissionFailure'] ?? null)
                <p class="text-sm text-rt-muted dark:text-rt-dark-muted">{{ $failure['at'] }} · {{ \App\Support\OutlookAddin\OutlookUniversalDiagnostics::reasonLabel($failure['reason']) }}</p>
                <p class="break-words text-xs text-rt-muted dark:text-rt-dark-muted"><code>{{ $failure['reason'] }} · {{ $failure['code'] }} · {{ $failure['phase'] }} · {{ $failure['host'] }} · Quelle {{ $failure['sourceLength'] ?? '–' }} / Ausgabe {{ $failure['outputLength'] ?? '–' }} UTF-16</code></p>
            @else
                <p class="text-sm text-rt-muted dark:text-rt-dark-muted">Keine Nichtzulassung in den letzten sieben Tagen gespeichert.</p>
            @endif
        </x-ui.surface.card>

        <x-ui.surface.card class="space-y-4 p-5 sm:p-6">
            <h2 class="text-base font-semibold text-rt-text dark:text-rt-dark-text">Letzte Rücklese- und Automatikdiagnosen</h2>
            <p class="text-sm text-rt-muted dark:text-rt-dark-muted">Codes, Host, Phase, Prüfpunkt und sichere Vergleichswerte als Zahlen oder Hash mit Länge. Ein erfolgreicher Vorschauaufruf löscht diese Ereignisse nicht.</p>
            <ul class="space-y-3" data-u2e-readback-errors>
                @forelse ($history['readbackErrors'] ?? [] as $failure)
                    <li class="break-words text-sm text-rt-text dark:text-rt-dark-text">
                        <span>{{ $failure['at'] }} · {{ $failure['host'] }}</span><br>
                        <code class="text-xs">{{ $failure['code'] }} · {{ $failure['phase'] }} · Quelle {{ $failure['sourceLength'] ?? '–' }} / Rücklesen {{ $failure['bodyLength'] ?? '–' }} UTF-16</code>
                        @if (isset($failure['originalCode']))
                            <br><code class="text-xs">Originalcode: {{ $failure['originalCode'] }} · gemeldet: {{ $failure['code'] }}</code>
                        @endif
                        @if (isset($failure['context']) || isset($failure['composeType']) || isset($failure['step']))
                            <br><code class="text-xs">Kontext: {{ $failure['context'] ?? '–' }} · Verfassen: {{ $failure['composeType'] ?? '–' }} · Schritt: {{ $failure['step'] ?? '–' }}</code>
                        @endif
                        @if (isset($failure['bodyLengthOrigin']))
                            <br><code class="text-xs">Länge aus: {{ $failure['bodyLengthOrigin'] === 'actual' ? 'Rücklesen nach Schreiben' : 'Lesung vor Schreiben' }}</code>
                        @endif
                        @if (isset($failure['automaticReason']))
                            <br><code class="text-xs">U2e-Automatik übersprungen: {{ $failure['automaticReason'] }}</code>
                        @endif
                        @if (isset($failure['checkpoint']))
                            <br><code class="text-xs">Prüfpunkt: {{ $failure['checkpoint'] }}</code>
                        @endif
                        @if (isset($failure['difference']))
                            <br><code class="text-xs">{{ $failure['difference']['key'] }}{{ $failure['difference']['index'] === null ? '' : '['.$failure['difference']['index'].']' }} · erwartet {{ json_encode($failure['difference']['expected']) }} / gelesen {{ json_encode($failure['difference']['actual']) }}</code>
                        @endif
                        @if (isset($failure['fixtureId']))
                            <br><a class="text-sm font-semibold text-rt-red underline underline-offset-4 dark:text-rt-dark-accent" href="{{ route('admin.mail-documents.u2e-fixture', ['user' => $subject->id, 'fixture' => $failure['fixtureId']]) }}">Redigiertes Vergleichspaar herunterladen</a>
                        @endif
                    </li>
                @empty
                    <li class="text-sm text-rt-muted dark:text-rt-dark-muted">Keine Rücklesefehler in den letzten sieben Tagen gespeichert. Das ersetzt keine Outlook-Probe.</li>
                @endforelse
            </ul>
        </x-ui.surface.card>
        <x-ui.surface.card class="space-y-4 p-5 sm:p-6">
            <h2 class="text-base font-semibold text-rt-text dark:text-rt-dark-text">Redigierte Compose-Fixtures · nur Pilot</h2>
            <p class="text-sm text-rt-muted dark:text-rt-dark-muted">Erwartete und während des Verfassens gelesene Signatur, ohne Nachrichtentext oder Zitat. Höchstens drei private Vergleichspaare, jeweils sieben Tage. Der Download wird als JSON-Anhang ausgegeben.</p>
            <ul class="space-y-3">
                @forelse ($fixtures ?? [] as $fixture)
                    <li class="text-sm text-rt-text dark:text-rt-dark-text">
                        <a class="font-semibold text-rt-red underline underline-offset-4 dark:text-rt-dark-accent" href="{{ route('admin.mail-documents.u2e-fixture', ['user' => $subject->id, 'fixture' => $fixture['id']]) }}">Vergleichspaar {{ $fixture['at'] }}</a>
                        <span class="text-rt-muted dark:text-rt-dark-muted"> · {{ $fixture['bytes'] }} Byte · verfügbar bis {{ $fixture['expiresAt'] }}</span>
                    </li>
                @empty
                    <li class="text-sm text-rt-muted dark:text-rt-dark-muted">Keine redigierte Compose-Fixture gespeichert. Empfangs-HTML ist kein Ersatz für die Rücklese-Fixture aus der Verfassen-Ansicht.</li>
                @endforelse
            </ul>
        </x-ui.surface.card>
    </x-ui.page>
@endsection
