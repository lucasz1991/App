<?php

namespace App\Services\Marketing;

use App\Enums\MarketingCreativeFormat;
use App\Enums\MarketingCreativeType;
use App\Support\CompanyData;

final class MarketingTemplateFactory
{
    /**
     * @return array{
     *     title: string,
     *     shared_content: array<string, mixed>,
     *     variants: array<string, array{builder_data: array<string, mixed>, html: string, css: string}>
     * }
     */
    public function definition(MarketingCreativeType $type): array
    {
        $company = CompanyData::all();
        $sharedContent = $type === MarketingCreativeType::Job
            ? $this->jobContent($company)
            : $this->infoContent($company);

        $variants = [];
        foreach (MarketingCreativeFormat::cases() as $format) {
            $html = $type === MarketingCreativeType::Job
                ? $this->jobHtml($format)
                : $this->infoHtml($format);
            $css = $this->css($format, $type);

            $variants[$format->value] = [
                'builder_data' => [
                    'pages' => [[
                        'name' => ucfirst($format->value),
                        'component' => $html,
                    ]],
                    'styles' => [],
                    'railtime' => [
                        'template' => $type->value,
                        'format' => $format->value,
                        'schema' => 2,
                    ],
                ],
                'html' => $html,
                'css' => $css,
            ];
        }

        return [
            'title' => $type === MarketingCreativeType::Job
                ? 'Wagenmeister (m/w/d) – Entwurf'
                : 'Wagenmeister-Service – 24/7',
            'shared_content' => $sharedContent,
            'variants' => $variants,
        ];
    }

    /**
     * @param  array<string, string>  $company
     * @return array<string, mixed>
     */
    private function jobContent(array $company): array
    {
        return [
            'template_key' => 'railtime_job_wagenmeister',
            'kicker' => 'Komm ins Team',
            'title' => 'Wagenmeister (m/w/d)',
            'subtitle' => 'Deutschlandweit im Einsatz',
            'intro' => 'Du prüfst Güterwagen mit geschultem Blick, dokumentierst zuverlässig und sorgst gemeinsam mit unserem Team für einen sicheren Bahnbetrieb.',
            'facts' => [
                ['value' => '60+', 'label' => 'Wagenmeister'],
                ['value' => '24/7', 'label' => 'Einsatzbereitschaft'],
                ['value' => 'DE', 'label' => 'deutschlandweit'],
            ],
            'tasks' => [
                'Technische Untersuchung von Güterwagen und Zügen',
                'Bremsproben, Wagendokumentation und Schadenerfassung',
                'Abstimmung mit Disposition, Betrieb und Werkstätten',
            ],
            'profile' => [
                'Abgeschlossene Ausbildung oder Qualifikation als Wagenmeister',
                'Verantwortungsbewusstsein und zuverlässige Arbeitsweise',
                'Bereitschaft zu wechselnden Einsatzzeiten und Einsatzorten',
                'Teamgeist und klare Kommunikation',
            ],
            'benefits' => [
                'Unbefristete Perspektive',
                'Planbare und abwechslungsreiche Einsätze',
                'Wertschätzendes Team mit direkter Kommunikation',
                'Zeitgemäße Arbeitsmittel und fachliche Weiterentwicklung',
            ],
            'cta_label' => 'Jetzt bewerben',
            'cta_url' => 'https://www.rail-time.de/de/karriere',
            'contact_phone' => $company['phone'],
            'contact_email' => $company['email'],
            'website' => preg_replace('#^https?://#i', '', rtrim($company['website'], '/')),
            'company_name' => $company['name'],
            'company_address' => CompanyData::addressLine($company),
            'editorial_note' => 'Aufgaben, Anforderungen und Vorteile sind ein realistischer HR-Entwurf und vor Veröffentlichung zu bestätigen.',
        ];
    }

