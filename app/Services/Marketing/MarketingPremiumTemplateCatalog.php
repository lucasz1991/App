<?php

namespace App\Services\Marketing;

use App\Enums\MarketingCreativeFormat;
use App\Support\CompanyData;
use InvalidArgumentException;

final class MarketingPremiumTemplateCatalog
{
    /** @return list<string> */
    public function templateKeys(): array
    {
        return [
            MarketingTemplateFactory::PREMIUM_COMPANY_PROFILE,
            MarketingTemplateFactory::PREMIUM_JOB_WAGENMEISTER,
            MarketingTemplateFactory::PREMIUM_GERMANY_NETWORK,
        ];
    }

    public function hasTemplateKey(string $templateKey): bool
    {
        return in_array($templateKey, $this->templateKeys(), true);
    }

    /** @return array{title:string,shared_content:array<string,mixed>,variants:array<string,array{builder_data:array<string,mixed>,html:string,css:string}>} */
    public function definitionByKey(string $templateKey): array
    {
        if (! $this->hasTemplateKey($templateKey)) {
            throw new InvalidArgumentException('Unbekannte Premium-Marketingvorlage: '.$templateKey);
        }

        $company = CompanyData::all();
        [$title, $content] = match ($templateKey) {
            MarketingTemplateFactory::PREMIUM_COMPANY_PROFILE => [
                'RailTime vorstellen – Menschen, Technik, Verantwortung',
                $this->companyProfileContent($company),
            ],
            MarketingTemplateFactory::PREMIUM_JOB_WAGENMEISTER => [
                'Wagenmeister (m/w/d) – Gemeinsam Sicherheit bewegen',
                $this->jobContent($company),
            ],
            MarketingTemplateFactory::PREMIUM_GERMANY_NETWORK => [
                'Deutschlandweit im Einsatz – RailTime Netzwerk',
                $this->networkContent($company),
            ],
        };

        $variants = [];
        foreach (MarketingCreativeFormat::cases() as $format) {
            $html = $this->html($templateKey, $format);
            $variants[$format->value] = [
                'builder_data' => [
                    'pages' => [[
                        'name' => ucfirst($format->value),
                        'component' => $html,
                    ]],
                    'styles' => [],
                    'railtime' => [
                        'template' => $templateKey,
                        'format' => $format->value,
                        'schema' => 4,
                    ],
                ],
                'html' => $html,
                'css' => $this->css($templateKey, $format),
            ];
        }

        return [
            'title' => $title,
            'shared_content' => $content,
            'variants' => $variants,
        ];
    }

    /** @param array<string, string> $company */
    private function companyProfileContent(array $company): array
    {
        return $this->companyContent($company) + [
            'template_key' => MarketingTemplateFactory::PREMIUM_COMPANY_PROFILE,
            'seed_version' => MarketingTemplateFactory::PREMIUM_SEED_VERSION,
            'kicker' => 'RT Rail Time GmbH',
            'title' => 'Menschen, die Verantwortung auf der Schiene übernehmen.',
            'subtitle' => 'Kompetent. Flexibel. Deutschlandweit.',
            'intro' => 'Mehr als 60 qualifizierte Wagenmeister sichern Einsätze bundesweit. Persönlich koordiniert, technisch präzise und rund um die Uhr erreichbar.',
            'facts' => [
                ['value' => '60+', 'label' => 'qualifizierte Wagenmeister'],
                ['value' => '24/7', 'label' => 'Notfalldienst'],
                ['value' => 'DE', 'label' => 'bundesweit im Einsatz'],
            ],
            'tasks' => [
                'Wagenmeister-Dienstleistungen',
                'Technische Wagenuntersuchungen',
                'Instandhaltung und Sonderuntersuchungen',
            ],
            'profile' => [
                'Sicher im Betrieb',
                'Flexibel im Einsatz',
                'Persönlich koordiniert',
            ],
            'benefits' => [
                'Menschen mit Bahnerfahrung',
                'Klare Dokumentation',
                'Ein Ansprechpartner',
            ],
            'cta_label' => 'RailTime kennenlernen',
            'cta_url' => 'https://www.rail-time.de/de/ueber-uns',
            'editorial_note' => 'Leistungsumfang, Teamgröße und Verfügbarkeit sind vor Veröffentlichung mit den aktuellen Unternehmensangaben abzugleichen.',
        ];
    }

    /** @param array<string, string> $company */
    private function jobContent(array $company): array
    {
        return $this->companyContent($company) + [
            'template_key' => MarketingTemplateFactory::PREMIUM_JOB_WAGENMEISTER,
            'seed_version' => MarketingTemplateFactory::PREMIUM_SEED_VERSION,
            'kicker' => 'Karriere / Wagenmeister',
            'title' => 'Wagenmeister (m/w/d)',
            'subtitle' => 'Gemeinsam bringen wir Sicherheit auf die Schiene.',
            'intro' => 'Du prüfst Güterwagen, dokumentierst präzise und triffst Entscheidungen für einen sicheren Bahnbetrieb. Bei RailTime zählen Technik, Verantwortung und Team.',
            'facts' => [
                ['value' => '60+', 'label' => 'Wagenmeister bundesweit'],
                ['value' => 'DE', 'label' => 'bundesweit im Einsatz'],
                ['value' => '24/7', 'label' => 'Notfalldienst'],
            ],
            'tasks' => [
                'Güterwagen und Züge technisch untersuchen',
                'Bremsproben und Dokumentation durchführen',
                'Befunde sicher bewerten und kommunizieren',
            ],
            'profile' => [
                'Technisches Verständnis',
                'Verantwortungsbewusstsein',
                'Teamgeist und klare Kommunikation',
            ],
            'benefits' => [
                'Technik',
                'Verantwortung',
                'Team',
            ],
            'cta_label' => 'Jetzt bewerben',
            'cta_url' => 'https://www.rail-time.de/de/karriere',
            'editorial_note' => 'Qualifikation, Einsatzmodell und Leistungen sind vor Veröffentlichung mit der aktuellen Stellenausschreibung abzugleichen.',
        ];
    }

