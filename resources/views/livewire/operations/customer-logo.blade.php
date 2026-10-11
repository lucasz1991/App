<section class="min-w-0 space-y-4" aria-labelledby="customer-logo-title-{{ $this->getId() }}" data-customer-logo-settings>
    <header class="flex min-w-0 items-center gap-3">
        <x-operations.company-avatar :customer="$customer" :size="56" :portal="$portal" />
        <div class="min-w-0"><h3 id="customer-logo-title-{{ $this->getId() }}" class="text-base font-semibold">Firmenlogo</h3><p class="mt-1 text-xs text-rt-muted dark:text-rt-dark-muted">Für die Kunden- und Leistungsübersicht.</p></div>
    </header>
    @if(!$ready)
        <p class="text-xs text-rt-muted dark:text-rt-dark-muted" role="status">Die Logo-Einstellung wird nach der technischen Einrichtung verfügbar.</p>
    @elseif($canEdit)
        <form wire:submit="save" class="space-y-3">
            <div class="min-w-0 space-y-1.5">
                <x-ui.forms.label :for="'customer-logo-upload-'.$this->getId()" value="Logo auswählen" />
                <x-ui.forms.input :id="'customer-logo-upload-'.$this->getId()" class="max-w-full" type="file" wire:model="logoFile" accept="image/jpeg,image/png,image/webp" :aria-describedby="'customer-logo-help-'.$this->getId()" />
                <p id="customer-logo-help-{{ $this->getId() }}" class="text-xs text-rt-muted dark:text-rt-dark-muted">PNG, JPG oder WebP · bis 2 MB und 2048 × 2048 px. Ohne Logo werden die Firmeninitialen angezeigt.</p>
                <x-ui.forms.input-error for="logoFile" />
                <p class="text-xs text-rt-muted dark:text-rt-dark-muted" wire:loading wire:target="logoFile" role="status">Logo wird hochgeladen …</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <x-ui.buttons.button-basic type="submit" mode="primary" :disabled="!$logoFile" wire:loading.attr="disabled" wire:target="logoFile,save,remove"><i class="far fa-check" aria-hidden="true"></i>Logo speichern</x-ui.buttons.button-basic>
                @if($customer->hasValidLogoPath())<x-ui.buttons.button-basic type="button" wire:click="remove" wire:confirm="Firmenlogo entfernen und wieder Firmeninitialen anzeigen?" wire:loading.attr="disabled" wire:target="logoFile,save,remove"><i class="far fa-trash-alt" aria-hidden="true"></i>Entfernen</x-ui.buttons.button-basic>@endif
                @if($customer->logo_path !== $expectedPath)<x-ui.buttons.button-basic type="button" wire:click="reload" wire:loading.attr="disabled"><i class="far fa-sync" aria-hidden="true"></i>Logo neu laden</x-ui.buttons.button-basic>@endif
            </div>
        </form>
    @else
        <p class="text-xs text-rt-muted dark:text-rt-dark-muted">Die Logo-Einstellung kann die Kundenkoordination oder ein Administrator ändern.</p>
    @endif
</section>
