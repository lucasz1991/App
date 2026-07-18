<section {{ $attributes->merge(['class' => 'md:grid md:grid-cols-3 md:gap-8']) }}>
    <x-section-title>
        <x-slot name="title">{{ $title }}</x-slot>
        <x-slot name="description">{{ $description }}</x-slot>
    </x-section-title>

    <div class="mt-5 md:mt-0 md:col-span-2">
        <div class="rounded-xl border border-rt-border bg-rt-surface px-4 py-5 shadow-sm dark:border-rt-dark-border dark:bg-rt-dark-surface sm:p-6">
            {{ $content }}
        </div>
    </div>
</section>
