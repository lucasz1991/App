{{--
    Sprache, Darstellung und Toene als Karte fuer die Profil-Einstellungen —
    dieselben Schalter wie im Topbar-Menue, hier in ausfuehrlicher Form.
    Kein Livewire noetig: Sprache wechselt per Route, Darstellung und Toene
    leben in den Alpine-Stores ($store.theme / $store.sound).
--}}
@php
    $rtLocales = [
        'de' => ['flag' => 'rt-brand/flags/de.svg', 'label' => __('app.german')],
        'en' => ['flag' => 'rt-brand/flags/gb.svg', 'label' => __('app.english')],
    ];
    $rtLocaleRoutes = collect(array_keys($rtLocales))
        ->mapWithKeys(fn (string $locale) => [$locale => route('locale.switch', $locale)])
        ->all();
@endphp

<section
    class="overflow-hidden rounded-2xl bg-rt-surface shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60"
    data-testid="display-settings"
>
    <div class="flex items-start gap-3 border-b border-rt-border/60 p-4 dark:border-rt-dark-border/60 sm:p-6">
        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-rt-accent-soft text-rt-accent dark:bg-rt-dark-accent-soft dark:text-rt-dark-accent">
            <i class="far fa-palette text-lg" aria-hidden="true"></i>
        </span>
        <div class="min-w-0">
            <h2 class="text-base font-semibold tracking-tight text-rt-text dark:text-rt-dark-text">
                {{ __('app.display_settings') }}
            </h2>
            <p class="mt-1 text-sm leading-6 text-rt-muted dark:text-rt-dark-muted">
                {{ __('app.preferences_description') }}
            </p>
        </div>
    </div>

    <div class="divide-y divide-rt-border/60 dark:divide-rt-dark-border/60">
        {{-- Sprache --}}
        <div class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between sm:p-6">
            <div class="min-w-0">
                <p class="text-sm font-semibold text-rt-text dark:text-rt-dark-text">{{ __('app.language') }}</p>
                <p class="mt-0.5 text-xs text-rt-muted dark:text-rt-dark-muted">{{ __('app.language_hint') }}</p>
            </div>
            <div class="w-full sm:!w-56">
                <x-ui.forms.select
                    id="profile-language"
                    change="const routes = {{ \Illuminate\Support\Js::from($rtLocaleRoutes) }}; if (routes[selected]) window.location.assign(routes[selected]);"
                    :aria-label="__('app.language')"
                >
                    @foreach ($rtLocales as $localeKey => $localeMeta)
                        <option
                            value="{{ $localeKey }}"
                            data-icon="{{ asset($localeMeta['flag']) }}"
                            @selected(app()->getLocale() === $localeKey)
                        >{{ $localeMeta['label'] }}</option>
                    @endforeach
                </x-ui.forms.select>
            </div>
        </div>

        {{-- Darstellung (hell/dunkel) --}}
        <div class="flex items-center justify-between gap-3 p-4 sm:p-6">
            <div class="min-w-0">
                <p class="text-sm font-semibold text-rt-text dark:text-rt-dark-text">{{ __('app.appearance') }}</p>
                <p class="mt-0.5 text-xs text-rt-muted dark:text-rt-dark-muted">{{ __('app.appearance_hint') }}</p>
            </div>
            <button
                type="button"
                role="switch"
                x-data
                x-bind:aria-checked="Boolean($store.theme?.dark).toString()"
                aria-label="{{ __('app.appearance') }}"
                x-on:click="$store.theme?.toggle()"
                class="relative h-7 w-[52px] shrink-0 rounded-full border border-slate-300 bg-slate-300 transition-colors duration-300 ease-rt-spring focus:outline-none focus-visible:ring-2 focus-visible:ring-rt-red/50 dark:border-slate-600 dark:bg-slate-600"
                :class="$store.theme?.dark && '!border-rt-red !bg-rt-red'"
            >
                <span
                    class="absolute left-1 top-1/2 flex h-5 w-5 -translate-y-1/2 items-center justify-center rounded-full bg-white text-slate-600 shadow-sm transition-transform duration-300 ease-rt-spring"
                    :class="$store.theme?.dark && 'translate-x-[22px] text-rt-red'"
                    aria-hidden="true"
                >
                    <i class="far fa-sun text-[10px]" x-show.important="!$store.theme?.dark"></i>
                    <i class="far fa-moon text-[10px]" x-show.important="$store.theme?.dark" x-cloak></i>
                </span>
            </button>
        </div>

        {{-- Toene an/aus --}}
        <div class="flex items-center justify-between gap-3 p-4 sm:p-6">
            <div class="min-w-0">
                <p class="text-sm font-semibold text-rt-text dark:text-rt-dark-text">{{ __('app.sound') }}</p>
                <p class="mt-0.5 text-xs text-rt-muted dark:text-rt-dark-muted">{{ __('app.sound_hint') }}</p>
            </div>
            <button
                type="button"
                role="switch"
                x-data
                x-bind:aria-checked="Boolean($store.sound?.enabled).toString()"
                aria-label="{{ __('app.sound') }}"
                x-on:click="$store.sound?.toggle()"
                class="relative h-7 w-[52px] shrink-0 rounded-full border border-slate-300 bg-slate-300 transition-colors duration-300 ease-rt-spring focus:outline-none focus-visible:ring-2 focus-visible:ring-rt-red/50 dark:border-slate-600 dark:bg-slate-600"
                :class="$store.sound?.enabled && '!border-rt-red !bg-rt-red'"
            >
                <span
                    class="absolute left-1 top-1/2 flex h-5 w-5 -translate-y-1/2 items-center justify-center rounded-full bg-white text-slate-600 shadow-sm transition-transform duration-300 ease-rt-spring"
                    :class="$store.sound?.enabled && 'translate-x-[22px] text-rt-red'"
                    aria-hidden="true"
                >
                    <i class="far fa-volume text-[10px]" x-show.important="$store.sound?.enabled"></i>
                    <i class="far fa-volume-slash text-[10px]" x-show.important="!$store.sound?.enabled" x-cloak></i>
                </span>
            </button>
        </div>
    </div>
</section>