    /**
     * @param  array<string, string>  $company
     * @return array<string, mixed>
     */
    private function infoContent(array $company): array
    {
        return [
            'template_key' => 'railtime_info_wagenmeister',
            'kicker' => 'Sicher auf der Schiene',
            'title' => 'Wagenmeister-Service',
            'subtitle' => 'Verlässlich. Erfahren. Deutschlandweit.',
            'intro' => 'RailTime unterstützt Eisenbahnverkehrsunternehmen mit qualifizierten Wagenmeistern, flexibler Einsatzplanung und einem rund um die Uhr erreichbaren Notfalldienst.',
            'facts' => [
                ['value' => '60+', 'label' => 'Wagenmeister'],
                ['value' => '24/7', 'label' => 'Einsatzbereitschaft'],
                ['value' => 'DE', 'label' => 'deutschlandweit'],
            ],
            'tasks' => [
                'Technische Wagenuntersuchungen und Bremsproben',
                'Dokumentation, Schadenerfassung und betriebliche Abstimmung',
                'Kurzfristige Unterstützung und planbare Dauereinsätze',
            ],
            'profile' => [
                'Qualifiziertes und erfahrenes Fachpersonal',
                'Flexible Koordination passend zu Ihrem Betrieb',
                'Klare Kommunikation und verlässliche Dokumentation',
            ],
            'benefits' => [
                'Deutschlandweite Einsatzmöglichkeiten',
                'Persönliche Disposition',
                '24/7-Notfalldienst',
            ],
            'cta_label' => 'Einsatz anfragen',
            'cta_url' => 'https://www.rail-time.de/de/kontakt',
            'contact_phone' => $company['phone'],
            'contact_email' => $company['email'],
            'website' => preg_replace('#^https?://#i', '', rtrim($company['website'], '/')),
            'company_name' => $company['name'],
            'company_address' => CompanyData::addressLine($company),
            'editorial_note' => '',
        ];
    }

