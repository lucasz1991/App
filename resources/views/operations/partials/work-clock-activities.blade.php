@php($clockKinds = \Illuminate\Support\Arr::except(\App\Services\Operations\WorkTimeActivityService::KINDS, ['break']))
<div class="rt-clock-activities" wire:ignore x-data="rtWorkClockActivities(@js($clockKinds))" x-id="['activity-dialog-title', 'activity-search']"
    x-effect="if (active?.status !== 'running' && $refs.activityDialog?.open) $refs.activityDialog.close()">
    <p class="rt-clock-activities__resume" x-show="active?.status === 'paused' && labels[active?.previous_kind]" x-cloak>
        Weiter mit <strong x-text="labels[active?.previous_kind]"></strong>
    </p>
    <div x-show="active?.status === 'running'" x-cloak>
        <span class="rt-clock-activities__caption">Aktuelle Tätigkeit</span>
        <div class="rt-clock-activities__current">
            <p role="status" aria-live="polite" aria-atomic="true" x-ref="activityCurrent" tabindex="-1">
                <strong x-text="labels[active?.kind] || 'Tätigkeit wählen'"></strong>
                <span x-show="activityTime" x-text="'seit ' + activityTime"></span>
            </p>
            <button type="button" class="rt-clock-activities__text" x-ref="activityCorrection" x-show="active?.can_correct_activity" x-cloak
                :disabled="busy || !ready || conflicts > 0" @click="openActivities(true)">Korrigieren</button>
        </div>
        <div class="rt-clock-activities__quick">
            <template x-for="kind in (quickKinds.length ? quickKinds : recentKinds)" :key="kind">
                <button type="button" :disabled="busy || !ready || conflicts > 0" @click="chooseActivity(kind)" x-text="labels[kind]"></button>
            </template>
            <button type="button" class="rt-clock-activities__picker" x-ref="activityPicker" :disabled="busy || !ready || conflicts > 0"
                aria-haspopup="dialog" @click="openActivities()"><i class="far fa-search" aria-hidden="true"></i> Alle Tätigkeiten</button>
        </div>
    </div>

    <dialog class="rt-activity-dialog" x-ref="activityDialog" :aria-labelledby="$id('activity-dialog-title')" @click.self="$el.close()"
        @keydown.arrow-down.prevent="moveFocus(1)" @keydown.arrow-up.prevent="moveFocus(-1)">
        <div class="rt-activity-dialog__inner">
            <header>
                <div>
                    <h3 :id="$id('activity-dialog-title')" x-text="correcting ? 'Tätigkeit korrigieren' : 'Tätigkeit wählen'"></h3>
                    <p>Aktuell: <span x-text="labels[active?.kind]"></span><span x-show="activityTime" x-text="' seit ' + activityTime"></span></p>
                </div>
                <button type="button" class="rt-activity-dialog__close" aria-label="Schließen" autofocus @click="$refs.activityDialog.close()"><i class="far fa-times" aria-hidden="true"></i></button>
            </header>
            <label class="sr-only" :for="$id('activity-search')">Tätigkeit suchen</label>
            <div class="rt-activity-dialog__search">
                <i class="far fa-search" aria-hidden="true"></i>
                <input x-ref="activitySearch" type="search" :id="$id('activity-search')" placeholder="Tätigkeit suchen"
                    autocomplete="off" x-model="search" @keydown.enter.prevent="if (search.trim()) $refs.activityDialog.querySelector('[data-activity-choice]:not(:disabled)')?.click()">
            </div>
            <div class="rt-activity-dialog__options">
                <template x-for="group in groups" :key="group.title">
                    <section x-show="group.kinds.length">
                        <h4 x-text="group.title"></h4>
                        <template x-for="kind in group.kinds" :key="kind">
                            <button type="button" data-activity-choice :disabled="busy || !ready || conflicts > 0 || active?.kind === kind"
                                @click="chooseActivity(kind)">
                                <span x-text="labels[kind]"></span>
                                <span x-show="active?.kind === kind"><i class="far fa-check" aria-hidden="true"></i> Aktuell</span>
                                <i x-show="active?.kind !== kind" class="far fa-chevron-right" aria-hidden="true"></i>
                            </button>
                        </template>
                    </section>
                </template>
                <p class="rt-activity-dialog__empty" x-show="search.trim() && !groups[0]?.kinds.length" x-cloak>
                    Keine passende Tätigkeit gefunden.
                    <button class="rt-clock-activities__text" type="button" @click="search = ''; $refs.activitySearch.focus()">Suche zurücksetzen</button>
                </p>
            </div>
            <p class="rt-activity-dialog__hint" x-text="correcting ? 'Die Auswahl korrigiert den laufenden Abschnitt ab seinem bisherigen Beginn.' : 'Die Auswahl startet den Abschnitt ab jetzt.'"></p>
        </div>
    </dialog>
</div>
