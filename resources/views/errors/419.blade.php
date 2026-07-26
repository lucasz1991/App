@php
    /**
     * Abgelaufene Sitzung (CSRF-Token). Die Seite stellt sich SOFORT und ohne
     * Rueckfrage selbst wieder her.
     *
     * Warum nicht location.reload(): diese Antwort gehoert zu einem
     * fehlgeschlagenen POST. Ein Reload wuerde den POST erneut senden. Deshalb
     * wird per GET auf die Ausgangsseite gewechselt — dort holt sich die
     * Anwendung eine frische Sitzung samt neuem Token, und weil ein GET keinen
     * CSRF-Schutz hat, kann daraus keine Schleife entstehen.
     *
     * Das Ziel wird auf die eigene Anwendung begrenzt, damit ein fremder
     * Referer keine offene Weiterleitung erzeugt.
     *
     * Livewire-Anfragen laufen NICHT hierueber: die behandelt der 419-Hook in
     * resources/js/app.js, bevor Livewire seinen confirm()-Dialog zeigt.
     */
    $referer = (string) request()->headers->get('referer', '');
    $home = url('/');
    $target = ($referer !== '' && str_starts_with($referer, $home)) ? $referer : $home;
    $targetJs = \Illuminate\Support\Js::from($target);

    // Zwei Wege, absichtlich abgestimmt:
    //  * JavaScript leitet sofort weiter (ohne Verlaufseintrag) — und entfernt
    //    die Meta-Weiterleitung, wenn der Schleifenschutz greift.
    //  * Ohne JavaScript uebernimmt die Meta-Weiterleitung nach 3 Sekunden.
    $headExtra = '<meta http-equiv="refresh" content="3;url='.e($target).'">'
        .'<script>(function(){'
        .'var stop=function(){var m=document.querySelector(\'meta[http-equiv="refresh"]\');if(m&&m.parentNode){m.parentNode.removeChild(m);}};'
        .'try{'
        .'var k="rt-419-recovered-at",l=Number(sessionStorage.getItem(k)||0);'
        // Kurzzeitfenster: schuetzt vor einer Schleife, laesst einen spaeteren
        // echten Ablauf aber weiterhin automatisch behandeln.
        .'if(l&&(Date.now()-l)<10000){stop();return;}'
        .'sessionStorage.setItem(k,String(Date.now()));'
        .'}catch(e){}'
        .'window.location.replace('.$targetJs.');'
        .'})();</script>';

    $body = '<p class="rt-error__text" style="margin-top:.75rem">'
        .e(__('app.session_restoring'))
        .'</p>';
@endphp

@include('errors.partials.page', [
    'status' => 419,
    'headExtra' => $headExtra,
    'body' => $body,
    'hint' => __('app.session_restore_hint'),
])
