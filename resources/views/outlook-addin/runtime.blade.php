@php
    $resolvedConfigUrl = (string) ($configUrl ?? $outlookAddinConfigUrl ?? '');
    $resolvedScriptUrl = (string) ($scriptUrl ?? asset('outlook-addin/runtime.js'));
@endphp
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-outlook-config-url="{{ $resolvedConfigUrl }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="railtime-outlook-config-url" content="{{ $resolvedConfigUrl }}">
    <title>RailTime Outlook Runtime</title>
    <script src="https://appsforoffice.microsoft.com/lib/1/hosted/office.js" type="text/javascript"></script>
    <script src="{{ $resolvedScriptUrl }}" type="text/javascript" defer></script>
</head>
<body data-outlook-config-url="{{ $resolvedConfigUrl }}" hidden>
    <p>RailTime Outlook Runtime</p>
</body>
</html>
