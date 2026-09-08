<div role="group" aria-label="Verwendung ändern" data-mail-delivery-menu>
    <p class="px-3 pb-1 pt-2 text-xs font-semibold text-rt-muted dark:text-rt-dark-muted">Verwendung ändern</p>
    @if (! $document->isPublished())
        <p class="p-3 text-xs text-rt-muted dark:text-rt-dark-muted">Zuerst einen Stand veröffentlichen. Danach können die Verwendungsbereiche getrennt zugeordnet werden.</p>
    @elseif (empty($availableActions))
        <p class="p-3 text-xs text-rt-muted dark:text-rt-dark-muted">Bereits als Standard zugeordnet. Zum Wechseln eine andere veröffentlichte Fassung auswählen.</p>
    @else
        @foreach ($availableActions as $action => [$label, $explanation])
            <button type="button" role="menuitem" class="rt-mail-library-menu__item" wire:click="prepare('{{ $action }}', '{{ $document->content_hash }}', '{{ $token }}')" wire:loading.attr="disabled" wire:target="prepare,preparePublish,confirm" x-on:click="close()">{{ $label }}</button>
        @endforeach
    @endif
</div>