    private function jobHtml(MarketingCreativeFormat $format): string
    {
        $logo = $this->brandLockup();

        return match ($format) {
            MarketingCreativeFormat::Story => <<<HTML
<main class="rt-marketing-canvas rt-job rt-job-story">
  <section class="rt-hero">
    {$logo}
    <div class="rt-copy">
      <p class="rt-kicker" data-rt-binding="kicker"></p>
      <h1 data-rt-binding="title"></h1>
      <p class="rt-subtitle" data-rt-binding="subtitle"></p>
      <p class="rt-intro" data-rt-binding="intro"></p>
    </div>
    <div class="rt-photo"><img class="rt-photo-image" src="/rt-brand/img/hero-railtime.jpg" alt="Lokführer im Führerstand mit Blick auf die Strecke"><span>RAIL · PEOPLE · SAFETY</span></div>
  </section>
  <section class="rt-facts" data-rt-binding-facts="facts"></section>
  <section class="rt-details">
    <article><p class="rt-section-number">01</p><h2>Deine Aufgaben</h2><ul data-rt-binding-list="tasks"></ul></article>
    <article><p class="rt-section-number">02</p><h2>Dein Profil</h2><ul data-rt-binding-list="profile"></ul></article>
  </section>
  <section class="rt-benefits"><h2>Darauf kannst du zählen</h2><ul data-rt-binding-list="benefits"></ul></section>
  <footer class="rt-footer">
    <div><p class="rt-footer-kicker">Interesse geweckt?</p><p class="rt-footer-title">Deine Zukunft. Unsere gemeinsame Fahrt.</p><p><span data-rt-binding="contact_phone"></span> · <span data-rt-binding="contact_email"></span></p></div>
    <a class="rt-cta" data-rt-binding="cta_label" data-rt-binding-href="cta_url"></a>
    <p class="rt-company"><span data-rt-binding="company_name"></span> · <span data-rt-binding="company_address"></span> · <span data-rt-binding="website"></span></p>
  </footer>
</main>
HTML,
            MarketingCreativeFormat::Post => <<<HTML
<main class="rt-marketing-canvas rt-job rt-job-post">
  <div class="rt-photo rt-photo-full"><img class="rt-photo-image" src="/rt-brand/img/hero-railtime.jpg" alt="Lokführer im Führerstand mit Blick auf die Strecke"></div>
  <section class="rt-post-panel">
    {$logo}
    <p class="rt-kicker" data-rt-binding="kicker"></p>
    <h1 data-rt-binding="title"></h1>
    <p class="rt-subtitle" data-rt-binding="subtitle"></p>
    <p class="rt-intro" data-rt-binding="intro"></p>
    <div class="rt-post-signal"><strong>Dein Einsatz</strong><ul data-rt-binding-list="tasks"></ul></div>
    <a class="rt-cta" data-rt-binding="cta_label" data-rt-binding-href="cta_url"></a>
  </section>
  <section class="rt-post-band"><div class="rt-facts" data-rt-binding-facts="facts"></div><div class="rt-band-contact"><span data-rt-binding="contact_email"></span><strong data-rt-binding="website"></strong></div></section>
</main>
HTML,
            MarketingCreativeFormat::Web => <<<HTML
<main class="rt-marketing-canvas rt-job rt-job-web">
  <div class="rt-photo rt-photo-full"><img class="rt-photo-image" src="/rt-brand/img/hero-railtime.jpg" alt="Lokführer im Führerstand mit Blick auf die Strecke"></div>
  <section class="rt-web-copy">
    {$logo}
    <p class="rt-kicker" data-rt-binding="kicker"></p>
    <h1 data-rt-binding="title"></h1>
    <p class="rt-subtitle" data-rt-binding="subtitle"></p>
    <ul class="rt-web-signals" data-rt-binding-list="tasks"></ul>
    <div class="rt-web-actions"><a class="rt-cta" data-rt-binding="cta_label" data-rt-binding-href="cta_url"></a><span data-rt-binding="contact_email"></span></div>
  </section>
  <section class="rt-web-proof"><div class="rt-facts" data-rt-binding-facts="facts"></div></section>
</main>
HTML,
        };
    }

