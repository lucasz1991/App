{{-- Sammelvorlage fuer alle 5xx ohne eigene Datei. --}}
@include('errors.partials.page', [
    'status' => $exception?->getStatusCode() ?? 500,
])
