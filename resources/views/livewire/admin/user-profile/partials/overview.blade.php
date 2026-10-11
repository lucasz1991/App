<div class="employee-overview" data-profile-overview>
    <section class="employee-detail-group employee-overview__personal" aria-labelledby="employee-overview-personal-title">
        <header class="employee-overview__section-header">
            <span class="employee-overview__section-icon"><i class="far fa-user" aria-hidden="true"></i></span>
            <div><h3 id="employee-overview-personal-title">{{ __('app.personal_data') }}</h3><p>Identität und persönliche Eckdaten</p></div>
        </header>
        <dl class="employee-detail-list">
            <div class="employee-detail-row">
                <dt>{{ __('app.username') }}</dt>
                <dd><x-ui.inline-edit-field id="employee-details-name" field="name" :can-edit="$canEditEmployee" align="left">{{ $user->name }}</x-ui.inline-edit-field></dd>
            </div>
            <div class="employee-detail-row">
                <dt>{{ __('app.birth_date') }}</dt>
                <dd><x-ui.inline-edit-field id="employee-details-birth-date" field="birth_date" type="date" :can-edit="$canEditEmployee" align="left">{{ $profile?->birth_date?->format('d.m.Y') ?: __('app.not_set') }}</x-ui.inline-edit-field></dd>
            </div>
            @if($canViewMasterData)
                <div class="employee-detail-row">
                    <dt>{{ __('app.personnel_nr') }}</dt>
                    <dd><x-ui.inline-edit-field id="employee-details-personnel-nr" field="personnel_nr" :can-edit="$canEditMasterData" align="left">{{ $profile?->personnel_nr ?: __('app.not_set') }}</x-ui.inline-edit-field></dd>
                </div>
            @endif
            <div class="employee-detail-row">
                <dt>{{ __('app.registered_at') }}</dt>
                <dd class="employee-overview__static-value">{{ $user->created_at->format('d.m.Y') }}</dd>
            </div>
        </dl>
    </section>

    <section class="employee-detail-group employee-overview__contact" aria-labelledby="employee-overview-contact-title">
        <header class="employee-overview__section-header">
            <span class="employee-overview__section-icon"><i class="far fa-address-card" aria-hidden="true"></i></span>
            <div><h3 id="employee-overview-contact-title">Kontakt</h3><p>So ist der Mitarbeiter erreichbar</p></div>
        </header>
        <dl class="employee-detail-list">
            @foreach([
                ['email', 'email', 'email', 'email'],
                ['phone', 'phone', 'text', 'tel'],
                ['mobile', 'mobile', 'text', 'tel'],
            ] as [$field, $label, $type, $autocomplete])
                @php($value = $field === 'email' ? $user->email : $profile?->{$field})
                <div class="employee-detail-row">
                    <dt>{{ __('app.'.$label) }}</dt>
                    <dd>
                        <x-ui.inline-edit-field :id="'employee-contact-'.$field" :field="$field" :type="$type" :autocomplete="$autocomplete" :can-edit="$canEditEmployee" align="left">
                            <span>{{ $value ?: __('app.not_set') }}</span>
                            @if($field === 'email')
                                <i class="far {{ $user->email_verified_at ? 'fa-check-circle text-emerald-500' : 'fa-exclamation-circle text-amber-500' }}" title="{{ $user->email_verified_at ? __('app.email_verified') : __('app.email_not_verified') }}" aria-hidden="true"></i>
                                <span class="sr-only">{{ $user->email_verified_at ? __('app.email_verified') : __('app.email_not_verified') }}</span>
                            @endif
                        </x-ui.inline-edit-field>
                    </dd>
                </div>
            @endforeach
        </dl>
    </section>

    <section class="employee-detail-group employee-overview__address" aria-labelledby="employee-overview-address-title">
        <header class="employee-overview__section-header">
            <span class="employee-overview__section-icon"><i class="far fa-location-dot" aria-hidden="true"></i></span>
            <div><h3 id="employee-overview-address-title">Anschrift</h3><p>Hinterlegte Adresse aus dem Profil</p></div>
        </header>
        <dl class="employee-detail-list employee-overview__address-grid">
            @foreach([
                ['street', 'street', 'street-address'],
                ['postal_code', 'postal_code', 'postal-code'],
                ['city', 'city', 'address-level2'],
                ['country', 'country', 'country-name'],
            ] as [$field, $label, $autocomplete])
                <div class="employee-detail-row" data-address-field="{{ $field }}">
                    <dt>{{ __('app.'.$label) }}</dt>
                    <dd><x-ui.inline-edit-field :id="'employee-contact-'.$field" :field="$field" :autocomplete="$autocomplete" :can-edit="$canEditEmployee" align="left">{{ $profile?->{$field} ?: __('app.not_set') }}</x-ui.inline-edit-field></dd>
                </div>
            @endforeach
        </dl>
    </section>

    @if($canViewMasterData)
        <section class="employee-detail-group employee-overview__employment" aria-labelledby="employee-overview-employment-title">
            <header class="employee-overview__section-header">
                <span class="employee-overview__section-icon"><i class="far fa-briefcase" aria-hidden="true"></i></span>
                <div><h3 id="employee-overview-employment-title">Beschäftigung</h3><p>Funktion und hinterlegte Arbeitszeit</p></div>
            </header>
            <dl class="employee-detail-list">
                <div class="employee-detail-row">
                    <dt>{{ __('app.position') }}</dt>
                    <dd><x-ui.inline-edit-field id="employee-overview-position" field="position" :can-edit="$canEditMasterData" align="left">{{ $profile?->position ?: __('app.not_set') }}</x-ui.inline-edit-field></dd>
                </div>
                <div class="employee-detail-row">
                    <dt>{{ __('app.entry_date') }}</dt>
                    <dd><x-ui.inline-edit-field id="employee-overview-entry-date" field="entry_date" type="date" :can-edit="$canEditMasterData" align="left">{{ $profile?->entry_date?->format('d.m.Y') ?: __('app.not_set') }}</x-ui.inline-edit-field></dd>
                </div>
                <div class="employee-detail-row">
                    <dt>{{ __('app.weekly_working_hours') }}</dt>
                    <dd><x-ui.inline-edit-field id="employee-overview-weekly-hours" field="weekly_working_hours" type="number" :can-edit="$canEditMasterData" align="left">{{ $profile?->weekly_working_hours !== null ? rtrim(rtrim(number_format((float) $profile->weekly_working_hours, 2, ',', '.'), '0'), ',').' h' : __('app.not_set') }}</x-ui.inline-edit-field></dd>
                </div>
            </dl>
        </section>
    @endif
</div>