    private function infoHtml(MarketingCreativeFormat $format): string
    {
        $logo = $this->brandLockup();

        return match ($format) {
            MarketingCreativeFormat::Story => <<<HTML
<main class="rt-marketing-canvas rt-info rt-info-story">
  <section class="rt-info-hero">
    {$logo}
    <div class="rt-info-copy"><p class="rt-kicker" data-rt-binding="kicker"></p><h1 data-rt-binding="title"></h1><p class="rt-subtitle" data-rt-binding="subtitle"></p><p class="rt-intro" data-rt-binding="intro"></p></div>
    <div class="rt-photo"><img class="rt-photo-image" src="/rt-brand/img/hero-railtime.jpg" alt="Lokführer im Führerstand mit Blick auf die Strecke"><span>QUALITÄT IM BAHNBETRIEB</span></div>
  </section>
  <section class="rt-facts" data-rt-binding-facts="facts"></section>
  <section class="rt-info-services">
    <article><p class="rt-section-number">01</p><h2>Unser Service</h2><ul data-rt-binding-list="tasks"></ul></article>
    <article><p class="rt-section-number">02</p><h2>Warum RailTime</h2><ul data-rt-binding-list="benefits"></ul></article>
  </section>
  <section class="rt-info-contact"><div><p class="rt-footer-kicker">Direkt. Flexibel. Erreichbar.</p><strong>24/7 für Ihren Betrieb da.</strong><span data-rt-binding="contact_phone"></span><span data-rt-binding="contact_email"></span></div><a class="rt-cta" data-rt-binding="cta_label" data-rt-binding-href="cta_url"></a><footer><span data-rt-binding="company_name"></span><span data-rt-binding="company_address"></span><span data-rt-binding="website"></span></footer></section>
</main>
HTML,
            MarketingCreativeFormat::Post => <<<HTML
<main class="rt-marketing-canvas rt-info rt-info-post">
  <div class="rt-photo rt-photo-full"><img class="rt-photo-image" src="/rt-brand/img/hero-railtime.jpg" alt="Lokführer im Führerstand mit Blick auf die Strecke"></div>
  <section class="rt-info-post-lead">{$logo}<p class="rt-kicker" data-rt-binding="kicker"></p><h1 data-rt-binding="title"></h1><p class="rt-subtitle" data-rt-binding="subtitle"></p><p class="rt-intro" data-rt-binding="intro"></p><a class="rt-cta" data-rt-binding="cta_label" data-rt-binding-href="cta_url"></a></section>
  <section class="rt-info-post-band"><div class="rt-facts" data-rt-binding-facts="facts"></div><div class="rt-info-post-service"><strong>Wenn es zählt, sind wir da.</strong><ul data-rt-binding-list="benefits"></ul></div><footer><span data-rt-binding="contact_phone"></span><span data-rt-binding="contact_email"></span><span data-rt-binding="website"></span></footer></section>
</main>
HTML,
            MarketingCreativeFormat::Web => <<<HTML
<main class="rt-marketing-canvas rt-info rt-info-web">
  <div class="rt-photo rt-photo-full"><img class="rt-photo-image" src="/rt-brand/img/hero-railtime.jpg" alt="Lokführer im Führerstand mit Blick auf die Strecke"></div>
  <section class="rt-info-web-lead">
    {$logo}
    <p class="rt-kicker" data-rt-binding="kicker"></p><h1 data-rt-binding="title"></h1><p class="rt-subtitle" data-rt-binding="subtitle"></p><p class="rt-intro" data-rt-binding="intro"></p>
    <div class="rt-info-web-actions"><a class="rt-cta" data-rt-binding="cta_label" data-rt-binding-href="cta_url"></a><span data-rt-binding="contact_phone"></span></div>
  </section>
  <section class="rt-info-web-proof"><div class="rt-facts" data-rt-binding-facts="facts"></div><p><span data-rt-binding="contact_email"></span><strong data-rt-binding="website"></strong></p></section>
</main>
HTML,
        };
    }

    private function brandLockup(bool $forDarkBackground = false): string
    {
        $variant = $forDarkBackground ? 'reverse' : 'standard';
        $asset = $forDarkBackground
            ? '/rt-brand/img/logo-horizontal-darkbg.png'
            : '/rt-brand/img/logo-horizontal.png';

        return '<div class="rt-brand rt-brand-lockup rt-brand-lockup-'.$variant.'" data-rt-brand-lockup="official"><img class="rt-brand-logo" src="'.$asset.'" alt="RT Rail Time GmbH"></div>';
    }

