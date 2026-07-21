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

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer>
© {{ date('Y') }} {{ config('app.name') }}. {{ __('app.mail_all_rights') }}
{{-- PHP schluckt das Newline nach dem schliessenden echo-Tag — die doppelte
     Leerzeile stellt sicher, dass Markdown hier zwei Absaetze erzeugt. --}}

{{ __('app.mail_confidentiality') }}
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
