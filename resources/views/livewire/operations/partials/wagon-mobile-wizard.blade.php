{{--
    Mobiles Ausfuellverfahren (unter 1024 px) — EINE Seite statt acht Folien.

    AUFBAU — drei Geschwister in einer Spalte:

        header (Abschnittsleiste)   · shrink-0
        Seite (5 Abschnitte)        · min-h-0 flex-1, scrollt vertikal
        footer (Stand + Speichern)  · shrink-0

    Die Waggons werden wie auf dem Desktop auf EINER Seite abgearbeitet:
    Der Abschnitt "Wagen" listet alle Waggons; Antippen oeffnet ein Modal
    mit saemtlichen Feldern dieses Wagens in einem Stueck. Die Felder kommen
    aus wagon-detail-fields (dieselben wie in der Einzelansicht am Desktop)
    und folgen der Excel-Liste v16_5.

    ASSISTENTEN-VERTRAG: Die acht Schritt-Kennungen (train, identity,
    vehicle, brakes, route, calculation, special, review) bleiben bestehen.
    train/calculation/special/review sind Anker auf der Seite;
    identity/vehicle/brakes/route sind Anker im Wagen-Modal (gesetzt von
    wagon-detail-fields mit anchors=true) — goToMobileStep() oeffnet bzw.
    schliesst das Modal entsprechend. Der Meldezettel hat keinen eigenen
    Schritt, er steht im Abschnitt "special".
--}}
<section
    class="rt-wagon-mobile-wizard min-h-0 flex-1 lg:hidden"
    data-wagon-wizard
    data-mobile-wagon-editor
    data-no-sidebar-swipe
    @keydown="handleMobileWizardKeydown($event)"
