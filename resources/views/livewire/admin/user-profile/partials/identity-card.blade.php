{{-- Identitaets-Card: links die Person, rechts die Eckdaten.
     Auf dem Telefon bleibt sie bewusst flach, damit das Tab-Menue
     darunter ohne Scrollen sichtbar bleibt. --}}
<div class="relative overflow-hidden rounded-2xl bg-rt-surface p-4 shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60 sm:p-5" data-anim="fade-up" data-rt-glow>
  <span class="rt-profile-aurora" aria-hidden="true"></span>

  @php
      $identityFacts = array_values(array_filter([
          $profile?->position ? ['icon' => 'far fa-user-tag', 'label' => __('app.position'), 'value' => $profile->position] : null,
          ['icon' => 'far fa-users', 'label' => __('app.team'), 'value' => $user->currentTeam?->name ?: '—'],
          $canViewMasterData && $profile?->personnel_nr
              ? ['icon' => 'far fa-id-badge', 'label' => __('app.personnel_nr'), 'value' => $profile->personnel_nr]
              : null,
          ['icon' => 'far fa-calendar-alt', 'label' => __('app.member_since'), 'value' => $user->created_at?->format('d.m.Y') ?: '—'],
          ['icon' => 'far fa-clock', 'label' => __('app.last_online'), 'value' => $lastActivityAt ? $lastActivityAt->format('d.m.Y H:i') : __('app.never')],
          ['icon' => 'far fa-hashtag', 'label' => __('app.user_id'), 'value' => (string) $user->id],
      ]));
  @endphp

  <div class="relative grid gap-4 sm:grid-cols-2 sm:gap-6">
      {{-- Spalte 1: Person --}}
      <div class="flex min-w-0 items-center gap-3 sm:gap-4">
          <span class="relative shrink-0">
              <img
                  src="{{ $user->profile_photo_url }}"
                  alt="{{ $user->name }}"
                  class="h-14 w-14 rounded-2xl object-cover shadow-rt-sm ring-1 ring-rt-border/60 dark:ring-rt-dark-border/60 sm:h-20 sm:w-20"
              >
              @if ($isUserOnline)
                  <span
                      class="absolute -bottom-1 -right-1 grid h-4 w-4 place-items-center rounded-full bg-rt-surface dark:bg-rt-dark-surface"
                      title="{{ __('app.online') }}"
                  >
                      <span class="h-2.5 w-2.5 rounded-full bg-emerald-500 dark:bg-emerald-400"></span>
                  </span>
              @endif
          </span>

          <div class="min-w-0 flex-1">
              <div class="min-w-[10rem] max-w-full">
                  <x-ui.inline-edit-field
                      id="employee-header-name"
                      field="name"
                      type="text"
                      :can-edit="$canEditEmployee"
                      autocomplete="name"
                      align="left"
                  >
                      <span class="truncate text-lg font-semibold tracking-tight sm:text-xl">{{ $user->name }}</span>
                  </x-ui.inline-edit-field>
              </div>

              <div class="mt-0.5 max-w-full">
                  <x-ui.inline-edit-field
                      id="employee-header-email"
                      field="email"
                      type="email"
                      :can-edit="$canEditEmployee"
                      autocomplete="email"
                      align="left"
                  >
                      <span class="truncate text-sm text-rt-muted dark:text-rt-dark-muted">{{ $user->email }}</span>
                  </x-ui.inline-edit-field>
              </div>

              <div class="mt-2 flex flex-wrap items-center gap-1.5">
                  <x-ui.badge :color="$roleColor">
                      <i class="far fa-user-tag"></i>
                      {{ $roleLabel }}
                  </x-ui.badge>

                  <x-ui.badge :color="$user->isActive() ? 'green' : 'red'">
                      <span class="h-1.5 w-1.5 rounded-full {{ $user->isActive() ? 'bg-emerald-500 dark:bg-emerald-400' : 'bg-red-500 dark:bg-red-400' }}"></span>
                      {{ $user->isActive() ? ucfirst(__('app.active')) : ucfirst(__('app.inactive')) }}
                  </x-ui.badge>

                  @if ($isUserOnline)
                      <x-ui.badge color="green">
                          <span class="h-1.5 w-1.5 rounded-full bg-emerald-500 dark:bg-emerald-400"></span>
                          {{ __('app.online') }}
                      </x-ui.badge>
                  @endif
              </div>
          </div>
      </div>

      {{-- Spalte 2: Eckdaten. Mobil nur die beiden wichtigsten Zeilen,
           damit die Karte flach bleibt. --}}
      <dl class="grid grid-cols-1 gap-x-6 gap-y-2 border-t border-rt-border/60 pt-3 text-sm dark:border-rt-dark-border/60 sm:border-l sm:border-t-0 sm:pl-6 sm:pt-0 lg:grid-cols-2">
          @foreach ($identityFacts as $index => $fact)
              <div @class([
                  'flex min-w-0 items-center justify-between gap-3',
                  'hidden sm:flex' => $index >= 2,
              ])>
                  <dt class="flex shrink-0 items-center gap-2 text-xs text-rt-muted dark:text-rt-dark-muted">
                      <i class="{{ $fact['icon'] }} w-3.5 text-center" aria-hidden="true"></i>
                      {{ $fact['label'] }}
                  </dt>
                  <dd class="min-w-0 truncate text-right text-sm font-semibold text-rt-text dark:text-rt-dark-text">
                      {{ $fact['value'] }}
                  </dd>
              </div>
          @endforeach
      </dl>
  </div>
</div>
