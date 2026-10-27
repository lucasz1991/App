@props(['id' => 'team-rbac-create', 'focusInPlace' => true])

<form id="{{ $id }}" wire:submit="createTeam" class="space-y-4" data-team-create-form>
    <div>
        <x-ui.forms.label :for="$id.'-name'" value="Teamname" />
        <x-ui.forms.input
            :id="$id.'-name'"
            type="text"
            wire:model="newTeamName"
            x-init="{{ $focusInPlace ? 'true' : 'false' }} && $nextTick(() => $el.focus({ preventScroll: true }))"
            maxlength="255"
            placeholder="Teamname eingeben"
            autocomplete="off"
            required
            :aria-describedby="$id.'-hint '.$id.'-error'"
            :aria-invalid="$errors->has('newTeamName') ? 'true' : 'false'"
            class="mt-2 w-full"
        />
        <div id="{{ $id }}-error" role="status">
            <x-input-error for="newTeamName" class="mt-2" />
        </div>
    </div>
    <p id="{{ $id }}-hint" class="text-sm leading-6 text-rt-muted dark:text-rt-dark-muted">Neue Teams starten ohne freigegebene Rechte. Anschließend Rechte auswählen und speichern.</p>
</form>
