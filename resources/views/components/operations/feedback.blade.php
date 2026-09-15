<div aria-live="polite">
    @if($errors->any())
        <div role="alert" class="ops-errors">@foreach(array_unique($errors->all()) as $message)<p>{{ $message }}</p>@endforeach</div>
    @endif
    @if(session('operations.saved'))<p role="status">{{ session('operations.saved') }}</p>@endif
    <p wire:offline class="ops-errors">Offline · Änderungen können gerade nicht gespeichert werden.</p>
</div>