    /** @param array<string, string> $company */
    private function networkContent(array $company): array
    {
        return $this->companyContent($company) + [
            'template_key' => MarketingTemplateFactory::PREMIUM_GERMANY_NETWORK,
            'seed_version' => MarketingTemplateFactory::PREMIUM_SEED_VERSION,
            'kicker' => 'RailTime Netzwerk',
            'title' => 'Deutschlandweit im Einsatz.',
            'subtitle' => 'Wagenmeister-Kompetenz dort, wo sie gebraucht wird.',
            'intro' => 'Mehr als 60 erfahrene Wagenmeister sind bundesweit im Einsatz. Planbar, technisch präzise und rund um die Uhr erreichbar.',
            'facts' => [
                ['value' => '60+', 'label' => 'erfahrene Wagenmeister'],
                ['value' => '24/7', 'label' => 'Notfalldienst'],
                ['value' => 'DE', 'label' => 'bundesweite Einsätze'],
            ],
            'tasks' => [
                'Wagenmeister-Dienstleistungen',
                'Technische Wagenuntersuchungen',
                'Kurzfristige Unterstützung und Dauereinsätze',
            ],
            'profile' => [
                'Hauptsitz',
                'RailTime-Standorte',
                'Einsatzorte',
            ],
            'benefits' => [
                'Ein Ansprechpartner',
                'Bundesweit koordiniert',
                'Rund um die Uhr erreichbar',
            ],
            'cta_label' => 'Einsatz anfragen',
            'cta_url' => 'https://www.rail-time.de/de/kontakt',
            'editorial_note' => 'Konkrete Verfügbarkeit, Reaktionszeit und Leistungsumfang werden für jede Einsatzanfrage individuell bestätigt.',
        ];
    }

    /** @param array<string, string> $company */
    private function companyContent(array $company): array
    {
        return [
            'contact_phone' => $company['phone'],
            'contact_email' => $company['email'],
            'website' => preg_replace('#^https?://#i', '', rtrim($company['website'], '/')),
            'company_name' => $company['name'],
            'company_address' => CompanyData::addressLine($company),
        ];
    }

    private function html(string $templateKey, MarketingCreativeFormat $format): string
    {
        return match ($templateKey) {
            MarketingTemplateFactory::PREMIUM_COMPANY_PROFILE => $this->companyHtml($format),
            MarketingTemplateFactory::PREMIUM_JOB_WAGENMEISTER => $this->jobHtml($format),
            MarketingTemplateFactory::PREMIUM_GERMANY_NETWORK => $this->networkHtml($format),
        };
    }

    private function companyHtml(MarketingCreativeFormat $format): string
    {
        $logo = $this->brandLockup($format !== MarketingCreativeFormat::Story);

        return match ($format) {
            MarketingCreativeFormat::Story => <<<HTML
<main class="rt-marketing-canvas rt-premium rt-company rt-company-story">
  <header class="rt-mast">{$logo}<span class="rt-code">UNTERNEHMEN / 001</span></header>
  <section class="rt-copy"><p class="rt-kicker" data-rt-binding="kicker"></p><h1 data-rt-binding="title"></h1><p class="rt-subtitle" data-rt-binding="subtitle"></p></section>
  <figure class="rt-photo"><img src="/rt-brand/img/wagenmeister-team.webp" alt="RailTime-Wagenmeister stimmen einen Einsatz im Team ab"><figcaption>Menschen. Technik. Verantwortung.</figcaption></figure>
  <section class="rt-intro-panel"><p class="rt-intro" data-rt-binding="intro"></p></section>
  <div class="rt-facts" data-rt-binding-facts="facts"></div>
  <footer><a class="rt-cta" data-rt-binding="cta_label" data-rt-binding-href="cta_url"></a><div><strong>rail-time.de/de/ueber-uns</strong><span data-rt-binding="contact_email"></span></div></footer>
</main>
HTML,
            MarketingCreativeFormat::Post => <<<HTML
<main class="rt-marketing-canvas rt-premium rt-company rt-company-post">
  <header class="rt-mast">{$logo}<span class="rt-code">RT / UNTERNEHMEN</span></header>
  <figure class="rt-photo"><img src="/rt-brand/img/wagenmeister-team.webp" alt="RailTime-Wagenmeister stimmen einen Einsatz im Team ab"><figcaption>60+ Menschen für sicheren Bahnbetrieb.</figcaption></figure>
  <section class="rt-copy"><p class="rt-kicker" data-rt-binding="kicker"></p><h1 data-rt-binding="title"></h1><p class="rt-subtitle" data-rt-binding="subtitle"></p><p class="rt-intro" data-rt-binding="intro"></p><a class="rt-cta" data-rt-binding="cta_label" data-rt-binding-href="cta_url"></a></section>
  <footer><div class="rt-facts" data-rt-binding-facts="facts"></div><strong data-rt-binding="website"></strong></footer>
</main>
HTML,
            MarketingCreativeFormat::Web => <<<HTML
<main class="rt-marketing-canvas rt-premium rt-company rt-company-web">
  <section class="rt-copy">{$logo}<p class="rt-kicker" data-rt-binding="kicker"></p><h1 data-rt-binding="title"></h1><p class="rt-subtitle" data-rt-binding="subtitle"></p><p class="rt-intro" data-rt-binding="intro"></p><div class="rt-actions"><a class="rt-cta" data-rt-binding="cta_label" data-rt-binding-href="cta_url"></a><strong data-rt-binding="website"></strong></div></section>
  <figure class="rt-photo"><img src="/rt-brand/img/wagenmeister-team.webp" alt="RailTime-Wagenmeister stimmen einen Einsatz im Team ab"><figcaption>COMPANY / PEOPLE / RAIL</figcaption></figure>
  <footer><div class="rt-facts" data-rt-binding-facts="facts"></div></footer>
</main>
HTML,
        };
    }

