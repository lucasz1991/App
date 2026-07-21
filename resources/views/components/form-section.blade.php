@props(['submit'])

<section {{ $attributes->merge(['class' => 'md:grid md:grid-cols-3 md:gap-8']) }}>
    <x-section-title>
        <x-slot name="title">{{ $title }}</x-slot>
        <x-slot name="description">{{ $description }}</x-slot>
    </x-section-title>

    <div class="mt-5 md:mt-0 md:col-span-2">
        <form wire:submit="{{ $submit }}">
            <div class="rt-ui-surface border border-rt-border bg-rt-surface px-4 py-5 shadow-rt-sm dark:border-rt-dark-border dark:bg-rt-dark-surface sm:p-6 {{ isset($actions) ? 'rounded-t-xl' : 'rounded-xl' }}">
                <div class="grid grid-cols-6 gap-6">
                    {{ $form }}
                </div>
            </div>

            @if (isset($actions))
                <div class="rt-ui-surface-muted flex items-center justify-end rounded-b-xl border border-t-0 border-rt-border bg-rt-surface-muted px-4 py-3 text-end shadow-rt-sm dark:border-rt-dark-border dark:bg-rt-dark-surface-muted sm:px-6">
                    {{ $actions }}
                </div>
            @endif
        </form>
    </div>
</section>
