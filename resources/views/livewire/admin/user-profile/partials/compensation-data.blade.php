@php
    $compensationTypeOptions = [
        '' => __('app.please_select'),
        'salary' => __('app.salary'),
        'fixed_salary' => __('app.fixed_salary'),
        'hourly_wage' => __('app.hourly_wage'),
    ];
@endphp

<div class="employee-profile__confidential-note">
    <i class="far fa-shield-check" aria-hidden="true"></i>
    <p>{{ __('app.compensation_confidential_hint') }}</p>
</div>

<section class="employee-detail-group employee-compensation">
    <h3 class="flex items-center gap-2 text-sm font-semibold text-rt-text dark:text-rt-dark-text">
        <span class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-rt-accent-soft/70 text-rt-accent dark:bg-rt-dark-accent-soft/60 dark:text-rt-dark-accent">
            <i class="far fa-wallet text-sm" aria-hidden="true"></i>
        </span>
        {{ __('app.compensation_data') }}
    </h3>
    <dl class="employee-compensation__fields">
    @foreach ([
        ['tax_identification_number', 'tax_identification_number', 'text', $profile?->tax_identification_number, []],
        ['social_security_number', 'social_security_number', 'text', $profile?->social_security_number, []],
        ['iban', 'iban', 'text', $profile?->iban, []],
        ['health_insurance', 'health_insurance', 'text', $profile?->health_insurance, []],
        ['tax_class', 'tax_class', 'text', $profile?->tax_class, []],
        ['children_count', 'children_count', 'number', $profile?->children_count, []],
        ['religion', 'religion', 'text', $profile?->religion, []],
        ['compensation_type', 'compensation_type', 'select', $compensationTypeOptions[$profile?->compensation_type ?? ''] ?? $profile?->compensation_type, $compensationTypeOptions],
        ['compensation_amount', 'compensation_amount', 'number', $profile?->compensation_amount, []],
    ] as [$field, $label, $type, $value, $options])
        <div class="employee-compensation__field">
            <dt>{{ __('app.'.$label) }}</dt>
            <dd class="min-w-0">
                <x-ui.inline-edit-field
                    :id="'employee-compensation-'.$field"
                    :field="$field"
                    :type="$type"
                    :options="$options"
                    :can-edit="$canEditCompensation"
                    align="left"
                >
                    {{ $value ?: __('app.not_set') }}
                </x-ui.inline-edit-field>
            </dd>
        </div>
    @endforeach
    </dl>
</section>
