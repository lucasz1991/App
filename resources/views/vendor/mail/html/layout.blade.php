@php
    /*
     * Laravel rendert Slot und Subcopy zuerst mit seinen sicheren Mail-
     * Komponenten. Erst danach setzt EmailTemplateBuilder diesen Htmlable-
     * Inhalt in den verpflichtenden APPLICATION_CONTENT-Slot der
     * veröffentlichten Datenbankvorlage ein.
     */
    $applicationContent = new \Illuminate\Support\HtmlString(
        (string) \Illuminate\Mail\Markdown::parse($slot)
        .(string) ($subcopy ?? '')
    );
@endphp
{!! \App\Support\EmailTemplateBuilder::buildSystemMailHtml($applicationContent) !!}
