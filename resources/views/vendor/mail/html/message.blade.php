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

{{-- Footer: der gemeinsame RailTime-Signaturblock. Er bringt Pflichtangaben
     UND den Vertraulichkeitshinweis bereits mit, deshalb bekommt er hier
     bewusst keinen eigenen Inhalt mehr. --}}
<x-slot:footer>
<x-mail::footer />
</x-slot:footer>
</x-mail::layout>