    private function css(MarketingCreativeFormat $format, MarketingCreativeType $type): string
    {
        $dimensions = $format->dimensions();
        $base = <<<CSS
*{box-sizing:border-box}.rt-marketing-canvas{position:relative;overflow:hidden;width:{$dimensions['width']}px;height:{$dimensions['height']}px;margin:0;background:#f4f1ea;color:#102237;font-family:Arial,"Helvetica Neue",sans-serif}.rt-marketing-canvas h1,.rt-marketing-canvas h2,.rt-marketing-canvas p{margin:0}.rt-brand-lockup{position:relative;z-index:4;width:250px}.rt-brand-logo{display:block;width:100%;height:auto}.rt-kicker,.rt-footer-kicker{color:#d71932;font-weight:800;letter-spacing:.14em;text-transform:uppercase}.rt-subtitle{font-weight:700}.rt-cta{display:inline-flex;align-items:center;justify-content:center;border-radius:10px;background:#d71932;color:#fff;font-weight:800;text-decoration:none}.rt-photo{position:relative;overflow:hidden;background:#102237}.rt-photo-image{position:absolute;inset:0;width:100%;height:100%;object-fit:cover}.rt-photo:after{position:absolute;inset:0;background:linear-gradient(90deg,rgba(9,20,31,.04),rgba(9,20,31,.32));content:""}.rt-photo>span{position:absolute;z-index:1;color:#fff;font-weight:700;letter-spacing:.15em}.rt-facts{display:flex}.rt-facts>div{display:flex;flex:1;flex-direction:column}.rt-facts strong{color:#d71932}.rt-facts span{text-transform:uppercase;letter-spacing:.08em}.rt-marketing-canvas ul{margin:0;padding:0;list-style:none}.rt-marketing-canvas li{position:relative;padding-left:26px}.rt-marketing-canvas li:before{position:absolute;left:0;color:#d71932;content:"✓"}.rt-section-number{color:#d71932;font-size:14px;font-weight:800;letter-spacing:.18em}.rt-footer,.rt-marketing-canvas footer{position:relative}.rt-company{position:absolute}
CSS;

        $specific = match ([$type, $format]) {
            [MarketingCreativeType::Job, MarketingCreativeFormat::Story] => <<<'CSS'
.rt-job-story .rt-hero{position:relative;height:720px;padding:50px 60px;background:#f4f1ea}.rt-job-story .rt-brand-lockup{width:255px}.rt-job-story .rt-copy{position:absolute;z-index:2;top:146px;left:60px;width:54%}.rt-job-story .rt-kicker{font-size:18px}.rt-job-story h1{max-width:560px;margin-top:13px;font-size:74px;line-height:.92;letter-spacing:-.045em}.rt-job-story .rt-subtitle{margin-top:22px;font-size:27px}.rt-job-story .rt-intro{max-width:500px;margin-top:22px;font-size:18px;line-height:1.46}.rt-job-story .rt-photo{position:absolute;top:0;right:0;width:50%;height:720px;clip-path:polygon(21% 0,100% 0,100% 100%,0 100%)}.rt-job-story .rt-photo-image{object-position:66% center}.rt-job-story .rt-photo span{right:35px;bottom:30px;font-size:13px}.rt-job-story>.rt-facts{height:150px;padding:31px 60px;background:#123654;color:#fff;gap:52px}.rt-job-story>.rt-facts strong{font-size:43px;line-height:1}.rt-job-story>.rt-facts span{margin-top:9px;color:#fff;font-size:13px}.rt-job-story .rt-details{display:grid;height:590px;padding:52px 60px;background:#102237;color:#fff;grid-template-columns:1fr 1fr;gap:58px}.rt-job-story .rt-details article+article{padding-left:56px;border-left:1px solid rgba(255,255,255,.22)}.rt-job-story .rt-details h2{margin-top:8px;padding-bottom:16px;border-bottom:2px solid rgba(255,255,255,.2);font-size:25px;text-transform:uppercase}.rt-job-story .rt-details li{margin-top:21px;color:#edf2f5;font-size:19px;line-height:1.38}.rt-job-story .rt-benefits{display:grid;height:200px;padding:38px 60px;background:#f4f1ea;grid-template-columns:260px 1fr;gap:45px}.rt-job-story .rt-benefits h2{font-size:24px;line-height:1.15;text-transform:uppercase}.rt-job-story .rt-benefits ul{display:grid;grid-template-columns:1fr 1fr;gap:13px 34px}.rt-job-story .rt-benefits li{font-size:17px;line-height:1.3}.rt-job-story .rt-footer{height:260px;padding:38px 60px;background:#fff}.rt-job-story .rt-footer-kicker{font-size:13px}.rt-job-story .rt-footer-title{max-width:570px;margin-top:10px;font-size:29px;font-weight:800}.rt-job-story .rt-footer>div>p:last-child{margin-top:15px;font-size:17px}.rt-job-story .rt-cta{position:absolute;top:47px;right:60px;min-width:220px;padding:19px 28px;font-size:19px}.rt-job-story .rt-company{right:60px;bottom:28px;left:60px;padding-top:17px;border-top:1px solid #c9d0d6;font-size:14px}
CSS,
            [MarketingCreativeType::Job, MarketingCreativeFormat::Post] => <<<'CSS'
.rt-job-post{background:#102237}.rt-job-post .rt-photo-full{position:absolute;inset:0}.rt-job-post .rt-photo-image{object-position:69% center}.rt-job-post .rt-photo:after{background:linear-gradient(90deg,rgba(16,34,55,.06),rgba(16,34,55,.18) 60%,rgba(16,34,55,.42))}.rt-job-post .rt-post-panel{position:absolute;z-index:2;top:0;bottom:190px;left:0;width:70%;padding:48px 112px 42px 54px;background:#f4f1ea;clip-path:polygon(0 0,100% 0,86% 100%,0 100%)}.rt-job-post .rt-brand-lockup{width:240px}.rt-job-post .rt-kicker{margin-top:79px;font-size:16px}.rt-job-post h1{max-width:590px;margin-top:11px;font-size:65px;line-height:.94;letter-spacing:-.045em}.rt-job-post .rt-subtitle{margin-top:18px;font-size:23px}.rt-job-post .rt-intro{max-width:535px;margin-top:20px;font-size:18px;line-height:1.42}.rt-job-post .rt-post-signal{max-width:510px;margin-top:25px;padding-top:20px;border-top:1px solid #c6cdd2}.rt-job-post .rt-post-signal strong{font-size:16px;letter-spacing:.1em;text-transform:uppercase}.rt-job-post .rt-post-signal ul{display:grid;margin-top:9px;gap:7px}.rt-job-post .rt-post-signal li{font-size:18px;line-height:1.3}.rt-job-post .rt-cta{margin-top:23px;padding:16px 26px;font-size:17px}.rt-job-post .rt-post-band{position:absolute;z-index:3;right:0;bottom:0;left:0;display:flex;height:210px;padding:39px 55px;background:#123654;color:#fff;align-items:center}.rt-job-post .rt-facts{width:69%;gap:38px}.rt-job-post .rt-facts strong{font-size:37px;line-height:1}.rt-job-post .rt-facts span{margin-top:8px;color:#fff;font-size:15px}.rt-job-post .rt-band-contact{display:grid;margin-left:auto;gap:9px;text-align:right}.rt-job-post .rt-band-contact span{font-size:17px}.rt-job-post .rt-band-contact strong{font-size:18px}
CSS,
            [MarketingCreativeType::Job, MarketingCreativeFormat::Web] => <<<'CSS'
.rt-job-web{background:#102237}.rt-job-web .rt-photo-full{position:absolute;inset:0}.rt-job-web .rt-photo-image{object-position:68% center}.rt-job-web .rt-photo:after{background:linear-gradient(90deg,rgba(16,34,55,.02),rgba(16,34,55,.26))}.rt-job-web .rt-web-copy{position:absolute;z-index:2;inset:0 auto 0 0;width:60%;padding:43px 106px 38px 55px;background:#f4f1ea;clip-path:polygon(0 0,100% 0,87% 100%,0 100%)}.rt-job-web .rt-brand-lockup{width:218px}.rt-job-web .rt-kicker{margin-top:48px;font-size:14px}.rt-job-web h1{max-width:560px;margin-top:8px;font-size:55px;line-height:.93;letter-spacing:-.04em}.rt-job-web .rt-subtitle{margin-top:14px;font-size:21px}.rt-job-web .rt-web-signals{display:grid;max-width:530px;margin-top:18px;gap:6px}.rt-job-web .rt-web-signals li{font-size:17px;line-height:1.28}.rt-job-web .rt-web-actions{display:flex;margin-top:23px;align-items:center;gap:23px}.rt-job-web .rt-cta{padding:14px 23px;font-size:16px}.rt-job-web .rt-web-actions span{font-size:16px;font-weight:700}.rt-job-web .rt-web-proof{position:absolute;z-index:3;right:24px;bottom:24px;width:480px;padding:25px 24px;background:rgba(16,34,55,.93);color:#fff}.rt-job-web .rt-facts{gap:8px}.rt-job-web .rt-facts strong{font-size:30px;line-height:1}.rt-job-web .rt-facts span{margin-top:7px;color:#fff;font-size:13px;line-height:1.25;letter-spacing:.025em;white-space:nowrap}
CSS,
            [MarketingCreativeType::Info, MarketingCreativeFormat::Story] => <<<'CSS'
.rt-info-story .rt-info-hero{position:relative;height:720px;padding:50px 60px;background:#f4f1ea}.rt-info-story .rt-brand-lockup{width:255px}.rt-info-story .rt-info-copy{position:absolute;z-index:2;top:150px;left:60px;width:55%}.rt-info-story .rt-kicker{font-size:18px}.rt-info-story h1{max-width:570px;margin-top:13px;font-size:72px;line-height:.93;letter-spacing:-.045em}.rt-info-story .rt-subtitle{max-width:540px;margin-top:22px;font-size:26px}.rt-info-story .rt-intro{max-width:505px;margin-top:23px;font-size:18px;line-height:1.46}.rt-info-story .rt-photo{position:absolute;top:0;right:0;width:49%;height:720px;clip-path:polygon(20% 0,100% 0,100% 100%,0 100%)}.rt-info-story .rt-photo-image{object-position:66% center}.rt-info-story .rt-photo span{right:34px;bottom:30px;font-size:13px}.rt-info-story>.rt-facts{height:150px;padding:31px 60px;background:#d71932;color:#fff;gap:52px}.rt-info-story>.rt-facts strong{color:#fff;font-size:43px;line-height:1}.rt-info-story>.rt-facts span{margin-top:9px;color:#fff;font-size:13px}.rt-info-story .rt-info-services{display:grid;height:620px;padding:56px 60px;background:#102237;color:#fff;grid-template-columns:1fr 1fr;gap:58px}.rt-info-story .rt-info-services article+article{padding-left:56px;border-left:1px solid rgba(255,255,255,.22)}.rt-info-story .rt-info-services h2{margin-top:8px;padding-bottom:16px;border-bottom:2px solid rgba(255,255,255,.2);font-size:25px;text-transform:uppercase}.rt-info-story .rt-info-services li{margin-top:23px;color:#edf2f5;font-size:20px;line-height:1.38}.rt-info-story .rt-info-contact{position:relative;height:430px;padding:54px 60px;background:#f4f1ea}.rt-info-story .rt-info-contact>div{display:grid;max-width:600px;gap:9px}.rt-info-story .rt-info-contact .rt-footer-kicker{font-size:14px}.rt-info-story .rt-info-contact strong{margin:4px 0 9px;font-size:33px}.rt-info-story .rt-info-contact span{font-size:18px}.rt-info-story .rt-cta{position:absolute;top:75px;right:60px;min-width:230px;padding:20px 30px;font-size:19px}.rt-info-story footer{position:absolute;right:60px;bottom:35px;left:60px;display:flex;padding-top:18px;border-top:1px solid #bdc5cc;justify-content:space-between;font-size:14px}
CSS,
            [MarketingCreativeType::Info, MarketingCreativeFormat::Post] => <<<'CSS'
.rt-info-post{background:#102237}.rt-info-post .rt-photo-full{position:absolute;inset:0}.rt-info-post .rt-photo-image{object-position:69% center}.rt-info-post .rt-photo:after{background:linear-gradient(90deg,rgba(16,34,55,.03),rgba(16,34,55,.24) 70%,rgba(16,34,55,.42))}.rt-info-post .rt-info-post-lead{position:absolute;z-index:2;top:0;bottom:335px;left:0;width:67%;padding:48px 112px 42px 54px;background:#f4f1ea;clip-path:polygon(0 0,100% 0,86% 100%,0 100%)}.rt-info-post .rt-brand-lockup{width:240px}.rt-info-post .rt-kicker{margin-top:67px;font-size:16px}.rt-info-post h1{max-width:590px;margin-top:10px;font-size:61px;line-height:.94;letter-spacing:-.045em}.rt-info-post .rt-subtitle{max-width:535px;margin-top:17px;font-size:22px}.rt-info-post .rt-intro{max-width:525px;margin-top:19px;font-size:18px;line-height:1.42}.rt-info-post .rt-cta{margin-top:23px;padding:16px 25px;font-size:17px}.rt-info-post .rt-info-post-band{position:absolute;z-index:3;right:0;bottom:0;left:0;height:355px;padding:30px 55px;background:#123654;color:#fff}.rt-info-post .rt-facts{gap:44px}.rt-info-post .rt-facts strong{font-size:37px;line-height:1}.rt-info-post .rt-facts span{margin-top:7px;color:#fff;font-size:15px}.rt-info-post .rt-info-post-service{display:flex;margin-top:27px;padding-top:24px;border-top:1px solid rgba(255,255,255,.22);align-items:flex-start}.rt-info-post .rt-info-post-service strong{width:270px;font-size:23px;line-height:1.2}.rt-info-post .rt-info-post-service ul{display:flex;flex:1;gap:18px}.rt-info-post .rt-info-post-service li{flex:1;font-size:18px;line-height:1.3}.rt-info-post footer{position:absolute;right:55px;bottom:29px;left:55px;display:flex;padding-top:15px;border-top:1px solid rgba(255,255,255,.22);justify-content:space-between;font-size:16px}
CSS,
            [MarketingCreativeType::Info, MarketingCreativeFormat::Web] => <<<'CSS'
.rt-info-web{background:#102237}.rt-info-web .rt-photo-full{position:absolute;inset:0}.rt-info-web .rt-photo-image{object-position:68% center}.rt-info-web .rt-photo:after{background:linear-gradient(90deg,rgba(16,34,55,.02),rgba(16,34,55,.25))}.rt-info-web .rt-info-web-lead{position:absolute;z-index:2;inset:0 auto 0 0;width:59%;padding:43px 102px 37px 55px;background:#f4f1ea;clip-path:polygon(0 0,100% 0,86% 100%,0 100%)}.rt-info-web .rt-brand-lockup{width:218px}.rt-info-web .rt-kicker{margin-top:39px;font-size:14px}.rt-info-web h1{max-width:550px;margin-top:8px;font-size:53px;line-height:.94;letter-spacing:-.04em}.rt-info-web .rt-subtitle{max-width:535px;margin-top:13px;font-size:20px}.rt-info-web .rt-intro{max-width:535px;margin-top:15px;font-size:17px;line-height:1.4}.rt-info-web .rt-info-web-actions{display:flex;margin-top:22px;align-items:center;gap:21px}.rt-info-web .rt-cta{padding:14px 23px;font-size:16px}.rt-info-web .rt-info-web-actions span{font-size:15px;font-weight:700}.rt-info-web .rt-info-web-proof{position:absolute;z-index:3;right:23px;bottom:23px;width:480px;padding:24px 24px;background:rgba(16,34,55,.94);color:#fff}.rt-info-web .rt-facts{gap:8px}.rt-info-web .rt-facts strong{font-size:30px;line-height:1}.rt-info-web .rt-facts span{margin-top:7px;color:#fff;font-size:13px;line-height:1.25;letter-spacing:.025em;white-space:nowrap}.rt-info-web .rt-info-web-proof>p{display:flex;margin-top:18px;padding-top:15px;border-top:1px solid rgba(255,255,255,.22);justify-content:space-between;font-size:15px}.rt-info-web .rt-info-web-proof>p strong{font-size:15px}
CSS,
        };

        return $base.$specific;
    }
}
