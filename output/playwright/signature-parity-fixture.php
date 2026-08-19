<?php

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$signature = App\Support\MailSignature::forCompany('light', animated: true, remoteAssets: true);
$values = $signature->values([], App\Support\CompanyData::defaults());
$assetBase = 'http://127.0.0.1:41741/public/mail-assets/';
$values = array_merge($values, [
    'LOGO_SRC' => $assetBase.'wortmarke-signature-light.gif',
    'LOGO_STILL_SRC' => $assetBase.'wortmarke-signature-light.png',
    'TRAIN_SRC' => $assetBase.'zug-dampf-light.gif',
    'GRUND_RASTER_SRC' => $assetBase.'signatur-raster-light.png',
    'GRUND_MARKE_SRC' => $assetBase.'signatur-marke-light.png',
    'ICON_PHONE_SRC' => $assetBase.'contact-phone.png',
    'ICON_MOBILE_SRC' => $assetBase.'contact-mobile.png',
    'ICON_EMAIL_SRC' => $assetBase.'contact-email.png',
    'ICON_LOCATION_SRC' => $assetBase.'contact-location.png',
    'ICON_WEB_SRC' => $assetBase.'contact-web.png',
]);
$fragment = Illuminate\Support\Facades\View::make('emails.parts.signature', [
    'values' => $values,
])->render();

?><!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <style>
        html, body { margin: 0; padding: 0; background: #e7eaed; font-family: Arial, sans-serif; }
        body, .frame { width: 1920px; }
        .frame { padding: 28px; box-sizing: border-box; }
        table { border-collapse: collapse; }
    </style>
</head>
<body>
    <div class="frame">
        <table role="presentation" width="100%"><tbody><?= $fragment ?></tbody></table>
    </div>
</body>
</html>
