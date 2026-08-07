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
                        'schema' => 1,
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
        return match ($format) {
            MarketingCreativeFormat::Story => <<<'HTML'
<main class="rt-marketing-canvas rt-job rt-job-story">
  <section class="rt-hero">
    <div class="rt-brand"><span class="rt-brand-mark"><img src="/rt-brand/rt-logo.svg" alt=""></span><span>RAILTIME</span></div>
    <div class="rt-copy">
      <p class="rt-kicker" data-rt-binding="kicker"></p>
      <h1 data-rt-binding="title"></h1>
      <p class="rt-subtitle" data-rt-binding="subtitle"></p>
      <p class="rt-intro" data-rt-binding="intro"></p>
    </div>
    <div class="rt-photo"><img class="rt-photo-image" src="/rt-brand/img/hero-railtime.jpg" alt=""><span>RAIL · PEOPLE · SAFETY</span></div>
  </section>
  <section class="rt-facts" data-rt-binding-facts="facts"></section>
  <section class="rt-details rt-details-job">
    <article><h2>Deine Aufgaben</h2><ul data-rt-binding-list="tasks"></ul></article>
    <article><h2>Dein Profil</h2><ul data-rt-binding-list="profile"></ul></article>
    <article class="rt-benefits"><h2>Darauf kannst du zählen</h2><ul data-rt-binding-list="benefits"></ul></article>
  </section>
  <footer class="rt-footer">
    <div><p class="rt-footer-title">Deine Zukunft. Unsere gemeinsame Fahrt.</p><p><span data-rt-binding="contact_phone"></span> · <span data-rt-binding="contact_email"></span></p></div>
    <a class="rt-cta" data-rt-binding="cta_label" data-rt-binding-href="cta_url"></a>
    <p class="rt-company"><span data-rt-binding="company_name"></span> · <span data-rt-binding="company_address"></span></p>
  </footer>
</main>
HTML,
            MarketingCreativeFormat::Post => <<<'HTML'
<main class="rt-marketing-canvas rt-job rt-job-post">
  <div class="rt-photo rt-photo-full"><img class="rt-photo-image" src="/rt-brand/img/hero-railtime.jpg" alt=""></div>
  <header><div class="rt-brand"><span class="rt-brand-mark"><img src="/rt-brand/rt-logo.svg" alt=""></span><span>RAILTIME</span></div><p class="rt-kicker" data-rt-binding="kicker"></p></header>
  <section class="rt-post-panel">
    <p class="rt-subtitle" data-rt-binding="subtitle"></p>
    <h1 data-rt-binding="title"></h1>
    <div class="rt-facts" data-rt-binding-facts="facts"></div>
    <p class="rt-intro" data-rt-binding="intro"></p>
    <div class="rt-post-bottom"><a class="rt-cta" data-rt-binding="cta_label" data-rt-binding-href="cta_url"></a><span data-rt-binding="website"></span></div>
  </section>
</main>
HTML,
            MarketingCreativeFormat::Web => <<<'HTML'
<main class="rt-marketing-canvas rt-job rt-job-web">
  <section class="rt-web-copy">
    <div class="rt-brand"><span class="rt-brand-mark"><img src="/rt-brand/rt-logo.svg" alt=""></span><span>RAILTIME</span></div>
    <p class="rt-kicker" data-rt-binding="kicker"></p>
    <h1 data-rt-binding="title"></h1>
    <p class="rt-subtitle" data-rt-binding="subtitle"></p>
    <div class="rt-web-actions"><a class="rt-cta" data-rt-binding="cta_label" data-rt-binding-href="cta_url"></a><span data-rt-binding="contact_email"></span></div>
  </section>
  <section class="rt-web-visual">
    <div class="rt-photo"><img class="rt-photo-image" src="/rt-brand/img/hero-railtime.jpg" alt=""></div>
    <div class="rt-facts" data-rt-binding-facts="facts"></div>
  </section>
</main>
HTML,
        };
    }

    private function infoHtml(MarketingCreativeFormat $format): string
    {
        return match ($format) {
            MarketingCreativeFormat::Story => <<<'HTML'
<main class="rt-marketing-canvas rt-info rt-info-story">
  <header><div class="rt-brand"><span class="rt-brand-mark"><img src="/rt-brand/rt-logo.svg" alt=""></span><span>RAILTIME</span></div><p class="rt-kicker" data-rt-binding="kicker"></p></header>
  <section class="rt-info-head"><h1 data-rt-binding="title"></h1><p class="rt-subtitle" data-rt-binding="subtitle"></p><p class="rt-intro" data-rt-binding="intro"></p></section>
  <div class="rt-photo rt-info-photo"><img class="rt-photo-image" src="/rt-brand/img/hero-railtime.jpg" alt=""><span>QUALITÄT IM BAHNBETRIEB</span></div>
  <section class="rt-facts" data-rt-binding-facts="facts"></section>
  <section class="rt-service-list"><h2>Unser Service</h2><ul data-rt-binding-list="tasks"></ul></section>
  <section class="rt-info-contact"><div><strong>24/7 erreichbar</strong><span data-rt-binding="contact_phone"></span><span data-rt-binding="contact_email"></span></div><a class="rt-cta" data-rt-binding="cta_label" data-rt-binding-href="cta_url"></a></section>
  <footer><span data-rt-binding="company_name"></span><span data-rt-binding="company_address"></span><span data-rt-binding="website"></span></footer>
</main>
HTML,
            MarketingCreativeFormat::Post => <<<'HTML'
<main class="rt-marketing-canvas rt-info rt-info-post">
  <header><div class="rt-brand"><span class="rt-brand-mark"><img src="/rt-brand/rt-logo.svg" alt=""></span><span>RAILTIME</span></div><p class="rt-kicker" data-rt-binding="kicker"></p></header>
  <section class="rt-info-post-title"><h1 data-rt-binding="title"></h1><p data-rt-binding="subtitle"></p></section>
  <section class="rt-facts" data-rt-binding-facts="facts"></section>
  <section class="rt-info-post-service"><h2>Wenn es zählt, sind wir da.</h2><ul data-rt-binding-list="benefits"></ul></section>
  <footer><a class="rt-cta" data-rt-binding="cta_label" data-rt-binding-href="cta_url"></a><div><span data-rt-binding="contact_phone"></span><span data-rt-binding="website"></span></div></footer>
</main>
HTML,
            MarketingCreativeFormat::Web => <<<'HTML'
<main class="rt-marketing-canvas rt-info rt-info-web">
  <section class="rt-info-web-lead">
    <div class="rt-brand"><span class="rt-brand-mark"><img src="/rt-brand/rt-logo.svg" alt=""></span><span>RAILTIME</span></div>
    <p class="rt-kicker" data-rt-binding="kicker"></p><h1 data-rt-binding="title"></h1><p class="rt-intro" data-rt-binding="intro"></p>
    <a class="rt-cta" data-rt-binding="cta_label" data-rt-binding-href="cta_url"></a>
  </section>
  <section class="rt-info-web-proof"><div class="rt-facts" data-rt-binding-facts="facts"></div><p><span data-rt-binding="contact_phone"></span><br><span data-rt-binding="contact_email"></span></p></section>
</main>
HTML,
        };
    }

    private function css(MarketingCreativeFormat $format, MarketingCreativeType $type): string
    {
        $dimensions = $format->dimensions();
        $base = <<<CSS
*{box-sizing:border-box}.rt-marketing-canvas{position:relative;overflow:hidden;width:{$dimensions['width']}px;height:{$dimensions['height']}px;margin:0;background:#f7f6f2;color:#102237;font-family:Arial,"Helvetica Neue",sans-serif}.rt-marketing-canvas h1,.rt-marketing-canvas h2,.rt-marketing-canvas p{margin:0}.rt-brand{display:flex;align-items:center;gap:16px;font-size:24px;font-weight:800;letter-spacing:.14em}.rt-brand-mark{display:block;width:58px;height:58px}.rt-brand-mark img{display:block;width:100%;height:100%;object-fit:contain}.rt-kicker{color:#d7172f;font-weight:800;letter-spacing:.14em;text-transform:uppercase}.rt-subtitle{font-weight:700}.rt-cta{display:inline-flex;align-items:center;justify-content:center;border-radius:999px;background:#d7172f;color:#fff;font-weight:800;text-decoration:none}.rt-photo{position:relative;overflow:hidden;background:linear-gradient(145deg,#263a4c,#101b27 55%,#d7172f)}.rt-photo-image{position:absolute;inset:0;width:100%;height:100%;object-fit:cover}.rt-photo:after{position:absolute;inset:0;background:linear-gradient(90deg,rgba(9,20,31,.04),rgba(9,20,31,.3));content:""}.rt-photo>span{z-index:1}.rt-facts{display:flex}.rt-facts>div{display:flex;flex-direction:column}.rt-facts strong{color:#d7172f}.rt-facts span{text-transform:uppercase;letter-spacing:.08em}.rt-marketing-canvas ul{margin:0;padding:0;list-style:none}.rt-marketing-canvas li{position:relative;padding-left:26px}.rt-marketing-canvas li:before{position:absolute;left:0;color:#d7172f;content:"✓"}.rt-footer,.rt-marketing-canvas footer{position:relative}.rt-company{position:absolute}
CSS;

        $specific = match ([$type, $format]) {
            [MarketingCreativeType::Job, MarketingCreativeFormat::Story] => '.rt-job-story .rt-hero{height:760px;padding:72px}.rt-job-story .rt-copy{position:relative;z-index:2;width:58%;padding-top:150px}.rt-job-story h1{max-width:620px;font-size:82px;line-height:.96}.rt-job-story .rt-kicker{font-size:22px}.rt-job-story .rt-subtitle{margin-top:25px;font-size:30px}.rt-job-story .rt-intro{margin-top:28px;font-size:22px;line-height:1.45}.rt-job-story .rt-photo{position:absolute;top:0;right:0;width:43%;height:760px;clip-path:polygon(22% 0,100% 0,100% 100%,0 100%)}.rt-job-story .rt-photo span{position:absolute;right:40px;bottom:34px;color:#fff;font-size:15px;letter-spacing:.16em}.rt-job-story>.rt-facts{height:190px;padding:42px 72px;background:#102f4f;color:#fff;gap:64px}.rt-job-story>.rt-facts>div{flex:1}.rt-job-story .rt-facts strong{font-size:50px}.rt-job-story .rt-facts span{font-size:14px}.rt-job-story .rt-details{display:grid;height:690px;padding:64px 72px;grid-template-columns:1fr 1fr;gap:54px}.rt-job-story .rt-details article:last-child{grid-column:1/-1}.rt-job-story h2{padding-bottom:18px;border-bottom:2px solid #d7dde3;font-size:24px;text-transform:uppercase}.rt-job-story li{margin-top:18px;font-size:20px;line-height:1.4}.rt-job-story .rt-benefits ul{display:grid;grid-template-columns:1fr 1fr;gap:0 36px}.rt-job-story .rt-footer{height:280px;padding:45px 72px;background:#fff}.rt-job-story .rt-footer-title{max-width:560px;font-size:30px;font-weight:800}.rt-job-story .rt-footer>div>p+ p{margin-top:18px;font-size:18px}.rt-job-story .rt-cta{position:absolute;top:45px;right:72px;min-width:230px;padding:22px 32px;font-size:20px}.rt-job-story .rt-company{right:72px;bottom:38px;left:72px;padding-top:18px;border-top:1px solid #ccd3da;font-size:15px}',
            [MarketingCreativeType::Job, MarketingCreativeFormat::Post] => '.rt-job-post{background:#102237;color:#fff}.rt-job-post .rt-photo-full{position:absolute;inset:0 0 390px}.rt-job-post:after{position:absolute;inset:0;background:linear-gradient(180deg,rgba(10,22,34,.1),rgba(10,22,34,.2) 45%,#102237 67%);content:""}.rt-job-post header{position:relative;z-index:2;display:flex;padding:58px 62px;align-items:center;justify-content:space-between}.rt-job-post .rt-post-panel{position:absolute;z-index:2;right:62px;bottom:52px;left:62px}.rt-job-post h1{max-width:850px;font-size:78px;line-height:.95}.rt-job-post .rt-subtitle{margin-bottom:18px;color:#f3c6cc;font-size:24px;text-transform:uppercase}.rt-job-post .rt-facts{margin-top:34px;gap:52px}.rt-job-post .rt-facts strong{font-size:38px}.rt-job-post .rt-facts span{font-size:12px}.rt-job-post .rt-intro{max-width:820px;margin-top:30px;color:#dbe2e8;font-size:18px;line-height:1.45}.rt-job-post .rt-post-bottom{display:flex;margin-top:34px;align-items:center;justify-content:space-between}.rt-job-post .rt-cta{padding:18px 30px;font-size:18px}.rt-job-post .rt-post-bottom>span{font-size:17px;font-weight:700}',
            [MarketingCreativeType::Job, MarketingCreativeFormat::Web] => '.rt-job-web{display:grid;background:#102237;color:#fff;grid-template-columns:54% 46%}.rt-job-web .rt-web-copy{padding:54px 64px}.rt-job-web .rt-kicker{margin-top:76px;font-size:16px}.rt-job-web h1{max-width:620px;margin-top:12px;font-size:66px;line-height:.98}.rt-job-web .rt-subtitle{margin-top:18px;font-size:25px}.rt-job-web .rt-web-actions{display:flex;margin-top:46px;align-items:center;gap:28px}.rt-job-web .rt-cta{padding:17px 27px}.rt-job-web .rt-web-visual{position:relative}.rt-job-web .rt-photo{height:100%}.rt-job-web .rt-facts{position:absolute;right:24px;bottom:24px;left:24px;padding:20px 24px;background:rgba(9,22,34,.88);gap:24px}.rt-job-web .rt-facts>div{flex:1}.rt-job-web .rt-facts strong{font-size:29px}.rt-job-web .rt-facts span{color:#fff;font-size:9px}',
            [MarketingCreativeType::Info, MarketingCreativeFormat::Story] => '.rt-info-story{padding:66px 70px;background:#f4f2ec}.rt-info-story header{display:flex;align-items:center;justify-content:space-between}.rt-info-story .rt-info-head{padding-top:120px}.rt-info-story h1{max-width:820px;font-size:84px;line-height:.98}.rt-info-story .rt-subtitle{margin-top:22px;font-size:31px}.rt-info-story .rt-intro{max-width:780px;margin-top:28px;font-size:22px;line-height:1.5}.rt-info-story .rt-info-photo{height:390px;margin:70px -70px 0}.rt-info-story .rt-info-photo span{position:absolute;right:55px;bottom:38px;color:#fff;font-size:17px;letter-spacing:.15em}.rt-info-story>.rt-facts{margin:0 -70px;padding:42px 70px;background:#d7172f;color:#fff;gap:70px}.rt-info-story>.rt-facts>div{flex:1}.rt-info-story>.rt-facts strong{color:#fff;font-size:48px}.rt-info-story>.rt-facts span{font-size:13px}.rt-info-story .rt-service-list{padding-top:66px}.rt-info-story h2{font-size:25px;text-transform:uppercase}.rt-info-story li{margin-top:22px;font-size:22px}.rt-info-story .rt-info-contact{display:flex;margin-top:62px;padding:38px;background:#102f4f;color:#fff;align-items:center;justify-content:space-between}.rt-info-story .rt-info-contact div{display:grid;gap:9px;font-size:19px}.rt-info-story .rt-info-contact strong{font-size:27px}.rt-info-story .rt-cta{padding:20px 30px}.rt-info-story footer{position:absolute;right:70px;bottom:44px;left:70px;display:flex;padding-top:18px;border-top:1px solid #bdc5cc;justify-content:space-between;font-size:14px}',
            [MarketingCreativeType::Info, MarketingCreativeFormat::Post] => '.rt-info-post{padding:56px 62px;background:linear-gradient(145deg,#f5f3ee 0 62%,#102f4f 62%);color:#102237}.rt-info-post header{display:flex;align-items:center;justify-content:space-between}.rt-info-post .rt-info-post-title{margin-top:108px}.rt-info-post h1{max-width:850px;font-size:82px;line-height:.95}.rt-info-post .rt-info-post-title p{margin-top:20px;font-size:27px;font-weight:700}.rt-info-post>.rt-facts{margin-top:64px;gap:70px}.rt-info-post>.rt-facts strong{font-size:48px}.rt-info-post>.rt-facts span{font-size:12px}.rt-info-post .rt-info-post-service{position:absolute;right:0;bottom:132px;left:0;padding:28px 62px 32px;background:#102f4f;color:#fff}.rt-info-post .rt-info-post-service h2{font-size:31px}.rt-info-post .rt-info-post-service ul{display:flex;margin-top:28px;gap:30px}.rt-info-post .rt-info-post-service li{flex:1;font-size:17px}.rt-info-post footer{position:absolute;right:62px;bottom:48px;left:62px;display:flex;align-items:end;justify-content:space-between;color:#fff}.rt-info-post .rt-cta{padding:17px 28px}.rt-info-post footer div{display:grid;gap:7px;text-align:right}',
            [MarketingCreativeType::Info, MarketingCreativeFormat::Web] => '.rt-info-web{display:grid;background:#f4f2ed;grid-template-columns:67% 33%}.rt-info-web .rt-info-web-lead{padding:48px 62px}.rt-info-web .rt-kicker{margin-top:43px}.rt-info-web h1{margin-top:10px;font-size:64px;line-height:.98}.rt-info-web .rt-intro{max-width:680px;margin-top:19px;font-size:18px;line-height:1.45}.rt-info-web .rt-cta{margin-top:30px;padding:15px 26px}.rt-info-web .rt-info-web-proof{display:flex;padding:54px 44px;background:#102f4f;color:#fff;flex-direction:column;justify-content:center}.rt-info-web .rt-facts{display:grid;gap:26px}.rt-info-web .rt-facts>div{padding-bottom:19px;border-bottom:1px solid rgba(255,255,255,.22)}.rt-info-web .rt-facts strong{font-size:35px}.rt-info-web .rt-facts span{font-size:10px}.rt-info-web .rt-info-web-proof>p{margin-top:36px;font-size:17px;line-height:1.6}',
        };

        return $base.$specific;
    }
}