    private function jobHtml(MarketingCreativeFormat $format): string
    {
        $logo = $this->brandLockup(true);

        return match ($format) {
            MarketingCreativeFormat::Story => <<<HTML
<main class="rt-marketing-canvas rt-premium rt-job-premium rt-job-premium-story">
  <figure class="rt-photo"><img src="/rt-brand/img/wagenmeister-team-gleis.jpeg" alt="RailTime-Wagenmeister im Einsatz zwischen Güterwagen"></figure>
  <header class="rt-mast">{$logo}<span class="rt-code">KARRIERE / 001</span></header>
  <section class="rt-copy"><p class="rt-kicker" data-rt-binding="kicker"></p><h1 data-rt-binding="title"></h1><p class="rt-subtitle" data-rt-binding="subtitle"></p></section>
  <section class="rt-job-panel"><p class="rt-intro" data-rt-binding="intro"></p><ul class="rt-keywords" data-rt-binding-list="benefits"></ul><div class="rt-facts" data-rt-binding-facts="facts"></div></section>
  <footer><a class="rt-cta" data-rt-binding="cta_label" data-rt-binding-href="cta_url"></a><div><strong>Deine nächste Station: RailTime.</strong><span>rail-time.de/de/karriere</span></div></footer>
</main>
HTML,
            MarketingCreativeFormat::Post => <<<HTML
<main class="rt-marketing-canvas rt-premium rt-job-premium rt-job-premium-post">
  <figure class="rt-photo"><img src="/rt-brand/img/wagenmeister-team-gleis.jpeg" alt="RailTime-Wagenmeister im Einsatz zwischen Güterwagen"></figure>
  <header class="rt-mast">{$logo}<span class="rt-code">JOB / 001</span></header>
  <section class="rt-copy"><p class="rt-kicker" data-rt-binding="kicker"></p><h1 data-rt-binding="title"></h1><p class="rt-subtitle" data-rt-binding="subtitle"></p><ul class="rt-keywords" data-rt-binding-list="benefits"></ul><a class="rt-cta" data-rt-binding="cta_label" data-rt-binding-href="cta_url"></a></section>
  <footer><div class="rt-facts" data-rt-binding-facts="facts"></div><strong data-rt-binding="website"></strong></footer>
</main>
HTML,
            MarketingCreativeFormat::Web => <<<HTML
<main class="rt-marketing-canvas rt-premium rt-job-premium rt-job-premium-web">
  <section class="rt-copy">{$logo}<p class="rt-kicker" data-rt-binding="kicker"></p><h1 data-rt-binding="title"></h1><p class="rt-subtitle" data-rt-binding="subtitle"></p><p class="rt-intro" data-rt-binding="intro"></p><div class="rt-actions"><a class="rt-cta" data-rt-binding="cta_label" data-rt-binding-href="cta_url"></a><strong data-rt-binding="website"></strong></div></section>
  <figure class="rt-photo"><img src="/rt-brand/img/wagenmeister-team-gleis.jpeg" alt="RailTime-Wagenmeister im Einsatz zwischen Güterwagen"><figcaption>TECHNIK / VERANTWORTUNG / TEAM</figcaption></figure>
  <footer><div class="rt-facts" data-rt-binding-facts="facts"></div></footer>
</main>
HTML,
        };
    }

    private function networkHtml(MarketingCreativeFormat $format): string
    {
        $logo = $this->brandLockup($format !== MarketingCreativeFormat::Story);

        return match ($format) {
            MarketingCreativeFormat::Story => <<<HTML
<main class="rt-marketing-canvas rt-premium rt-network-premium rt-network-premium-story">
  <header class="rt-mast">{$logo}<span class="rt-code">NETZWERK / DE</span></header>
  <section class="rt-copy"><p class="rt-kicker" data-rt-binding="kicker"></p><h1 data-rt-binding="title"></h1><p class="rt-subtitle" data-rt-binding="subtitle"></p></section>
  <figure class="rt-map"><img src="/rt-brand/img/deutschland-netzwerk.png" alt="Deutschlandkarte mit RailTime-Einsatzstandorten"><figcaption>Hauptsitz / Standorte / Einsatzorte</figcaption></figure>
  <section class="rt-network-panel"><p class="rt-intro" data-rt-binding="intro"></p></section>
  <div class="rt-facts" data-rt-binding-facts="facts"></div>
  <footer><a class="rt-cta" data-rt-binding="cta_label" data-rt-binding-href="cta_url"></a><div><strong data-rt-binding="contact_phone"></strong><span>rail-time.de/de/kontakt</span></div></footer>
</main>
HTML,
            MarketingCreativeFormat::Post => <<<HTML
<main class="rt-marketing-canvas rt-premium rt-network-premium rt-network-premium-post">
  <header class="rt-mast">{$logo}<span class="rt-code">NETWORK / DE</span></header>
  <section class="rt-copy"><p class="rt-kicker" data-rt-binding="kicker"></p><h1 data-rt-binding="title"></h1><p class="rt-subtitle" data-rt-binding="subtitle"></p><p class="rt-intro" data-rt-binding="intro"></p><a class="rt-cta" data-rt-binding="cta_label" data-rt-binding-href="cta_url"></a></section>
  <figure class="rt-map"><img src="/rt-brand/img/deutschland-netzwerk.png" alt="Deutschlandkarte mit RailTime-Einsatzstandorten"></figure>
  <footer><div class="rt-facts" data-rt-binding-facts="facts"></div><strong data-rt-binding="website"></strong></footer>
</main>
HTML,
            MarketingCreativeFormat::Web => <<<HTML
<main class="rt-marketing-canvas rt-premium rt-network-premium rt-network-premium-web">
  <section class="rt-copy">{$logo}<p class="rt-kicker" data-rt-binding="kicker"></p><h1 data-rt-binding="title"></h1><p class="rt-subtitle" data-rt-binding="subtitle"></p><p class="rt-intro" data-rt-binding="intro"></p><div class="rt-facts" data-rt-binding-facts="facts"></div><div class="rt-actions"><a class="rt-cta" data-rt-binding="cta_label" data-rt-binding-href="cta_url"></a><strong data-rt-binding="contact_phone"></strong></div></section>
  <figure class="rt-map"><img src="/rt-brand/img/deutschland-netzwerk.png" alt="Deutschlandkarte mit RailTime-Einsatzstandorten"><figcaption>RAILTIME / NETWORK / GERMANY</figcaption></figure>
</main>
HTML,
        };
    }

