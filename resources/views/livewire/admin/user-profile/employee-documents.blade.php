<div class="space-y-4">
    <div class="rounded-xl border border-sky-200 bg-sky-50 p-4 text-sm text-sky-900 dark:border-sky-500/30 dark:bg-sky-500/10 dark:text-sky-100">
        <div class="font-semibold"><i class="far fa-lock mr-2"></i>{{ __('app.employee_documents') }}</div>
        <p class="mt-1 text-xs opacity-80">{{ __('app.employee_documents_hint') }}</p>
    </div>

    <div class="grid gap-3 lg:grid-cols-2">
        @foreach ($types as $type => $label)
            @php $requirement = $requirements->get($type); @endphp
            <section class="rounded-xl bg-rt-surface p-4 shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h3 class="text-sm font-semibold text-rt-text dark:text-rt-dark-text">{{ $label }}</h3>
                        @if ($requirement?->verified_at)
                            <p class="mt-1 text-xs text-emerald-600 dark:text-emerald-400">
                                {{ __('app.verified_by_at', ['name' => $requirement->verifier?->name ?? '–', 'date' => $requirement->verified_at->format('d.m.Y H:i')]) }}
                            </p>
                        @endif
                    </div>
                    <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700 dark:bg-slate-800 dark:text-slate-200">
                        {{ $availableStatuses[$statuses[$type]] ?? $statuses[$type] }}
                    </span>
                </div>

                <div class="mt-4 grid gap-3 sm:grid-cols-2">
                    <div>
                        <x-ui.forms.label :value="__('app.status')" />
                        <x-ui.forms.select wire:model="statuses.{{ $type }}" :disabled="!$canEdit">
                            @foreach ($availableStatuses as $status => $statusLabel)
                                <option value="{{ $status }}">{{ $statusLabel }}</option>
                            @endforeach
                        </x-ui.forms.select>
                    </div>
                    <div>
                        <x-ui.forms.label :value="__('app.linked_private_file')" />
                        <x-ui.forms.select wire:model="fileIds.{{ $type }}" :disabled="!$canEdit">
                            <option value="">{{ __('app.no_file_linked') }}</option>
                            @foreach ($files as $file)
                                <option value="{{ $file->id }}">{{ $file->name }}</option>
                            @endforeach
                        </x-ui.forms.select>
                    </div>
                </div>

                @if ($canEdit)
                    <div class="mt-3 flex justify-end">
                        <x-ui.buttons.button-basic size="sm" wire:click="save('{{ $type }}')" wire:loading.attr="disabled" wire:target="save('{{ $type }}')">
                            <i class="far fa-save"></i>{{ __('app.save') }}
                        </x-ui.buttons.button-basic>
                    </div>
                @endif
            </section>
        @endforeach
    </div>
</div>
