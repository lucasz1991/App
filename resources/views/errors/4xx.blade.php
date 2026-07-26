{{-- Sammelvorlage fuer alle 4xx ohne eigene Datei. Laravel loest zuerst
     errors/{status} und dann errors/4xx auf (Foundation\Exceptions\Handler::
     getHttpExceptionView). --}}
@include('errors.partials.page', [
    'status' => $exception?->getStatusCode() ?? 400,
])
