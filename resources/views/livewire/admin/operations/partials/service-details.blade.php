@if($selectedOrder)
            @php
                $selectedStatus = $selectedOrder->status instanceof \BackedEnum ? $selectedOrder->status->value : (string) $selectedOrder->status;
                $selectedPriority = $selectedOrder->priority instanceof \BackedEnum ? $selectedOrder->priority->value : (string) $selectedOrder->priority;
                $planningRoute = \App\Support\Operations\OperationsAccess::ready() ? 'operations.workspace' : 'admin.operations.preview';
            @endphp
            <div class="rt-ops-panel__accent" aria-hidden="true"></div>
            <x-operations.panel.header :eyebrow="$selectedOrder->order_number" :title="$selectedOrder->title" subtitle="Leistungsdetails" icon="fa-briefcase" close-label="Leistungsdetails schließen">
                <x-slot:actions>
                    <button type="button" wire:click="editOrder({{ $selectedOrder->id }})" wire:loading.attr="disabled" @if($embeddedDetail) x-on:click="$dispatch('close')" @endif class="rt-ops-panel__icon-button" aria-label="Leistung bearbeiten" title="Leistung bearbeiten"><i class="far fa-pen" aria-hidden="true"></i></button>
                </x-slot:actions>
                <x-slot:meta><x-operations.service-status :status="$selectedOrder->status" /><span class="rt-ops-panel__fact">{{ $serviceDetailItem->count }} {{ $serviceDetailItem->count === 1 ? 'Schicht' : 'Schichten' }} · Gesamte Leistung</span></x-slot:meta>
            </x-operations.panel.header>
            @if($consolidated)
                @php
                    $detailTabIcons = ['overview'=>'far fa-briefcase', 'shifts'=>'far fa-calendar-days', 'staffing'=>'far fa-users', 'planning'=>'far fa-calendar-check', 'proofs'=>'far fa-file-check', 'costs'=>'far fa-coins', 'history'=>'far fa-clock-rotate-left'];
                    $detailTabs = collect($this->detailSections())->map(fn ($label, $key) => [
                        'label'=>$label, 'icon'=>$detailTabIcons[$key] ?? 'far fa-circle',
                        'count'=>match ($key) { 'shifts'=>$serviceDetailItem->count, 'staffing'=>$serviceDetailItem->summary['reserved'], default=>null },
                        'alert'=>$key === 'staffing' && $serviceDetailItem->summary['open'] > 0,
                    ])->all();
                    $detailPrefix = 'service-detail-'.$this->getId();
                @endphp
                <x-operations.panel.tabs :tabs="$detailTabs" label="Leistungsdetails" :id-prefix="$detailPrefix" action="setDetailSection" :active="$detailSection" />
            @endif

            <div class="rt-ops-panel__body rt-modal-content" data-service-detail-body @if($detailOnly) wire:key="service-detail-body-{{ $this->getId() }}-{{ $detailSection }}" @endif>
                <x-operations.feedback />
                <div @if($consolidated) role="tabpanel" id="{{ $detailPrefix }}-panel-{{ $detailSection }}" aria-labelledby="{{ $detailPrefix }}-tab-{{ $detailSection }}" tabindex="0" @endif class="rt-service-detail-section" wire:loading.class="opacity-60" wire:target="setDetailSection">
            @if($consolidated && $detailSection === 'shifts')
                <x-operations.service-detail-shifts :item="$serviceDetailItem" :detail-only="$detailOnly" :planning-route="$planningRoute" />
            @endif
            @if($consolidated && $detailSection === 'staffing')
                <x-operations.service-detail-staffing :item="$serviceDetailItem" />
            @endif
            @if(!$consolidated || $detailSection === 'overview')
            <x-operations.service-detail-summary :item="$serviceDetailItem" />
            <div class="rt-service-detail-workflow">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <h3 class="text-sm font-semibold text-rt-text dark:text-rt-dark-text">Ablauf & Freigabe</h3>
                        <p class="mt-1 text-xs text-rt-muted dark:text-rt-dark-muted">Statuswechsel mit den bestehenden Prüfungen.</p>
                    </div>
                    <div class="block w-full sm:w-56" wire:key="order-status-{{ $selectedOrder->id }}-{{ $selectedStatus }}">
                        <span class="mb-1 block text-xs font-semibold text-rt-muted dark:text-rt-dark-muted">Status ändern</span>
                        <x-ui.forms.select wire:key="order-status-transition-{{ $selectedStatus }}" change="$wire.changeStatus($event.target.value)" :disabled="empty($transitionOptions)" aria-label="Status ändern">
                            <option value="" selected disabled>{{ empty($transitionOptions) ? 'Kein Folgestatus verfügbar' : 'Folgestatus auswählen' }}</option>
                            @foreach($transitionOptions as $option)
                                <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                            @endforeach
                        </x-ui.forms.select>
                    </div>
                </div>
                @error('statusChange') <p class="mt-2 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-500/10 dark:text-red-300">{{ $message }}</p> @enderror
            </div>

            @endif

            @if($detailOpen && (!$consolidated || $detailSection === 'overview') && \App\Services\Operations\SiteSnapshotService::ready())
                <livewire:operations.routing-site-editor subject-type="order" :subject-id="$selectedOrder->id" :key="'routing-order-'.$selectedOrder->id" />
            @endif
            @if($detailOpen && (!$consolidated || $detailSection === 'planning') && \App\Support\Operations\PlanningSchema::ready())
                <livewire:operations.order-demands :order-id="$selectedOrder->id" :key="'demand-'.$selectedOrder->id" />
            @endif
            @if($detailOpen && (!$consolidated || $detailSection === 'overview') && \Illuminate\Support\Facades\Schema::hasTable('commercial_offer_revisions') && auth()->user()->can('operations.manage'))
                <livewire:operations.commercial-offers subject-type="Order" :subject-id="$selectedOrder->id" :key="'commercial-order-'.$selectedOrder->id" />
            @endif
            @if((!$consolidated || $detailSection === 'overview') && ($selectedOrder->description || $selectedOrder->notes))
                <div class="mt-4 grid gap-3 sm:grid-cols-2">
                    @if($selectedOrder->description)
                        <div class="rounded-xl border border-rt-border/70 p-3.5 dark:border-rt-dark-border/70">
                            <p class="text-[10px] font-semibold uppercase tracking-[0.12em] text-rt-soft">Beschreibung</p>
                            <p class="mt-2 whitespace-pre-line text-sm leading-6 text-rt-muted dark:text-rt-dark-muted">{{ $selectedOrder->description }}</p>
                        </div>
                    @endif
                    @if($selectedOrder->notes)
                        <div class="rounded-xl border border-rt-border/70 p-3.5 dark:border-rt-dark-border/70">
                            <p class="text-[10px] font-semibold uppercase tracking-[0.12em] text-rt-soft">Interne Notiz</p>
                            <p class="mt-2 whitespace-pre-line text-sm leading-6 text-rt-muted dark:text-rt-dark-muted">{{ $selectedOrder->notes }}</p>
                        </div>
                    @endif
                </div>
            @endif

            <div @class(['mt-5 grid gap-4','lg:grid-cols-2'=>!$consolidated])>
                @if(!$consolidated || (!$detailOnly && $detailSection === 'planning'))
                <section>
                    <h3 class="text-sm font-semibold text-rt-text dark:text-white">Schichten</h3>
                    <div class="mt-2 divide-y divide-rt-border/60 rounded-xl border border-rt-border/70 dark:divide-rt-dark-border/60 dark:border-rt-dark-border/70">
                        @forelse($selectedOrder->shifts as $shift)
                            @php($shiftStatus = $shift->status instanceof \BackedEnum ? $shift->status->value : (string) $shift->status)
                            <div class="px-3.5 py-3" wire:key="order-shift-{{ $shift->id }}">
                                <div class="flex items-center justify-between gap-2">
                                    @if($detailOnly)
                                        <x-ui.buttons.button-basic mode="link" type="button" x-on:click="$dispatch('close'); $wire.detailOpen = false; $dispatch('operations-shift-detail-request', { id: {{ $shift->id }} })" class="min-h-11 min-w-0 text-left"><span class="break-words">{{ $shift->title }}</span></x-ui.buttons.button-basic>
                                    @else
                                        <x-ui.buttons.button-basic mode="link" data-no-navigate :href="route($planningRoute, ['module' => 'shift-management', 'order' => $selectedOrder->id, 'shift' => $shift->id])" class="min-h-11 min-w-0 text-left"><span class="break-words">{{ $shift->title }}</span></x-ui.buttons.button-basic>
                                    @endif
                                    <x-operations.status :value="$shiftStatus" />
                                </div>
                                <p class="mt-1 text-xs text-rt-muted dark:text-rt-dark-muted">{{ $shift->starts_at?->format('d.m.Y H:i') }} · {{ $shift->assignments->filter(fn ($assignment) => in_array(
                                    $assignment->status instanceof \BackedEnum ? $assignment->status->value : $assignment->status,
                                    \App\Enums\ShiftAssignmentStatus::blockingValues(),
                                    true,
                                ))->count() }}/{{ $shift->required_staff }} reserviert</p>
                            </div>
                        @empty
                            <p class="px-4 py-6 text-center text-sm text-rt-muted dark:text-rt-dark-muted">Noch keine Schichten angelegt.</p>
                        @endforelse
                    </div>
                </section>
                @endif
                @if(!$consolidated || $detailSection === 'history')
                <section>
                    <h3 class="text-sm font-semibold text-rt-text dark:text-white">Statusverlauf</h3>
                    <div class="mt-2 space-y-2">
                        @forelse($selectedOrder->statusHistory->take(6) as $history)
                            <div class="rounded-xl border border-rt-border/70 px-3.5 py-3 dark:border-rt-dark-border/70" wire:key="order-history-{{ $history->id }}">
                                <p class="text-xs font-semibold text-rt-text dark:text-white">{{ method_exists($history->to_status, 'label') ? $history->to_status->label() : \Illuminate\Support\Str::headline((string) ($history->to_status instanceof \BackedEnum ? $history->to_status->value : $history->to_status)) }}</p>
                                <p class="mt-1 text-[11px] text-rt-muted dark:text-rt-dark-muted">{{ $history->changed_at?->format('d.m.Y H:i') ?? $history->created_at?->format('d.m.Y H:i') }}</p>
                                @if($history->changedBy)<x-user.public-info :user="$history->changedBy" :size="6" :show-presence="false" class="mt-2" />@else<span class="ops-muted">System</span>@endif
                                @if($history->note)<p class="mt-1 text-xs text-rt-muted dark:text-rt-dark-muted">{{ $history->note }}</p>@endif
                            </div>
                        @empty
                            <p class="rounded-xl border border-dashed border-rt-border px-4 py-6 text-center text-sm text-rt-muted dark:border-rt-dark-border dark:text-rt-dark-muted">Noch keine Statusänderung protokolliert.</p>
                        @endforelse
                    </div>
                </section>
                @endif
            </div>
            @if($consolidated && $detailOpen && in_array($detailSection,['proofs','costs'],true))
                <livewire:operations.operations-enhancements :tab="$detailSection" :order-id="$selectedOrder->id" :initial-record-id="$detailSection === 'proofs' ? $fulfilmentRecordId : null" :embedded="true" :key="'order-fulfilment-'.$selectedOrder->id.'-'.$detailSection" />
            @endif
                </div>
            </div>
            <x-operations.panel.footer>
                <x-slot:note>{{ $selectedOrder->service_type ?: 'Leistungsart offen' }} · Bedarf: {{ $selectedOrder->required_staff }} · {{ $selectedOrder->priority->label() }}</x-slot:note>
                <nav class="rt-service-detail-links" aria-label="Verknüpfte Bereiche">
                    @if($originInquiry)<x-ui.buttons.button-basic size="sm" mode="link" data-no-navigate :href="route('operations.page',['page'=>'cases','view'=>'inbox','inquiry'=>$originInquiry->id,'customer'=>$selectedOrder->customer_id])">{{ $originInquiry->number }}</x-ui.buttons.button-basic>@endif
                    <x-ui.buttons.button-basic size="sm" mode="link" data-no-navigate :href="route('operations.page',['page'=>'customers','customer'=>$selectedOrder->customer_id])"><i class="far fa-building" aria-hidden="true"></i>Kundenakte</x-ui.buttons.button-basic>
                    <x-ui.buttons.button-basic size="sm" mode="link" data-no-navigate :href="route($planningRoute, ['module'=>'shift-management','order'=>$selectedOrder->id])"><i class="far fa-table-list" aria-hidden="true"></i>Schichtplan</x-ui.buttons.button-basic>
                    <x-ui.buttons.button-basic size="sm" mode="link" data-no-navigate :href="route($planningRoute, ['module'=>'calendar','order'=>$selectedOrder->id])"><i class="far fa-calendar-days" aria-hidden="true"></i>Kalender</x-ui.buttons.button-basic>
                </nav>
            </x-operations.panel.footer>
        @else
            <div class="flex min-h-80 flex-col items-center justify-center text-center">
                <i class="fad fa-arrow-pointer text-3xl text-rt-soft" aria-hidden="true"></i>
                <h2 class="mt-3 text-sm font-semibold text-rt-text dark:text-white">Auftrag auswählen</h2>
                <p class="mt-1 max-w-sm text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">Wähle einen Auftrag aus, um Details und Planungsstatus zu sehen.</p>
            </div>
        @endif
