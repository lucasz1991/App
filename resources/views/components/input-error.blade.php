@props(['for'])

@error($for)
    <p
        {{ $attributes->class('mt-1.5 flex items-start gap-1.5 text-xs font-medium leading-5 text-rt-red dark:text-rt-dark-accent') }}
        id="{{ \Illuminate\Support\Str::slug($for) }}-error"
        role="alert"
        aria-live="polite"
    >
        <i class="far fa-circle-exclamation mt-1 shrink-0 text-[0.7rem]" aria-hidden="true"></i>
        <span>{{ $message }}</span>
    </p>
@enderror
