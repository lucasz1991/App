@if($item->status === 'submitted' && $item->user_id !== auth()->id())
<x-ui.forms.checkbox wire:model.live="selected" :value="$item->id" :aria-label="'Zeitmeldung '.$item->id.' auswählen'" />
@endif
