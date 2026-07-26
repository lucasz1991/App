{{-- Icons, Manifest und Theme-Color liegen in einem eigenen Partial, damit
     auch Fehlerseiten sie ohne Auth-/Session-/DB-Zugriff einbinden koennen. --}}
@include('layouts.icon-head')

<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="{{ config('app.name', 'RailTime') }}">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="rt-service-worker-url" content="{{ asset('service-worker.js') }}">
@auth
    <meta
        name="rt-push-account-binding"
        content="{{ \App\Support\Push\WebPushConfiguration::accountBinding(auth()->id()) }}"
    >
@endauth