    private function brandLockup(bool $reverse = false): string
    {
        $variant = $reverse ? 'reverse' : 'standard';
        $asset = $reverse ? '/rt-brand/img/logo-horizontal-darkbg.png' : '/rt-brand/img/logo-horizontal.png';

        return '<div class="rt-brand rt-brand-lockup rt-brand-lockup-'.$variant.'" data-rt-brand-lockup="official"><img class="rt-brand-logo" src="'.$asset.'" alt="RT Rail Time GmbH"></div>';
    }

    private function css(string $templateKey, MarketingCreativeFormat $format): string
    {
        $dimensions = $format->dimensions();
        $base = <<<CSS
@font-face{font-family:Manrope;src:url("/rt-brand/fonts/manrope-latin.woff2") format("woff2");font-style:normal;font-weight:400 800;font-display:swap}@font-face{font-family:"Space Mono";src:url("/rt-brand/fonts/space-mono-700-latin.woff2") format("woff2");font-style:normal;font-weight:700;font-display:swap}*{box-sizing:border-box}.rt-marketing-canvas{position:relative;overflow:hidden;width:{$dimensions['width']}px;height:{$dimensions['height']}px;margin:0;background:#f4f2ed;color:#10151b;font-family:Manrope,Arial,sans-serif}.rt-premium:before{position:absolute;z-index:0;inset:0;background-image:linear-gradient(rgba(16,21,27,.045) 1px,transparent 1px),linear-gradient(90deg,rgba(16,21,27,.045) 1px,transparent 1px);background-size:54px 54px;content:"";pointer-events:none}.rt-premium h1,.rt-premium p,.rt-premium figure{margin:0}.rt-premium .rt-mast,.rt-premium .rt-copy,.rt-premium footer{position:absolute;z-index:3}.rt-brand-lockup{position:relative;width:240px}.rt-brand-logo{display:block;width:100%;height:auto}.rt-code,.rt-kicker,.rt-premium figcaption{font-family:"Space Mono",monospace;font-weight:700;letter-spacing:.14em;text-transform:uppercase}.rt-code{color:#7d8791;font-size:13px}.rt-kicker{color:#e4002b;font-size:15px}.rt-premium h1{font-weight:700;letter-spacing:-.063em}.rt-subtitle{font-weight:600;letter-spacing:-.025em}.rt-intro{color:#57636e;line-height:1.55}.rt-photo,.rt-map{position:absolute;z-index:1;overflow:hidden}.rt-photo img,.rt-map img{display:block;width:100%;height:100%;object-fit:cover}.rt-premium figcaption{position:absolute;z-index:2;color:#fff;font-size:11px}.rt-cta{display:inline-flex;min-height:56px;padding:0 25px;align-items:center;justify-content:center;background:#e4002b;color:#fff;font-size:14px;font-weight:800;letter-spacing:.035em;text-decoration:none;text-transform:uppercase}.rt-cta:after{margin-left:22px;content:"→"}.rt-facts{display:grid}.rt-facts>div{display:grid}.rt-facts strong{font-family:"Space Mono",monospace;font-weight:700;letter-spacing:-.06em}.rt-facts span{font-weight:650;line-height:1.25}.rt-keywords{display:flex;margin:0;padding:0;list-style:none}.rt-keywords li{font-family:"Space Mono",monospace;font-weight:700;text-transform:uppercase}.rt-keywords li+li:before{margin:0 13px;color:#e4002b;content:"/"}.rt-actions{display:flex;align-items:center;gap:22px}
.rt-premium .rt-intro{overflow-wrap:anywhere}
CSS;

        $specific = match ([$templateKey, $format]) {
            [MarketingTemplateFactory::PREMIUM_COMPANY_PROFILE, MarketingCreativeFormat::Story] => <<<'CSS'
.rt-company-story{background:#f4f2ed}.rt-company-story .rt-mast{top:52px;right:58px;left:58px;display:flex;align-items:center;justify-content:space-between}.rt-company-story .rt-brand-lockup{width:258px}.rt-company-story .rt-copy{top:200px;right:58px;left:58px}.rt-company-story h1{max-width:960px;margin-top:18px;font-size:99px;line-height:.94}.rt-company-story .rt-subtitle{max-width:780px;margin-top:27px;font-size:31px}.rt-company-story .rt-photo{top:590px;right:0;width:770px;height:650px;background:#1a222c}.rt-company-story .rt-photo:before{position:absolute;z-index:2;top:26px;left:26px;width:145px;height:145px;border-top:3px solid #e4002b;border-left:3px solid #e4002b;content:""}.rt-company-story .rt-photo img{object-position:center 45%}.rt-company-story .rt-photo figcaption{right:28px;bottom:25px;padding:13px 16px;background:#e4002b}.rt-company-story .rt-intro-panel{position:absolute;z-index:2;top:675px;left:58px;width:380px;padding:38px 34px;background:#10151b;color:#fff}.rt-company-story .rt-intro{color:#c3cbd2;font-size:19px}.rt-company-story .rt-facts{position:absolute;top:1335px;right:58px;left:58px;grid-template-columns:repeat(3,1fr);border-top:1px solid rgba(16,21,27,.2);border-bottom:1px solid rgba(16,21,27,.2)}.rt-company-story .rt-facts>div{min-height:170px;padding:31px 25px 28px;border-right:1px solid rgba(16,21,27,.18)}.rt-company-story .rt-facts>div:last-child{border:0}.rt-company-story .rt-facts strong{font-size:55px}.rt-company-story .rt-facts span{margin-top:14px;font-size:15px;text-transform:uppercase}.rt-company-story footer{right:0;bottom:0;left:0;display:flex;height:305px;padding:54px 58px;align-items:flex-start;background:#090c11;color:#fff}.rt-company-story footer:before{position:absolute;top:0;left:58px;width:90px;height:3px;background:#e4002b;content:""}.rt-company-story footer>div{display:grid;margin-left:auto;text-align:right;gap:8px}.rt-company-story footer strong{font-size:20px}.rt-company-story footer span{color:#aeb7c0;font-size:15px}
.rt-company-story .rt-mast{top:104px}.rt-company-story .rt-copy{top:242px}.rt-company-story .rt-code,.rt-company-story .rt-kicker{font-size:18px}.rt-company-story .rt-photo{top:625px;height:615px}.rt-company-story .rt-photo:before{right:26px;left:auto;border-right:3px solid #e4002b;border-left:0}.rt-company-story .rt-intro-panel{top:710px;width:400px}.rt-company-story .rt-intro{font-size:25px;line-height:1.4}.rt-company-story .rt-facts span{font-size:18px}.rt-company-story .rt-cta{font-size:16px}.rt-company-story footer strong{font-size:22px}.rt-company-story footer span{font-size:18px}
CSS,
            [MarketingTemplateFactory::PREMIUM_COMPANY_PROFILE, MarketingCreativeFormat::Post] => <<<'CSS'
.rt-company-post{background:#10151b;color:#fff}.rt-company-post:before{background-image:linear-gradient(rgba(255,255,255,.045) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.045) 1px,transparent 1px)}.rt-company-post .rt-mast{top:44px;right:48px;left:48px;display:flex;align-items:center;justify-content:space-between}.rt-company-post .rt-brand-lockup{width:230px}.rt-company-post .rt-photo{top:0;right:0;width:47%;height:760px}.rt-company-post .rt-photo:after{position:absolute;inset:0;background:linear-gradient(90deg,#10151b 0,transparent 32%),linear-gradient(0deg,#10151b 0,transparent 30%);content:""}.rt-company-post .rt-photo img{object-position:center}.rt-company-post .rt-photo figcaption{right:22px;bottom:24px}.rt-company-post .rt-copy{top:195px;left:48px;width:610px}.rt-company-post h1{margin-top:14px;font-size:70px;line-height:.92}.rt-company-post .rt-subtitle{max-width:560px;margin-top:19px;font-size:25px}.rt-company-post .rt-intro{max-width:520px;margin-top:22px;color:#b7c0c9;font-size:17px}.rt-company-post .rt-cta{margin-top:30px}.rt-company-post footer{right:0;bottom:0;left:0;display:flex;height:265px;padding:38px 48px;align-items:flex-start;background:#f4f2ed;color:#10151b}.rt-company-post footer .rt-facts{width:790px;grid-template-columns:repeat(3,1fr)}.rt-company-post .rt-facts>div{padding-right:25px;border-right:1px solid rgba(16,21,27,.17)}.rt-company-post .rt-facts>div+div{padding-left:25px}.rt-company-post .rt-facts strong{font-size:39px}.rt-company-post .rt-facts span{margin-top:7px;font-size:12px;text-transform:uppercase}.rt-company-post footer>strong{margin-left:auto;font-size:14px}
CSS,
            [MarketingTemplateFactory::PREMIUM_COMPANY_PROFILE, MarketingCreativeFormat::Web] => <<<'CSS'
.rt-company-web{background:#10151b;color:#fff}.rt-company-web:before{background-image:linear-gradient(rgba(255,255,255,.04) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.04) 1px,transparent 1px);background-size:42px 42px}.rt-company-web .rt-copy{top:0;bottom:0;left:0;width:61%;padding:35px 48px}.rt-company-web .rt-brand-lockup{width:202px}.rt-company-web .rt-kicker{margin-top:25px;font-size:11px}.rt-company-web h1{max-width:685px;margin-top:10px;font-size:52px;line-height:.93}.rt-company-web .rt-subtitle{margin-top:13px;font-size:19px}.rt-company-web .rt-intro{max-width:610px;margin-top:13px;color:#b9c2ca;font-size:13px}.rt-company-web .rt-actions{margin-top:18px}.rt-company-web .rt-cta{min-height:46px;padding:0 19px;font-size:11px}.rt-company-web .rt-actions strong{font-size:12px}.rt-company-web .rt-photo{top:0;right:0;width:42%;height:630px}.rt-company-web .rt-photo:after{position:absolute;inset:0;background:linear-gradient(90deg,#10151b 0,transparent 23%),linear-gradient(0deg,rgba(9,12,17,.74),transparent 45%);content:""}.rt-company-web .rt-photo img{object-position:center}.rt-company-web .rt-photo figcaption{right:22px;bottom:20px}.rt-company-web footer{right:22px;bottom:65px;width:420px;padding:16px 18px;background:rgba(9,12,17,.9)}.rt-company-web footer .rt-facts{grid-template-columns:repeat(3,1fr)}.rt-company-web .rt-facts strong{font-size:24px}.rt-company-web .rt-facts span{margin-top:4px;color:#fff;font-size:9px;text-transform:uppercase}
CSS,
            [MarketingTemplateFactory::PREMIUM_JOB_WAGENMEISTER, MarketingCreativeFormat::Story] => <<<'CSS'
.rt-job-premium-story{background:#090c11;color:#fff}.rt-job-premium-story:before{z-index:2;background-image:linear-gradient(rgba(255,255,255,.035) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.035) 1px,transparent 1px)}.rt-job-premium-story .rt-photo{inset:0 0 auto;height:1120px;background:#090c11}.rt-job-premium-story .rt-photo:after{position:absolute;inset:0;background:linear-gradient(180deg,rgba(9,12,17,.16),rgba(9,12,17,.04) 32%,#090c11 96%),linear-gradient(90deg,rgba(9,12,17,.5),transparent 68%);content:""}.rt-job-premium-story .rt-photo img{object-position:center 48%}.rt-job-premium-story .rt-mast{top:52px;right:58px;left:58px;display:flex;align-items:center;justify-content:space-between}.rt-job-premium-story .rt-brand-lockup{width:260px}.rt-job-premium-story .rt-code{color:#fff}.rt-job-premium-story .rt-copy{top:430px;right:58px;left:58px}.rt-job-premium-story .rt-kicker{font-size:15px}.rt-job-premium-story h1{max-width:900px;margin-top:18px;font-size:113px;line-height:.86}.rt-job-premium-story .rt-subtitle{max-width:800px;margin-top:27px;font-size:31px;line-height:1.2}.rt-job-premium-story .rt-job-panel{position:absolute;z-index:3;top:1080px;right:58px;left:58px}.rt-job-premium-story .rt-intro{max-width:890px;color:#c2cbd3;font-size:20px}.rt-job-premium-story .rt-keywords{margin-top:28px;font-size:14px}.rt-job-premium-story .rt-facts{margin-top:42px;grid-template-columns:repeat(3,1fr);border-top:1px solid rgba(255,255,255,.18);border-bottom:1px solid rgba(255,255,255,.18)}.rt-job-premium-story .rt-facts>div{min-height:150px;padding:27px 18px;border-right:1px solid rgba(255,255,255,.18)}.rt-job-premium-story .rt-facts>div:last-child{border:0}.rt-job-premium-story .rt-facts strong{color:#fff;font-size:50px}.rt-job-premium-story .rt-facts span{margin-top:10px;color:#aab4bd;font-size:14px;text-transform:uppercase}.rt-job-premium-story footer{right:0;bottom:0;left:0;display:flex;height:390px;padding:48px 58px 210px;isolation:isolate;border-top:3px solid #e4002b;background-image:linear-gradient(rgba(255,255,255,.024) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.024) 1px,transparent 1px),radial-gradient(70% 180% at 96% 48%,rgba(228,0,43,.2),transparent 62%),linear-gradient(112deg,#111923 0,#0c1119 54%,#070a0f 100%);background-size:54px 54px,54px 54px,auto,auto;color:#fff}.rt-job-premium-story footer:before{position:absolute;z-index:-1;inset:0;background:linear-gradient(116deg,transparent 0 57%,rgba(228,0,43,.32) 57.2% 57.55%,transparent 57.8%);content:""}.rt-job-premium-story footer:after{position:absolute;z-index:-1;right:58px;bottom:20px;width:310px;height:310px;background:url("/rt-brand/rt-logo.svg") center/contain no-repeat;content:"";filter:grayscale(1) brightness(3);opacity:.055}.rt-job-premium-story footer>div{display:grid;margin-left:auto;text-align:right;gap:9px}.rt-job-premium-story footer strong{color:#fff;font-size:23px}.rt-job-premium-story footer span{color:#b7c1ca;font-size:14px}
.rt-job-premium-story .rt-photo img{object-position:31% center}.rt-job-premium-story .rt-mast{top:104px}.rt-job-premium-story .rt-code,.rt-job-premium-story .rt-kicker{font-size:18px}.rt-job-premium-story .rt-copy{top:450px}.rt-job-premium-story .rt-intro{font-size:26px;line-height:1.42}.rt-job-premium-story .rt-keywords{font-size:17px}.rt-job-premium-story .rt-facts span{font-size:17px}.rt-job-premium-story footer{align-items:flex-start}.rt-job-premium-story .rt-cta{font-size:16px}.rt-job-premium-story footer strong{font-size:24px}.rt-job-premium-story footer span{font-size:18px}
CSS,
            [MarketingTemplateFactory::PREMIUM_JOB_WAGENMEISTER, MarketingCreativeFormat::Post] => <<<'CSS'
.rt-job-premium-post{background:#090c11;color:#fff}.rt-job-premium-post .rt-photo{top:0;right:0;width:55%;height:815px}.rt-job-premium-post .rt-photo:after{position:absolute;inset:0;background:linear-gradient(90deg,#090c11 0,transparent 31%),linear-gradient(0deg,#090c11 0,transparent 27%);content:""}.rt-job-premium-post .rt-photo img{object-position:center 48%}.rt-job-premium-post .rt-mast{top:42px;right:48px;left:48px;display:flex;align-items:center;justify-content:space-between}.rt-job-premium-post .rt-brand-lockup{width:230px}.rt-job-premium-post .rt-code{color:#fff}.rt-job-premium-post .rt-copy{top:240px;left:48px;width:690px}.rt-job-premium-post h1{margin-top:13px;font-size:78px;line-height:.87}.rt-job-premium-post .rt-subtitle{max-width:570px;margin-top:20px;font-size:25px}.rt-job-premium-post .rt-keywords{margin-top:25px;font-size:12px}.rt-job-premium-post .rt-cta{margin-top:32px}.rt-job-premium-post footer{right:0;bottom:0;left:0;display:flex;height:265px;padding:39px 48px;background-image:linear-gradient(rgba(255,255,255,.024) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.024) 1px,transparent 1px),radial-gradient(70% 180% at 96% 48%,rgba(228,0,43,.2),transparent 62%),linear-gradient(112deg,#111923 0,#0c1119 54%,#070a0f 100%);background-size:54px 54px,54px 54px,auto,auto;color:#fff}.rt-job-premium-post footer:before{position:absolute;top:0;right:48px;left:48px;height:3px;background:#e4002b;content:""}.rt-job-premium-post footer .rt-facts{width:790px;grid-template-columns:repeat(3,1fr)}.rt-job-premium-post .rt-facts>div{padding-right:24px;border-right:1px solid rgba(255,255,255,.18)}.rt-job-premium-post .rt-facts>div+div{padding-left:24px}.rt-job-premium-post .rt-facts strong{color:#fff;font-size:39px}.rt-job-premium-post .rt-facts span{margin-top:7px;color:#b7c1ca;font-size:12px;text-transform:uppercase}.rt-job-premium-post footer>strong{margin-left:auto;color:#fff;font-size:14px}
.rt-job-premium-post .rt-photo img{object-position:42% center}
CSS,
            [MarketingTemplateFactory::PREMIUM_JOB_WAGENMEISTER, MarketingCreativeFormat::Web] => <<<'CSS'
.rt-job-premium-web{background:#090c11;color:#fff}.rt-job-premium-web .rt-copy{top:0;bottom:0;left:0;width:59%;padding:35px 48px}.rt-job-premium-web .rt-brand-lockup{width:205px}.rt-job-premium-web .rt-kicker{margin-top:28px;font-size:11px}.rt-job-premium-web h1{margin-top:10px;font-size:59px;line-height:.86}.rt-job-premium-web .rt-subtitle{max-width:620px;margin-top:14px;font-size:20px}.rt-job-premium-web .rt-intro{max-width:610px;margin-top:14px;color:#bcc5cd;font-size:13px}.rt-job-premium-web .rt-actions{margin-top:20px}.rt-job-premium-web .rt-cta{min-height:46px;padding:0 19px;font-size:11px}.rt-job-premium-web .rt-actions strong{font-size:12px}.rt-job-premium-web .rt-photo{top:0;right:0;width:45%;height:630px}.rt-job-premium-web .rt-photo:after{position:absolute;inset:0;background:linear-gradient(90deg,#090c11 0,transparent 25%),linear-gradient(0deg,rgba(9,12,17,.7),transparent 50%);content:""}.rt-job-premium-web .rt-photo img{object-position:center 48%}.rt-job-premium-web .rt-photo figcaption{right:22px;bottom:20px}.rt-job-premium-web footer{right:22px;bottom:66px;width:430px;padding:17px 18px;border-top:2px solid #e4002b;background-image:linear-gradient(rgba(255,255,255,.024) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.024) 1px,transparent 1px),radial-gradient(70% 180% at 96% 48%,rgba(228,0,43,.2),transparent 62%),linear-gradient(112deg,#111923 0,#0c1119 54%,#070a0f 100%);background-size:42px 42px,42px 42px,auto,auto}.rt-job-premium-web footer .rt-facts{grid-template-columns:repeat(3,1fr)}.rt-job-premium-web .rt-facts strong{font-size:24px}.rt-job-premium-web .rt-facts span{margin-top:4px;color:#fff;font-size:9px;text-transform:uppercase}
.rt-job-premium-web .rt-photo img{object-position:42% center}
CSS,
            [MarketingTemplateFactory::PREMIUM_GERMANY_NETWORK, MarketingCreativeFormat::Story] => <<<'CSS'
.rt-network-premium-story{background:#f4f2ed}.rt-network-premium-story .rt-mast{top:52px;right:58px;left:58px;display:flex;align-items:center;justify-content:space-between}.rt-network-premium-story .rt-brand-lockup{width:255px}.rt-network-premium-story .rt-copy{top:190px;right:58px;left:58px}.rt-network-premium-story h1{max-width:920px;margin-top:17px;font-size:104px;line-height:.91}.rt-network-premium-story .rt-subtitle{max-width:790px;margin-top:25px;font-size:29px}.rt-network-premium-story .rt-map{top:555px;right:20px;width:700px;height:1000px}.rt-network-premium-story .rt-map:before{position:absolute;z-index:0;inset:60px 25px 30px;background:radial-gradient(circle,#fff 0,rgba(255,255,255,.78) 42%,transparent 72%);content:""}.rt-network-premium-story .rt-map img{position:relative;z-index:1;object-fit:contain;filter:saturate(.72) contrast(1.02)}.rt-network-premium-story .rt-map figcaption{right:55px;bottom:80px;color:#606a74;font-size:10px}.rt-network-premium-story .rt-network-panel{position:absolute;z-index:2;top:680px;left:58px;width:390px;padding:35px 30px;background:#10151b;color:#fff}.rt-network-premium-story .rt-intro{color:#c4ccd3;font-size:18px}.rt-network-premium-story .rt-facts{position:absolute;top:1160px;left:58px;width:360px;gap:0;border-top:1px solid rgba(16,21,27,.18)}.rt-network-premium-story .rt-facts>div{padding:21px 0;border-bottom:1px solid rgba(16,21,27,.18)}.rt-network-premium-story .rt-facts strong{color:#e4002b;font-size:43px}.rt-network-premium-story .rt-facts span{margin-top:5px;font-size:13px;text-transform:uppercase}.rt-network-premium-story footer{right:0;bottom:0;left:0;display:flex;height:285px;padding:52px 58px;background:#090c11;color:#fff}.rt-network-premium-story footer>div{display:grid;margin-left:auto;text-align:right;gap:8px}.rt-network-premium-story footer strong{font-size:24px}.rt-network-premium-story footer span{color:#b4bdc5;font-size:14px}
.rt-network-premium-story .rt-mast{top:104px}.rt-network-premium-story .rt-copy{top:230px}.rt-network-premium-story .rt-code,.rt-network-premium-story .rt-kicker{font-size:18px}.rt-network-premium-story .rt-map{top:590px;right:20px;width:660px;height:950px}.rt-network-premium-story .rt-map figcaption{right:24px;bottom:54px;padding:8px 10px;background:rgba(244,242,237,.9);color:#10151b;font-size:17px}.rt-network-premium-story .rt-network-panel{top:720px;width:340px;padding:32px 28px}.rt-network-premium-story .rt-intro{font-size:24px;line-height:1.42}.rt-network-premium-story .rt-facts{top:1110px;width:340px}.rt-network-premium-story .rt-facts span{font-size:17px}.rt-network-premium-story footer{align-items:flex-start}.rt-network-premium-story .rt-cta{font-size:16px}.rt-network-premium-story footer strong{font-size:26px}.rt-network-premium-story footer span{font-size:18px}
CSS,
            [MarketingTemplateFactory::PREMIUM_GERMANY_NETWORK, MarketingCreativeFormat::Post] => <<<'CSS'
.rt-network-premium-post{background:#f4f2ed}.rt-network-premium-post .rt-mast{top:43px;right:48px;left:48px;display:flex;align-items:center;justify-content:space-between}.rt-network-premium-post .rt-brand-lockup{width:225px}.rt-network-premium-post .rt-copy{top:180px;left:48px;width:500px}.rt-network-premium-post h1{margin-top:13px;font-size:68px;line-height:.91}.rt-network-premium-post .rt-subtitle{margin-top:18px;font-size:23px}.rt-network-premium-post .rt-intro{margin-top:20px;font-size:15px}.rt-network-premium-post .rt-cta{margin-top:28px}.rt-network-premium-post .rt-map{top:195px;right:-80px;width:650px;height:720px}.rt-network-premium-post .rt-map img{object-fit:contain;filter:saturate(.72) contrast(1.03)}.rt-network-premium-post footer{right:0;bottom:0;left:0;display:flex;height:165px;padding:28px 48px;align-items:flex-start;background:#10151b;color:#fff}.rt-network-premium-post footer .rt-facts{width:780px;grid-template-columns:repeat(3,1fr)}.rt-network-premium-post .rt-facts>div{padding-right:22px;border-right:1px solid rgba(255,255,255,.18)}.rt-network-premium-post .rt-facts>div+div{padding-left:22px}.rt-network-premium-post .rt-facts strong{color:#fff;font-size:34px}.rt-network-premium-post .rt-facts span{margin-top:5px;color:#b8c1c9;font-size:11px;text-transform:uppercase}.rt-network-premium-post footer>strong{margin-left:auto;font-size:13px}
.rt-network-premium-post .rt-map{right:0;width:580px}
CSS,
            [MarketingTemplateFactory::PREMIUM_GERMANY_NETWORK, MarketingCreativeFormat::Web] => <<<'CSS'
.rt-network-premium-web{display:grid;background:#10151b;color:#fff;grid-template-columns:59% 41%}.rt-network-premium-web:before{background-image:linear-gradient(rgba(255,255,255,.04) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.04) 1px,transparent 1px);background-size:42px 42px}.rt-network-premium-web .rt-copy{top:0;bottom:0;left:0;width:61%;padding:35px 48px}.rt-network-premium-web .rt-brand-lockup{width:200px}.rt-network-premium-web .rt-kicker{margin-top:26px;font-size:11px}.rt-network-premium-web h1{margin-top:9px;font-size:57px;line-height:.9}.rt-network-premium-web .rt-subtitle{max-width:610px;margin-top:12px;font-size:19px}.rt-network-premium-web .rt-intro{max-width:600px;margin-top:12px;color:#bac3cb;font-size:13px}.rt-network-premium-web .rt-facts{margin-top:18px;grid-template-columns:repeat(3,1fr);border-top:1px solid rgba(255,255,255,.17);border-bottom:1px solid rgba(255,255,255,.17)}.rt-network-premium-web .rt-facts>div{padding:12px 9px 12px 0}.rt-network-premium-web .rt-facts strong{font-size:25px}.rt-network-premium-web .rt-facts span{margin-top:4px;color:#b8c1c9;font-size:9px;text-transform:uppercase}.rt-network-premium-web .rt-actions{margin-top:17px}.rt-network-premium-web .rt-cta{min-height:45px;padding:0 18px;font-size:11px}.rt-network-premium-web .rt-actions strong{font-size:13px}.rt-network-premium-web .rt-map{top:0;right:0;width:43%;height:630px;background:#f4f2ed}.rt-network-premium-web .rt-map:before{position:absolute;inset:40px;background:radial-gradient(circle,#fff,transparent 70%);content:""}.rt-network-premium-web .rt-map img{position:relative;object-fit:contain;filter:saturate(.72) contrast(1.03)}.rt-network-premium-web .rt-map figcaption{right:20px;bottom:17px;color:#646d76;font-size:9px}
CSS,
        };

        return $base.$specific;
    }
}
