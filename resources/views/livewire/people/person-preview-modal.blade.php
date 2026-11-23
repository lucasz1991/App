<div data-testid="person-preview-host">
    <x-dialog-modal
        id="person-preview-modal"
        wire:model="isOpen"
        max-width="md"
        :description="$person ? ($person->profile?->position ?: __('app.position_not_set')) : null"
    >
        <x-slot:title>
            @if ($person)
                <span class="flex min-w-0 items-center gap-3">
                    <x-chat.avatar
                        :src="$person->profile_photo_url"
                        :name="$person->name"
                        size="lg"
                    />
                    <span class="min-w-0">
                        <span class="block text-[10px] font-bold uppercase tracking-[0.14em] text-rt-red dark:text-rt-dark-accent">{{ __('app.person_preview') }}</span>
                        <span id="person-preview-title" class="block truncate">{{ $person->name }}</span>
                    </span>
                </span>
            @else
                {{ __('app.person_preview') }}
            @endif
        </x-slot:title>

        <x-slot:content>
            @if ($person)
            <article
                class="relative min-w-0"
                wire:key="person-preview-{{ $person->id }}"
                data-testid="person-preview-modal"
            >
                @php
                    $viewer = auth()->user();
                    $canReachPerson = $person->isActive() && ! $person->is($viewer);
                    $canCallPerson = $canReachPerson
                        && ($viewer->isAdmin() || $viewer->hasRbacPermission('calls.start'))
                        && ($person->isAdmin() || $person->hasRbacPermission('calls.join'));
                @endphp

                <div class="relative">
                    <x-user.quick-actions
                        :user="$person"
                        :profile-url="$viewer->canViewManagementDashboard()
                            ? route($viewer->usesAdminLayout() ? 'admin.user-profile' : 'employees.show', $person->id)
                            : null"
                        :can-chat="$canReachPerson"
                        :can-call="$canCallPerson"
                        :can-message="$canReachPerson && $viewer->can('users.messages.create')"
                        chat-action="startChat"
                        call-action="startPreviewCall"
                        message-action="messagePerson"
                        variant="grid"
                    />
                </div>
            </article>
            @endif
        </x-slot:content>
    </x-dialog-modal>
</div>
