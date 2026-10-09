{{-- Eine zusammenhaengende Identitaet mit nachgeordneten Eckdaten. --}}
<header class="employee-profile__identity" data-anim="fade-up">

  @php
      $teamName = $user->currentTeam?->name;

      // Rolle, Funktion und Team stehen als Badges beieinander. Ein Team
      // heisst haeufig genauso wie die Rolle ("Mitarbeiter") — dann waere
      // dieselbe Angabe zweimal zu sehen.
      $identityBadges = [];
      $seen = [];

      foreach ([
          ['value' => $roleLabel, 'color' => $roleColor, 'icon' => 'far fa-user-tag', 'title' => __('app.role')],
          ['value' => $profile?->position, 'color' => 'slate', 'icon' => 'far fa-briefcase', 'title' => __('app.position')],
          ['value' => $teamName, 'color' => 'slate', 'icon' => 'far fa-users', 'title' => __('app.team')],
      ] as $badge) {
          $value = trim((string) $badge['value']);
          $key = mb_strtolower($value);

          if ($value === '' || in_array($key, $seen, true)) {
              continue;
          }

          $seen[] = $key;
          $identityBadges[] = ['value' => $value] + $badge;
      }

      // Funktion und Team stehen links als Badges — rechts bleiben nur die
      // Angaben, die dort nicht schon zu sehen sind.
      $identityFacts = array_values(array_filter([
          $canViewMasterData && $profile?->personnel_nr
              ? ['icon' => 'far fa-id-badge', 'label' => __('app.personnel_nr'), 'value' => $profile->personnel_nr]
              : null,
          ['icon' => 'far fa-calendar-alt', 'label' => __('app.member_since'), 'value' => $user->created_at?->format('d.m.Y') ?: '—'],
          ['icon' => 'far fa-clock', 'label' => __('app.last_online'), 'value' => $lastActivityAt ? $lastActivityAt->format('d.m.Y H:i') : __('app.never')],
      ]));
  @endphp

  <div class="employee-profile__identity-layout">
      {{-- Spalte 1: Person. Der Kontostatus haengt am Bild statt als Badge
           unter der Adresse: gruener oder grauer Punkt fuer die Anwesenheit,
           ein rotes Zeichen nur dann, wenn das Konto gesperrt ist. --}}
      <div class="employee-profile__person">
          <span class="employee-profile__portrait relative shrink-0">
              <img
                  src="{{ $user->profile_photo_url }}"
                  alt="{{ $user->name }}"
                  @class([
                      'object-cover',
                      'opacity-60 grayscale' => ! $user->isActive(),
                  ])
              >

              @unless ($user->isActive())
                  <span
                      class="absolute -right-1 -top-1 grid h-5 w-5 place-items-center rounded-full bg-rt-red text-[10px] text-white ring-2 ring-rt-surface dark:ring-rt-dark-surface"
                      title="{{ ucfirst(__('app.inactive')) }}"
                  >
                      <i class="far fa-ban" aria-hidden="true"></i>
                      <span class="sr-only">{{ ucfirst(__('app.inactive')) }}</span>
                  </span>
              @endunless

          </span>

          <div class="employee-profile__person-copy">
              <p class="employee-profile__eyebrow">Personalakte <span aria-hidden="true">·</span> <span aria-label="{{ __('app.user_id') }}">#{{ $user->id }}</span></p>
              <div class="employee-profile__name">
                  <x-ui.inline-edit-field
                      id="employee-header-name"
                      field="name"
                      type="text"
                      :can-edit="$canEditEmployee"
                      autocomplete="name"
                      align="left"
                  >
                      <span class="employee-profile__name-text" title="{{ $user->name }}">{{ $user->name }}</span>
                  </x-ui.inline-edit-field>
              </div>

              <div class="employee-profile__email">
                  <x-ui.inline-edit-field
                      id="employee-header-email"
                      field="email"
                      type="email"
                      :can-edit="$canEditEmployee"
                      autocomplete="email"
                      align="left"
                  >
                      <span class="employee-profile__email-text" title="{{ $user->email }}">{{ $user->email }}</span>
                  </x-ui.inline-edit-field>
              </div>

                  <div class="employee-profile__identity-labels">
                      @foreach ($identityBadges as $badge)
                          <x-ui.badge :color="$badge['color']" class="!px-2 !py-0.5 !text-[11px]" :title="$badge['title']">
                              <i class="{{ $badge['icon'] }}" aria-hidden="true"></i>
                              {{ $badge['value'] }}
                          </x-ui.badge>
                      @endforeach
                      <span class="employee-profile__presence" data-online="{{ $user->isActive() && $isUserOnline ? 'true' : 'false' }}">
                          <span aria-hidden="true"></span>
                          {{ ! $user->isActive() ? ucfirst(__('app.inactive')) : ($isUserOnline ? __('app.online') : __('app.offline')) }}
                      </span>
                  </div>
          </div>
      </div>

      <dl class="employee-profile__facts">
          @foreach ($identityFacts as $index => $fact)
              <div class="employee-profile__fact">
                  <dt>
                      <i class="{{ $fact['icon'] }} w-3.5 text-center" aria-hidden="true"></i>
                      {{ $fact['label'] }}
                  </dt>
                  <dd title="{{ $fact['value'] }}">
                      {{ $fact['value'] }}
                  </dd>
              </div>
          @endforeach
      </dl>
  </div>
</header>