>
    <header class="rt-wagon-wizard-progress shrink-0" data-wagon-motion="header">
        <div class="rt-wagon-wizard-progress-inner">
            <ol
                x-ref="mobileStepRail"
                class="rt-wagon-step-rail"
                aria-label="{{ __('app.wagon_wizard_steps') }}"
            >
                @foreach ([
                    ['id' => 'train', 'icon' => 'far fa-route', 'label' => __('app.train_data')],
                    ['id' => 'wagons', 'icon' => 'far fa-train', 'label' => __('app.wagons')],
                    ['id' => 'calculation', 'icon' => 'far fa-calculator', 'label' => __('app.brake_sheet')],
                    ['id' => 'special', 'icon' => 'far fa-file-signature', 'label' => __('app.wagon_report')],
                    ['id' => 'review', 'icon' => 'far fa-clipboard-check', 'label' => __('app.review_and_finish')],
                ] as $index => $section)
                    <li class="shrink-0">
                        <button
                            type="button"
                            @click="goToMobileSection(@js($section['id']))"
                            :aria-current="mobileSectionId === @js($section['id']) ? 'true' : null"
                            :data-active="mobileSectionId === @js($section['id']) ? 'true' : 'false'"
                            data-mobile-section-chip="{{ $section['id'] }}"
                            class="rt-wagon-step-chip"
                            title="{{ $section['label'] }}"
                        >
                            <span class="rt-wagon-step-number"><i class="{{ $section['icon'] }}" aria-hidden="true"></i></span>
                            <span class="whitespace-nowrap">{{ $section['label'] }}</span>
                        </button>
                    </li>
                @endforeach
            </ol>
        </div>

        <span class="sr-only" aria-live="polite" x-text="mobileStepTitle"></span>
    </header>

    <div
        x-ref="mobilePage"
        class="rt-wagon-mobile-page min-h-0 flex-1"
        role="region"
        aria-label="{{ __('app.wagon_wizard_steps') }}"
    >
        {{-- Zugdaten ---------------------------------------------------- --}}
        <section
            id="wagon-mobile-section-train"
            class="rt-wagon-page-section"
            data-wagon-anchor="train"
            data-wagon-motion="panel"
            aria-labelledby="wagon-mobile-train-title"
        >
            <section class="rt-wagon-card">
                <header class="rt-wagon-card-head">
                    <span class="rt-wagon-card-icon"><i class="far fa-route" aria-hidden="true"></i></span>
                    <h4 id="wagon-mobile-train-title">{{ __('app.train_data') }}</h4>
                </header>
                <div class="rt-wagon-card-body rt-wl-fields">
                    <label class="rt-wl-field">
                        <span class="rt-wl-label">{{ __('app.train_number') }}</span>
                        <input x-model="meta.trainNumber" type="text" class="rt-wl-input" autocomplete="off" inputmode="numeric">
                    </label>
                    <div class="rt-wl-field">
                        <span class="rt-wl-label">
                            {{ __('app.date') }}
                            <button type="button" class="rt-wl-link" @click="setToday()">{{ __('app.today') }}</button>
                        </span>
                        <x-ui.forms.date-field x-model="meta.date" class="mt-1" :aria-label="__('app.date')" />
                    </div>
                    <label class="rt-wl-field">
                        <span class="rt-wl-label">{{ __('app.from') }}</span>
                        <input x-model="meta.origin" type="text" class="rt-wl-input" autocomplete="off">
                    </label>
                    <label class="rt-wl-field">
                        <span class="rt-wl-label">{{ __('app.to') }}</span>
                        <input x-model="meta.destination" type="text" class="rt-wl-input" autocomplete="off">
                    </label>
                    <label class="rt-wl-field">
                        <span class="rt-wl-label">{{ __('app.reference') }}</span>
                        <input x-model="meta.reference" type="text" class="rt-wl-input" autocomplete="off">
                    </label>
                    <label class="rt-wl-field">
                        <span class="rt-wl-label">{{ __('app.customer') }}</span>
                        <input x-model="meta.customer" type="text" class="rt-wl-input" autocomplete="off">
                    </label>
                </div>
            </section>
        </section>

        {{-- Wagen: alle Waggons auf einen Blick, Modal je Wagen ---------- --}}
        <section
            id="wagon-mobile-section-wagons"
            class="rt-wagon-page-section"
            data-wagon-anchor="wagons"
            data-wagon-motion="panel"
            aria-labelledby="wagon-mobile-wagons-title"
        >
            <section class="rt-wagon-card">
                <header class="rt-wagon-card-head">
                    <span class="rt-wagon-card-icon"><i class="far fa-train" aria-hidden="true"></i></span>
                    <div class="min-w-0 flex-1">
                        <h4 id="wagon-mobile-wagons-title">{{ __('app.wagons') }}</h4>
                    </div>
                    <span class="rt-wagon-review-count"><span x-text="completionCount"></span>/<span x-text="visibleCount"></span></span>
                </header>

                <div class="rt-wagon-card-body">
                    <ol class="rt-wagon-review-list" x-ref="reviewWagonList">
                        <template x-for="(wagon, index) in wagons.slice(0, visibleCount)" :key="index">
                            <li data-wagon-motion-item>
                                <button
                                    type="button"
                                    @click="openWagonModal(index)"
                                    class="rt-wagon-review-item"
                                    :data-state="wagonStatus(wagon)"
                                    :aria-label="`${@js(__('app.wagon_open'))}: ${@js(__('app.wagons'))} ${index + 1}`"
                                >
                                    <span class="rt-wagon-review-item-index" x-text="index + 1"></span>
                                    <span class="min-w-0 flex-1">
                                        <span class="rt-wagon-review-item-title" x-text="wagonNumber(wagon) || `${@js(__('app.wagons'))} ${index + 1}`"></span>
                                        <span class="rt-wagon-review-item-meta">
                                            <template x-if="isWagonFilled(wagon)">
                                                <span>
                                                    <span x-text="wagon.category || '—'"></span>
                                                    <span aria-hidden="true">·</span>
                                                    <span class="tabular-nums" x-text="`${formatPlain(totalWeight(wagon), 1)} t`"></span>
                                                    <template x-if="wagon.brakeType">
                                                        <span><span aria-hidden="true">·</span> <span x-text="wagon.brakeType"></span></span>
                                                    </template>
                                                </span>
                                            </template>
                                        </span>
                                        <span class="rt-wagon-review-item-route" x-show="wagonRouteLabel(wagon)" x-text="wagonRouteLabel(wagon)"></span>
                                    </span>
                                    <span class="rt-wagon-review-item-state" aria-hidden="true"></span>
                                    <i class="far fa-chevron-right rt-wagon-review-item-chevron" aria-hidden="true"></i>
                                </button>
                            </li>
                        </template>
                    </ol>

                    <button
                        type="button"
                        @click="addWagonAndOpen()"
                        :disabled="visibleCount >= 40"
                        class="rt-wagon-review-add"
                    >
                        <i class="far fa-plus" aria-hidden="true"></i>
                        <span>{{ __('app.add_wagon') }}</span>
                    </button>

                    <button
                        type="button"
                        @click="reverseWagons()"
                        x-show="completionCount > 1"
                        class="rt-wl-ghost mt-2 w-full justify-center"
                    >
                        <i class="far fa-sort-alt" aria-hidden="true"></i>
                        <span>{{ __('app.wagon_reverse_order') }}</span>
                    </button>
                </div>
            </section>

            <section class="rt-wagon-card" aria-label="{{ __('app.wagon_totals') }}">
                <header class="rt-wagon-card-head">
                    <span class="rt-wagon-card-icon"><i class="far fa-calculator" aria-hidden="true"></i></span>
                    <h4>{{ __('app.wagon_totals') }}</h4>
                </header>
                <div class="rt-wagon-card-body grid grid-cols-2 gap-2">
                    <template x-for="item in [
                        { label: @js(__('app.wagons')), value: totals.wagons, suffix: '' },
                        { label: @js(__('app.axles')), value: formatPlain(totals.axles, 0), suffix: '' },
                        { label: @js(__('app.length_over_buffers')), value: formatPlain(totals.length, 1), suffix: ' m' },
                        { label: @js(__('app.total_weight')), value: formatPlain(totals.totalWeight, 1), suffix: ' t' },
                        { label: @js(__('app.brake_weight_g')), value: formatPlain(totals.brakeG, 1), suffix: ' t' },
                        { label: @js(__('app.brake_weight_p')), value: formatPlain(totals.brakeP, 1), suffix: ' t' },
                    ]" :key="item.label">
                        <div class="rt-wagon-metric">
                            <p class="rt-wagon-metric-label" x-text="item.label"></p>
                            <p class="rt-wagon-metric-value"><span x-text="item.value"></span><span x-text="item.suffix"></span></p>
                        </div>
                    </template>
                </div>
            </section>
        </section>

        {{-- Bremszettel -------------------------------------------------- --}}
        <section
            id="wagon-mobile-section-calculation"
            class="rt-wagon-page-section"
            data-wagon-anchor="calculation"
            data-wagon-motion="panel"
            aria-label="{{ __('app.brake_sheet') }}"
        >
            @include('livewire.operations.partials.wagon-brake-inputs', ['scope' => 'mobile'])
            @include('livewire.operations.partials.wagon-brake-result', ['scope' => 'mobile'])
        </section>

        {{-- Besondere Angaben und Meldezettel ----------------------------- --}}
        <section
            id="wagon-mobile-section-special"
            class="rt-wagon-page-section"
            data-wagon-anchor="special"
            data-wagon-motion="panel"
            aria-label="{{ __('app.special_information') }}"
        >
            @include('livewire.operations.partials.wagon-special-information', [
                'columns' => 1,
                'scope' => 'mobile',
            ])

            @include('livewire.operations.partials.wagon-report-form', ['scope' => 'mobile'])
        </section>

        {{-- Pruefen und abschliessen ------------------------------------- --}}
        <section
            id="wagon-mobile-section-review"
            class="rt-wagon-page-section"
            data-wagon-anchor="review"
            data-wagon-motion="panel"
            aria-labelledby="wagon-mobile-review-title"
        >
            <section class="rt-wagon-review-train">
                <p class="rt-wagon-flow-eyebrow" id="wagon-mobile-review-title">{{ __('app.review_and_finish') }}</p>
                <div class="mt-2 flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <strong class="block truncate text-xl" x-text="meta.trainNumber || @js(__('app.wagon_not_filled'))"></strong>
                        <p class="mt-1 truncate text-sm opacity-70">
                            <span x-text="meta.origin || '—'"></span> → <span x-text="meta.destination || '—'"></span>
                        </p>
                        <p class="mt-0.5 text-xs tabular-nums opacity-55" x-text="formatDate(meta.date)"></p>
                    </div>
                    <span class="rt-wagon-review-count"><span x-text="completionCount"></span>/<span x-text="visibleCount"></span></span>
                </div>
            </section>

            @include('livewire.operations.partials.wagon-summary-panel', [
                'scope' => 'mobile',
                'sectionClass' => 'rt-wagon-card rt-wl-aside-card',
            ])

            <button
                type="button"
                @click="exportWorkbook()"
                :disabled="exporting"
                class="rt-wagon-export-button"
            >
                <i class="far" :class="exporting ? 'fa-spinner fa-spin' : 'fa-file-excel'" aria-hidden="true"></i>
                <span x-text="exporting ? @js(__('app.wagon_exporting')) : @js(__('app.export_excel'))"></span>
            </button>
        </section>
    </div>

    <footer class="rt-wagon-wizard-footer shrink-0" data-wagon-motion="footer">
        <div class="rt-wagon-wizard-footer-inner">
            <p class="rt-wagon-wizard-footer-title justify-self-start" aria-hidden="true">
                <span class="tabular-nums" x-text="`${completionCount}/${visibleCount}`"></span>
                {{ __('app.wagons') }}
            </p>

            <span></span>

            <button type="button" @click="saveAndClose()" class="rt-wagon-wizard-action rt-wagon-wizard-action-primary justify-self-end">
                <i class="far fa-check" aria-hidden="true"></i><span>{{ $labels['saveAndClose'] }}</span>
            </button>
        </div>
    </footer>

    {{-- Wagen-Modal: alle Angaben EINES Wagens in einem Stueck ----------- --}}
    <div
        x-show.important="wagonModalOpen"
        x-cloak
        {{-- rt-modal-shell haelt diese Ebene im gemeinsamen Vertrag aller
             Modale: nur damit stellt die Regel in app.css die Huelle
             waehrend der Fahrt auf overflow:clip und der Dialog kann nicht
             durch scrollIntoView verrutschen. --}}
        class="rt-wagon-modal rt-modal-shell fixed inset-0 z-[60] flex items-end justify-center lg:hidden"
        data-rt-modal-shell
        data-no-sidebar-swipe
    >
        <div
            x-show.important="wagonModalOpen"
            class="rt-wagon-modal-backdrop absolute inset-0"
            x-transition:enter="rt-motion-backdrop-enter"
            x-transition:enter-start="rt-motion-faded"
            x-transition:enter-end="rt-motion-shown"
            x-transition:leave="rt-motion-backdrop-leave"
            x-transition:leave-start="rt-motion-shown"
            x-transition:leave-end="rt-motion-faded"
            @click="closeWagonModal()"
            aria-hidden="true"
        ></div>

        <section
            x-show.important="wagonModalOpen"
            x-transition:enter="rt-motion-modal-enter"
            x-transition:enter-start="rt-motion-modal-enter-from"
            x-transition:enter-end="rt-motion-modal-enter-to"
            x-transition:leave="rt-motion-modal-leave"
            x-transition:leave-start="rt-motion-modal-leave-from"
            x-transition:leave-end="rt-motion-modal-leave-to"
            role="dialog"
            aria-modal="true"
            aria-labelledby="wagon-modal-title"
            class="rt-wagon-modal-panel rt-modal-frame relative flex max-h-[94dvh] min-h-0 w-full flex-col"
            data-rt-modal-panel
        >
            <header class="rt-wagon-modal-head shrink-0">
                <span class="rt-wagon-step-number-badge" x-text="mobileWagon + 1"></span>
                <div class="min-w-0 flex-1">
                    <h3 id="wagon-modal-title" class="truncate text-base font-semibold" x-text="wagonNumber(wagons[mobileWagon]) || `${@js(__('app.wagons'))} ${mobileWagon + 1}`"></h3>
                </div>
                <button
                    type="button"
                    @click="moveWagon(mobileWagon, -1)"
                    :disabled="mobileWagon === 0"
                    class="rt-wagon-icon-action"
                    title="{{ __('app.wagon_move_up') }}"
                    aria-label="{{ __('app.wagon_move_up') }}"
                >
                    <i class="far fa-arrow-up" aria-hidden="true"></i>
                </button>
                <button
                    type="button"
                    @click="moveWagon(mobileWagon, 1)"
                    :disabled="mobileWagon >= visibleCount - 1"
                    class="rt-wagon-icon-action"
                    title="{{ __('app.wagon_move_down') }}"
                    aria-label="{{ __('app.wagon_move_down') }}"
                >
                    <i class="far fa-arrow-down" aria-hidden="true"></i>
                </button>
                <button
                    type="button"
                    @click="clearWagon(mobileWagon)"
                    class="rt-wagon-icon-action rt-wagon-icon-action--danger"
                    title="{{ __('app.clear_wagon') }}"
                    aria-label="{{ __('app.clear_wagon') }}"
                >
                    <i class="far fa-trash-alt" aria-hidden="true"></i>
                </button>
                <button
                    type="button"
                    @click="closeWagonModal()"
                    class="rt-wagon-icon-action"
                    title="{{ __('app.close') }}"
                    aria-label="{{ __('app.close') }}"
                >
                    <i class="far fa-times" aria-hidden="true"></i>
                </button>
            </header>

            <div x-ref="wagonModalBody" class="rt-wagon-modal-body min-h-0 flex-1">
                @include('livewire.operations.partials.wagon-detail-fields', [
                    'w' => 'wagons[mobileWagon]',
                    'scope' => 'mobile',
                    'anchors' => true,
                ])
            </div>

            <footer class="rt-wagon-modal-footer shrink-0">
                <button
                    type="button"
                    @click="previousMobileWagon()"
                    :disabled="mobileWagon === 0"
                    class="rt-wagon-wizard-action"
                    aria-label="{{ __('app.previous') }}"
                >
                    <i class="far fa-arrow-left" aria-hidden="true"></i>
                    <span class="tabular-nums" x-text="mobileWagon"></span>
                </button>

                <button type="button" @click="closeWagonModal()" class="rt-wagon-wizard-action rt-wagon-wizard-action-primary">
                    <i class="far fa-check" aria-hidden="true"></i>
                    <span>{{ __('app.close') }}</span>
                </button>

                <button
                    type="button"
                    @click="nextMobileWagon()"
                    :disabled="mobileWagon >= visibleCount - 1"
                    class="rt-wagon-wizard-action"
                    aria-label="{{ __('app.next') }}"
                >
                    <span class="tabular-nums" x-text="mobileWagon + 2"></span>
                    <i class="far fa-arrow-right" aria-hidden="true"></i>
                </button>
            </footer>
        </section>
    </div>
</section>
