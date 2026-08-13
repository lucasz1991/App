<x-mail::layout>
{{-- Body --}}
{{ $slot }}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{{ $subcopy }}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Die veröffentlichte Nachrichtenschale fügt ihren kanonischen
     Signaturblock nach dem APPLICATION_CONTENT-Slot selbst ein. --}}
</x-mail::layout>
