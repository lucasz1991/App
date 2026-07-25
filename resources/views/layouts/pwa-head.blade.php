<link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
<link rel="apple-touch-icon" sizes="180x180" href="{{ asset('icons/apple-touch-icon-180.png') }}">
<link rel="icon" type="image/png" sizes="192x192" href="{{ asset('icons/pwa-192.png') }}">
<meta name="theme-color" content="#f3f6fa" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#0b1120" media="(prefers-color-scheme: dark)">
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
