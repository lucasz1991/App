{{-- Eine zusammenhaengende Identitaet mit nachgeordneten Eckdaten. --}}
<header class="employee-profile__identity" data-profile-identity data-anim="fade-up">

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
          ['key' => 'status', 'icon' => 'far fa-shield-check', 'label' => 'Kontostatus', 'value' => $user->isActive() ? 'Aktiv' : 'Inaktiv', 'status' => $user->isActive() ? 'active' : 'inactive'],
          $canViewMasterData && $profile?->personnel_nr
              ? ['key' => 'personnel', 'icon' => 'far fa-id-badge', 'label' => __('app.personnel_nr'), 'value' => $profile->personnel_nr]
              : null,
          ['key' => 'member', 'icon' => 'far fa-calendar-alt', 'label' => __('app.member_since'), 'value' => $user->created_at?->format('d.m.Y') ?: '—'],
          ['key' => 'activity', 'icon' => 'far fa-clock', 'label' => __('app.last_online'), 'value' => $lastActivityAt ? $lastActivityAt->format('d.m.Y H:i') : __('app.never')],
      ]));

      // Schnellaktionen wie in der Personen-Schnellansicht (person-preview-modal).
      $viewer = auth()->user();
      $canReachPerson = $user->isActive() && ! $user->is($viewer);
      $canCallPerson = $canReachPerson
          && ($viewer->isAdmin() || $viewer->hasRbacPermission('calls.start'))
          && ($user->isAdmin() || $user->hasRbacPermission('calls.join'));
      $mobileNumber = trim((string) ($profile?->mobile ?: $profile?->phone));
  @endphp

  <div class="employee-profile__identity-layout">
      {{-- Identitaet und Bearbeitungsfelder bleiben nativ. Kontostatus und
           aktuelle Praesenz sind bewusst zwei getrennte Angaben. --}}
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
              <span class="employee-profile__presence" data-online="{{ $user->isActive() && $isUserOnline ? 'true' : 'false' }}">
                  <span aria-hidden="true"></span>
                  <span class="sr-only">{{ ! $user->isActive() ? ucfirst(__('app.inactive')) : ($isUserOnline ? __('app.online') : __('app.offline')) }}</span>
              </span>
          </span>

          <div class="employee-profile__person-copy">
            <div class="employee-profile__name-line">
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
              <p class="employee-profile__eyebrow"><span class="sr-only">Personalakte</span> <span class="employee-profile__record-number" aria-label="{{ __('app.user_id') }}">#{{ $user->id }}</span></p>
            </div>
            <div class="employee-profile__meta-line">
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
                          <x-ui.badge :color="$badge['color']" class="employee-profile__identity-badge" :title="$badge['title']">
                              <i class="{{ $badge['icon'] }}" aria-hidden="true"></i>
                              {{ $badge['value'] }}
                          </x-ui.badge>
                      @endforeach
              </div>
            </div>
          </div>
      </div>

      @if ($canReachPerson || $mobileNumber !== '')
          <div class="employee-profile__actions" data-profile-actions>
              <x-user.quick-actions
                  :user="$user"
                  :can-chat="$canReachPerson"
                  :can-call="$canCallPerson"
                  :can-message="$canReachPerson && $viewer->can('users.messages.create')"
                  chat-action="openDirectChat"
                  call-action="startDirectCall"
                  message-action="openMessage"
                  variant="grid"
              />
              @if ($mobileNumber !== '')
                  <a href="tel:{{ preg_replace('/[^0-9+]/', '', $mobileNumber) }}" class="employee-profile__contact-link">
                      <i class="far fa-mobile-alt" aria-hidden="true"></i>
                      <span>{{ $mobileNumber }}</span>
                  </a>
              @endif
          </div>
      @endif

      <section class="employee-profile__status" data-profile-status aria-labelledby="{{ $profilePrefix }}-status-title">
          <h2 id="{{ $profilePrefix }}-status-title">Einrichtungs Status</h2>
          <div class="employee-profile__status-rail">
              @if($phoneStatus !== null)
                  <livewire:profile.employee-phone-status :user-id="(int) $user->id" :profile-prefix="$profilePrefix" :key="$profilePrefix.'-phone-status-'.$user->id" />
              @endif
              <dl class="employee-profile__status-group employee-profile__facts" aria-label="Konto und Aktivität">
                  @foreach ($identityFacts as $fact)
                      <div class="employee-profile__status-item" wire:key="{{ $profilePrefix }}-status-account-{{ $fact['key'] }}" data-profile-fact="{{ $fact['key'] }}">
                          <dt>
                              @include('livewire.admin.user-profile.partials.status-indicator', [
                                  'indicatorKey' => 'account-'.$fact['key'],
                                  'indicatorLabel' => $fact['label'],
                                  'indicatorValue' => $fact['value'],
                                  'indicatorIcon' => $fact['icon'],
                                  'indicatorState' => $fact['status'] ?? ($fact['key'] === 'activity' && ! $lastActivityAt ? 'unknown' : 'info'),
                                  'indicatorNote' => null,
                                  'indicatorEvidence' => null,
                                  'indicatorPhoneCount' => null,
                              ])
                          </dt>
                          <dd class="sr-only" @if(isset($fact['status'])) data-profile-account-status="{{ $fact['status'] }}" @endif>{{ $fact['value'] }}</dd>
                      </div>
                  @endforeach
              </dl>
          </div>
      </section>
  </div>
</header>
