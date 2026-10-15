@php
    $groups = [
        'contact' => [
            'label' => __('app.profile_contact'),
            'icon' => 'fad fa-address-card',
            'fields' => [
                ['first_name', 'first_name', 'text', 'given-name'],
                ['last_name', 'last_name', 'text', 'family-name'],
                ['phone', 'phone', 'text', 'tel'],
                ['mobile', 'mobile', 'text', 'tel'],
            ],
        ],
        'address' => [
            'label' => __('app.address'),
            'icon' => 'fad fa-map-marker-alt',
            'fields' => [
                ['street', 'street', 'text', 'street-address'],
                ['postal_code', 'postal_code', 'text', 'postal-code'],
                ['city', 'city', 'text', 'address-level2'],
                ['country', 'country', 'text', 'country-name'],
            ],
        ],
        'details' => [
            'label' => __('app.additional_information'),
            'icon' => 'fad fa-id-card',
            'fields' => [
                ['birth_date', 'birth_date', 'date', 'bday'],
                ['birth_place', 'birth_place', 'text', 'off'],
                ['birth_name', 'birth_name', 'text', 'off'],
                ['nationality', 'nationality', 'text', 'country-name'],
                ['education', 'school_education', 'text', 'off'],
            ],
        ],
    ];
    $errorSection = collect($groups)->keys()->first(
        fn ($key) => $errors->hasAny(array_column($groups[$key]['fields'], 0))
    );
@endphp

<form
    wire:submit="save"
    x-data="{
        openAccordionSection: 'contact',
        toggleAccordionSection(section) {
            this.openAccordionSection = this.openAccordionSection === section ? null : section;
        },
    }"
    class="employee-own-profile__form flex flex-col gap-3"
    data-profile-accordion="personal"
    data-autosave-scope
>
    <x-ui.autosave-status
        event="contact-saved"
        target="save"
        dirty-target="first_name,last_name,phone,mobile,street,postal_code,city,country,birth_date,birth_place,birth_name,nationality,education"
    />

    {{-- Reveal validation feedback even when its section was collapsed during autosave. --}}
    @if ($errorSection)
        <span wire:key="profile-contact-validation" wire:replace class="hidden" aria-hidden="true">
            <span x-init="openAccordionSection = @js($errorSection)"></span>
        </span>
    @endif

    @foreach ($groups as $section => $group)
        <x-ui.accordion.section
            :section="$section"
            :label="$group['label']"
            :icon="$group['icon']"
            id-prefix="profile-personal"
        >
            <div class="employee-own-profile__fields">
                @if ($section === 'contact')
                    <div class="employee-own-profile__position">
                        <span>{{ __('app.position') }}</span>
                        <strong>{{ auth()->user()->profile?->position ?: __('app.not_set') }}</strong>
                    </div>
                @endif

                @foreach ($group['fields'] as [$field, $label, $type, $autocomplete])
                    <div class="employee-own-profile__field" wire:key="profile-contact-{{ $field }}">
                        <x-ui.forms.label :for="$field" :value="__('app.'.$label)" />
                        <div class="min-w-0">
                            <x-ui.forms.input
                                :id="$field"
                                :type="$type"
                                wire:model="{{ $field }}"
                                :autocomplete="$autocomplete"
                            />
                            <x-input-error :for="$field" />
                        </div>
                    </div>
                @endforeach
            </div>
        </x-ui.accordion.section>
    @endforeach
</form>
