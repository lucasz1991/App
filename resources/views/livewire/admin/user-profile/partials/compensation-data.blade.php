<div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100">
    <i class="far fa-shield-check mr-2"></i>{{ __('app.compensation_confidential_hint') }}
</div>
<div class="grid gap-4 lg:grid-cols-2">
    @foreach ([
        __('app.tax_identification_number') => $profile?->tax_identification_number,
        __('app.social_security_number') => $profile?->social_security_number,
        __('app.iban') => $profile?->iban,
        __('app.health_insurance') => $profile?->health_insurance,
        __('app.tax_class') => $profile?->tax_class,
        __('app.children_count') => $profile?->children_count,
        __('app.religion') => $profile?->religion,
        __('app.compensation_type') => $profile?->compensation_type,
        __('app.compensation_amount') => $profile?->compensation_amount,
    ] as $label => $value)
        <section class="flex items-start justify-between gap-4 rounded-xl bg-rt-surface p-4 shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60">
            <span class="text-xs font-semibold uppercase tracking-wide text-rt-muted dark:text-rt-dark-muted">{{ $label }}</span>
            <span class="max-w-[58%] break-words text-right text-sm font-semibold text-rt-text dark:text-rt-dark-text">{{ $value ?: '–' }}</span>
        </section>
    @endforeach
</div>
