<?php

namespace App\Services\Marketing;

use App\Enums\MarketingCreativeFormat;
use App\Enums\MarketingCreativeType;
use App\Support\CompanyData;
use InvalidArgumentException;

final class MarketingTemplateFactory
{
    public const JOB_WAGENMEISTER = 'railtime_job_wagenmeister';

    public const INFO_WAGENMEISTER_ROLE = 'railtime_info_wagenmeister';

    public const INFO_GERMANY_NETWORK = 'railtime_info_deutschland_netzwerk';

    public const PREMIUM_COMPANY_PROFILE = 'railtime_2026_unternehmen';

    public const PREMIUM_JOB_WAGENMEISTER = 'railtime_2026_job_wagenmeister';

    public const PREMIUM_GERMANY_NETWORK = 'railtime_2026_deutschland_netzwerk';

    public const SEED_VERSION = 3;

    public const PREMIUM_SEED_VERSION = 4;

    public function __construct(private readonly MarketingPremiumTemplateCatalog $premium) {}

    /** @return list<array{title:string,shared_content:array<string,mixed>,variants:array<string,array{builder_data:array<string,mixed>,html:string,css:string}>}> */
    public function starterDefinitions(): array
    {
        return [
            $this->definitionByKey(self::PREMIUM_COMPANY_PROFILE),
            $this->definitionByKey(self::PREMIUM_JOB_WAGENMEISTER),
            $this->definitionByKey(self::PREMIUM_GERMANY_NETWORK),
        ];
    }

    /**
     * Keeps the public create-by-type contract stable. The dedicated starter
     * catalogue uses definitionByKey() where more than one information motif
     * exists.
     *
     * @return array{title:string,shared_content:array<string,mixed>,variants:array<string,array{builder_data:array<string,mixed>,html:string,css:string}>}
     */
    public function definition(MarketingCreativeType $type): array
    {
        return $this->definitionByKey($type === MarketingCreativeType::Job
            ? self::PREMIUM_JOB_WAGENMEISTER
            : self::PREMIUM_COMPANY_PROFILE);
    }

    public function typeForKey(string $templateKey): MarketingCreativeType
    {
        return match ($templateKey) {
            self::JOB_WAGENMEISTER, self::PREMIUM_JOB_WAGENMEISTER => MarketingCreativeType::Job,
            self::INFO_WAGENMEISTER_ROLE,
            self::INFO_GERMANY_NETWORK,
            self::PREMIUM_COMPANY_PROFILE,
            self::PREMIUM_GERMANY_NETWORK => MarketingCreativeType::Info,
            default => throw new InvalidArgumentException('Unbekannte Marketing-Startvorlage: '.$templateKey),
        };
    }

    public function hasTemplateKey(string $templateKey): bool
    {
        return in_array($templateKey, [
            self::JOB_WAGENMEISTER,
            self::INFO_WAGENMEISTER_ROLE,
            self::INFO_GERMANY_NETWORK,
            self::PREMIUM_COMPANY_PROFILE,
            self::PREMIUM_JOB_WAGENMEISTER,
            self::PREMIUM_GERMANY_NETWORK,
        ], true);
    }

    /** @return array{title:string,shared_content:array<string,mixed>,variants:array<string,array{builder_data:array<string,mixed>,html:string,css:string}>} */
    public function definitionByKey(string $templateKey): array
    {
        if ($this->premium->hasTemplateKey($templateKey)) {
            return $this->premium->definitionByKey($templateKey);
        }

        $company = CompanyData::all();
        [$type, $title, $content] = match ($templateKey) {
            self::JOB_WAGENMEISTER => [
                MarketingCreativeType::Job,
                'Wagenmeister (m/w/d) – Karriere',
                $this->jobContent($company),
            ],
            self::INFO_WAGENMEISTER_ROLE => [
                MarketingCreativeType::Info,
                'Beruf Wagenmeister – Sicherheit am Zug',
                $this->roleContent($company),
            ],
            self::INFO_GERMANY_NETWORK => [
                MarketingCreativeType::Info,
                'Deutschlandweit im Einsatz – RailTime Netzwerk',
                $this->networkContent($company),
            ],
            default => throw new InvalidArgumentException('Unbekannte Marketing-Startvorlage: '.$templateKey),
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
                        'schema' => 3,
                    ],
                ],
                'html' => $html,
                'css' => $this->css($templateKey, $type, $format),
            ];
        }

        return [
            'title' => $title,
            'shared_content' => $content,
            'variants' => $variants,
        ];
    }

    /** @param array<string,string> $company */
    private function jobContent(array $company): array
    {
        return $this->companyContent($company) + [
            'template_key' => self::JOB_WAGENMEISTER,
            'seed_version' => self::SEED_VERSION,
            'kicker' => 'Karriere bei RailTime',
            'title' => 'Wagenmeister (m/w/d)',
            'subtitle' => 'Verantwortung, die den Güterverkehr bewegt.',
            'intro' => 'Du prüfst Güterwagen mit geschultem Blick, dokumentierst präzise und sorgst gemeinsam mit unserem Team für einen sicheren Bahnbetrieb.',
            'facts' => [
                ['value' => '60+', 'label' => 'Kolleginnen & Kollegen'],
                ['value' => 'DE', 'label' => 'Einsatzorte bundesweit'],
                ['value' => '24/7', 'label' => 'starkes Einsatzteam'],
            ],
            'tasks' => [
                'Güterwagen und Züge technisch untersuchen',
                'Bremsproben und Wagendokumentation durchführen',
                'Schäden erkennen, bewerten und klar kommunizieren',
            ],
            'profile' => [
                'Qualifikation als Wagenmeister oder vergleichbare Ausbildung',
                'Verantwortungsbewusstsein und zuverlässige Arbeitsweise',
                'Teamgeist und Bereitschaft zu wechselnden Einsatzorten',
            ],
            'benefits' => [
                'Unbefristete Perspektive',
                'Moderne Arbeitsmittel',
                'Direkte Kommunikation',
                'Fachliche Weiterentwicklung',
            ],
            'cta_label' => 'Jetzt bei RailTime bewerben',
            'cta_url' => 'https://www.rail-time.de/de/karriere',
            'editorial_note' => 'Qualifikation, Einsatzmodell und Leistungen sind vor Veröffentlichung mit der aktuellen Stellenausschreibung abzugleichen.',
        ];
    }

    /** @param array<string,string> $company */
    private function roleContent(array $company): array
    {
        return $this->companyContent($company) + [
            'template_key' => self::INFO_WAGENMEISTER_ROLE,
            'seed_version' => self::SEED_VERSION,
            'kicker' => 'Beruf mit Verantwortung',
            'title' => 'Was macht ein Wagenmeister?',
            'subtitle' => 'Sicherheit beginnt, bevor der Zug abfährt.',
            'intro' => 'Wagenmeister prüfen Güterwagen und Züge vor der Abfahrt. Sie erkennen Unregelmäßigkeiten, dokumentieren Befunde und schaffen damit eine wichtige Grundlage für einen sicheren Eisenbahnbetrieb.',
            'facts' => [
                ['value' => '01', 'label' => 'Prüfen'],
                ['value' => '02', 'label' => 'Bewerten'],
                ['value' => '03', 'label' => 'Dokumentieren'],
            ],
            'tasks' => [
                'Technischen Zustand von Güterwagen beurteilen',
                'Bremsproben und betriebliche Prüfungen begleiten',
                'Befunde nachvollziehbar dokumentieren und abstimmen',
            ],
            'profile' => [
                'Technisches Verständnis und qualifizierte Ausbildung',
                'Konzentration, Sorgfalt und klare Entscheidungen',
                'Kommunikation mit Disposition und Betrieb',
            ],
            'benefits' => [
                'Ein Beruf mit sichtbarer Wirkung',
                'Abwechslungsreiche Einsatzorte',
                'Arbeit im qualifizierten Team',
            ],
            'cta_label' => 'Beruf & Karriere entdecken',
            'cta_url' => 'https://www.rail-time.de/de/karriere',
            'editorial_note' => 'Die Darstellung beschreibt das Berufsbild allgemein und ersetzt keine verbindliche Stellen- oder Qualifikationsbeschreibung.',
        ];
    }

    /** @param array<string,string> $company */
    private function networkContent(array $company): array
    {
        return $this->companyContent($company) + [
            'template_key' => self::INFO_GERMANY_NETWORK,
            'seed_version' => self::SEED_VERSION,
            'kicker' => 'RailTime Netzwerk',
            'title' => 'Deutschlandweit im Einsatz',
            'subtitle' => 'Wagenmeister-Kompetenz dort, wo sie gebraucht wird.',
            'intro' => 'Mit mehr als 60 erfahrenen Wagenmeistern unterstützt RailTime Eisenbahnverkehrsunternehmen bundesweit – für planbare Einsätze und bei dringenden technischen Unregelmäßigkeiten rund um die Uhr.',
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
                'Qualifiziertes Fachpersonal',
                'Flexible Einsatzkoordination',
                'Klare Dokumentation und Kommunikation',
            ],
            'benefits' => [
                'Ein Ansprechpartner',
                'Bundesweit koordiniert',
                'Rund um die Uhr erreichbar',
            ],
            'cta_label' => 'Einsatz jetzt anfragen',
            'cta_url' => 'https://www.rail-time.de/de/kontakt',
            'editorial_note' => 'Konkrete Verfügbarkeit, Reaktionszeit und Leistungsumfang werden für jede Einsatzanfrage individuell bestätigt.',
        ];
    }

    /** @param array<string,string> $company */
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
            self::JOB_WAGENMEISTER => $this->jobHtml($format),
            self::INFO_WAGENMEISTER_ROLE => $this->roleHtml($format),
            self::INFO_GERMANY_NETWORK => $this->networkHtml($format),
        };
    }

    private function jobHtml(MarketingCreativeFormat $format): string
    {
        $logo = $this->brandLockup(true);

        return match ($format) {
            MarketingCreativeFormat::Story => <<<HTML
<main class="rt-marketing-canvas rt-job rt-job-story">
  <section class="rt-photo"><img src="/rt-brand/img/wagenmeister-pruefung.jpg" alt="Wagenmeister bei der technischen Prüfung eines Güterwagens"><span class="rt-photo-code">RAIL · PEOPLE · SAFETY</span></section>
  <header>{$logo}<p class="rt-kicker" data-rt-binding="kicker"></p><h1 data-rt-binding="title"></h1><p class="rt-subtitle" data-rt-binding="subtitle"></p></header>
  <section class="rt-job-body"><p class="rt-intro" data-rt-binding="intro"></p><div class="rt-facts" data-rt-binding-facts="facts"></div><div class="rt-columns"><article><span>01 / DEIN EINSATZ</span><ul data-rt-binding-list="tasks"></ul></article><article><span>02 / DEIN PROFIL</span><ul data-rt-binding-list="profile"></ul></article></div></section>
  <footer><div><small>KOMM INS TEAM</small><strong>Deine nächste Station:<br>RailTime.</strong></div><a class="rt-cta" data-rt-binding="cta_label" data-rt-binding-href="cta_url"></a><p><span data-rt-binding="contact_email"></span> · <span data-rt-binding="website"></span></p></footer>
</main>
HTML,
            MarketingCreativeFormat::Post => <<<HTML
<main class="rt-marketing-canvas rt-job rt-job-post">
  <section class="rt-photo"><img src="/rt-brand/img/wagenmeister-pruefung.jpg" alt="Wagenmeister bei der technischen Prüfung eines Güterwagens"><span class="rt-photo-code">JOB / WAGENMEISTER</span></section>
  <section class="rt-copy">{$logo}<p class="rt-kicker" data-rt-binding="kicker"></p><h1 data-rt-binding="title"></h1><p class="rt-subtitle" data-rt-binding="subtitle"></p><p class="rt-intro" data-rt-binding="intro"></p><ul data-rt-binding-list="benefits"></ul><a class="rt-cta" data-rt-binding="cta_label" data-rt-binding-href="cta_url"></a></section>
  <footer><div class="rt-facts" data-rt-binding-facts="facts"></div><strong data-rt-binding="website"></strong></footer>
</main>
HTML,
            MarketingCreativeFormat::Web => <<<HTML
<main class="rt-marketing-canvas rt-job rt-job-web">
  <section class="rt-copy">{$logo}<p class="rt-kicker" data-rt-binding="kicker"></p><h1 data-rt-binding="title"></h1><p class="rt-subtitle" data-rt-binding="subtitle"></p><p class="rt-intro" data-rt-binding="intro"></p><div class="rt-actions"><a class="rt-cta" data-rt-binding="cta_label" data-rt-binding-href="cta_url"></a><span data-rt-binding="contact_email"></span></div></section>
  <section class="rt-photo"><img src="/rt-brand/img/wagenmeister-pruefung.jpg" alt="Wagenmeister bei der technischen Prüfung eines Güterwagens"><span class="rt-photo-code">KARRIERE / DEUTSCHLANDWEIT</span></section>
  <footer><div class="rt-facts" data-rt-binding-facts="facts"></div></footer>
</main>
HTML,
        };
    }

    private function roleHtml(MarketingCreativeFormat $format): string
    {
        $logo = $this->brandLockup();

        return match ($format) {
            MarketingCreativeFormat::Story => <<<HTML
<main class="rt-marketing-canvas rt-role rt-role-story">
  <header>{$logo}<p class="rt-kicker" data-rt-binding="kicker"></p><h1 data-rt-binding="title"></h1><p class="rt-subtitle" data-rt-binding="subtitle"></p></header>
  <section class="rt-photo"><img src="/rt-brand/img/wagenmeister-team.webp" alt="RailTime-Wagenmeister stimmen einen Einsatz im Team ab"><span>VERANTWORTUNG · TECHNIK · TEAM</span></section>
  <section class="rt-role-body"><p class="rt-intro" data-rt-binding="intro"></p><div class="rt-facts" data-rt-binding-facts="facts"></div><article><span>DER AUFTRAG</span><ul data-rt-binding-list="tasks"></ul></article><article><span>DAS BRINGST DU MIT</span><ul data-rt-binding-list="profile"></ul></article></section>
  <footer><a class="rt-cta" data-rt-binding="cta_label" data-rt-binding-href="cta_url"></a><p><span data-rt-binding="website"></span> · <span data-rt-binding="contact_email"></span></p></footer>
</main>
HTML,
            MarketingCreativeFormat::Post => <<<HTML
<main class="rt-marketing-canvas rt-role rt-role-post">
  <header>{$logo}<p class="rt-kicker" data-rt-binding="kicker"></p><h1 data-rt-binding="title"></h1><p class="rt-subtitle" data-rt-binding="subtitle"></p></header>
  <section class="rt-photo"><img src="/rt-brand/img/wagenmeister-team.webp" alt="RailTime-Wagenmeister stimmen einen Einsatz im Team ab"></section>
  <section class="rt-role-body"><p class="rt-intro" data-rt-binding="intro"></p><div class="rt-facts" data-rt-binding-facts="facts"></div><a class="rt-cta" data-rt-binding="cta_label" data-rt-binding-href="cta_url"></a></section>
  <footer><span>BERUFSBILD / WAGENMEISTER</span><strong data-rt-binding="website"></strong></footer>
</main>
HTML,
            MarketingCreativeFormat::Web => <<<HTML
<main class="rt-marketing-canvas rt-role rt-role-web">
  <section class="rt-photo"><img src="/rt-brand/img/wagenmeister-team.webp" alt="RailTime-Wagenmeister stimmen einen Einsatz im Team ab"><span>BERUF MIT VERANTWORTUNG</span></section>
  <section class="rt-role-body">{$logo}<p class="rt-kicker" data-rt-binding="kicker"></p><h1 data-rt-binding="title"></h1><p class="rt-subtitle" data-rt-binding="subtitle"></p><p class="rt-intro" data-rt-binding="intro"></p><div class="rt-facts" data-rt-binding-facts="facts"></div><a class="rt-cta" data-rt-binding="cta_label" data-rt-binding-href="cta_url"></a></section>
</main>
HTML,
        };
    }

    private function networkHtml(MarketingCreativeFormat $format): string
    {
        $logo = $this->brandLockup($format !== MarketingCreativeFormat::Story);

        return match ($format) {
            MarketingCreativeFormat::Story => <<<HTML
<main class="rt-marketing-canvas rt-network rt-network-story">
  <header>{$logo}<p class="rt-kicker" data-rt-binding="kicker"></p><h1 data-rt-binding="title"></h1><p class="rt-subtitle" data-rt-binding="subtitle"></p></header>
  <section class="rt-map"><img src="/rt-brand/img/deutschland-netzwerk.png" alt="Deutschlandkarte mit RailTime-Einsatzstandorten"><span>NETWORK / GERMANY</span></section>
  <section class="rt-network-body"><p class="rt-intro" data-rt-binding="intro"></p><div class="rt-facts" data-rt-binding-facts="facts"></div><ul data-rt-binding-list="tasks"></ul></section>
  <footer><a class="rt-cta" data-rt-binding="cta_label" data-rt-binding-href="cta_url"></a><div><strong data-rt-binding="contact_phone"></strong><span data-rt-binding="contact_email"></span></div><p data-rt-binding="website"></p></footer>
</main>
HTML,
            MarketingCreativeFormat::Post => <<<HTML
<main class="rt-marketing-canvas rt-network rt-network-post">
  <header>{$logo}<p class="rt-kicker" data-rt-binding="kicker"></p><h1 data-rt-binding="title"></h1><p class="rt-subtitle" data-rt-binding="subtitle"></p></header>
  <section class="rt-map"><img src="/rt-brand/img/deutschland-netzwerk.png" alt="Deutschlandkarte mit RailTime-Einsatzstandorten"></section>
  <section class="rt-network-body"><div class="rt-facts" data-rt-binding-facts="facts"></div><p class="rt-intro" data-rt-binding="intro"></p><a class="rt-cta" data-rt-binding="cta_label" data-rt-binding-href="cta_url"></a></section>
  <footer><span data-rt-binding="contact_phone"></span><strong data-rt-binding="website"></strong></footer>
</main>
HTML,
            MarketingCreativeFormat::Web => <<<HTML
<main class="rt-marketing-canvas rt-network rt-network-web">
  <section class="rt-network-body">{$logo}<p class="rt-kicker" data-rt-binding="kicker"></p><h1 data-rt-binding="title"></h1><p class="rt-subtitle" data-rt-binding="subtitle"></p><p class="rt-intro" data-rt-binding="intro"></p><div class="rt-facts" data-rt-binding-facts="facts"></div><div class="rt-actions"><a class="rt-cta" data-rt-binding="cta_label" data-rt-binding-href="cta_url"></a><span data-rt-binding="contact_phone"></span></div></section>
  <section class="rt-map"><img src="/rt-brand/img/deutschland-netzwerk.png" alt="Deutschlandkarte mit RailTime-Einsatzstandorten"><span>NETWORK / GERMANY</span></section>
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

    private function css(string $templateKey, MarketingCreativeType $type, MarketingCreativeFormat $format): string
    {
        $dimensions = $format->dimensions();
        $base = <<<CSS
*{box-sizing:border-box}.rt-marketing-canvas{position:relative;overflow:hidden;width:{$dimensions['width']}px;height:{$dimensions['height']}px;margin:0;background:#f4f1ea;color:#102237;font-family:Arial,"Helvetica Neue",sans-serif}.rt-marketing-canvas h1,.rt-marketing-canvas h2,.rt-marketing-canvas p{margin:0}.rt-brand-lockup{position:relative;z-index:4;width:245px}.rt-brand-logo{display:block;width:100%;height:auto}.rt-kicker{color:#d71932;font-size:16px;font-weight:900;letter-spacing:.16em;text-transform:uppercase}.rt-subtitle{font-weight:700}.rt-intro{color:#46596a;line-height:1.48}.rt-photo,.rt-map{position:relative;overflow:hidden}.rt-photo img,.rt-map img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover}.rt-cta{display:inline-flex;min-height:58px;padding:0 28px;align-items:center;justify-content:center;border-radius:4px;background:#d71932;color:#fff;font-size:17px;font-weight:900;text-decoration:none}.rt-cta:after{margin-left:18px;content:"→"}.rt-facts{display:flex}.rt-facts>div{display:grid}.rt-facts strong{font-weight:900}.rt-facts span{line-height:1.2}.rt-marketing-canvas ul{margin:0;padding:0;list-style:none}.rt-marketing-canvas li{position:relative;padding-left:22px}.rt-marketing-canvas li:before{position:absolute;top:.58em;left:0;width:8px;height:2px;background:#d71932;content:""}
CSS;

        $specific = match ([$templateKey, $format]) {
            [self::JOB_WAGENMEISTER, MarketingCreativeFormat::Story] => <<<'CSS'
.rt-job-story{background:#f4f1ea}.rt-job-story>.rt-photo{position:absolute;top:0;right:0;left:0;height:880px;background:#102237}.rt-job-story>.rt-photo:after{position:absolute;inset:0;background:linear-gradient(180deg,rgba(7,22,37,.18),rgba(7,22,37,.08) 45%,rgba(7,22,37,.92));content:""}.rt-job-story>.rt-photo img{object-position:55% center}.rt-job-story .rt-photo-code{position:absolute;z-index:2;right:55px;bottom:44px;color:#fff;font-size:13px;font-weight:900;letter-spacing:.18em}.rt-job-story>header{position:absolute;z-index:3;top:0;right:0;left:0;height:880px;padding:54px 60px;color:#fff}.rt-job-story .rt-brand-lockup{width:270px}.rt-job-story header .rt-kicker{margin-top:325px}.rt-job-story h1{max-width:850px;margin-top:17px;font-size:96px;line-height:.88;letter-spacing:-.055em}.rt-job-story .rt-subtitle{max-width:730px;margin-top:22px;font-size:31px;line-height:1.18}.rt-job-story .rt-job-body{position:absolute;top:880px;right:0;left:0;height:710px;padding:52px 60px}.rt-job-story .rt-intro{max-width:870px;font-size:24px}.rt-job-story .rt-facts{margin-top:39px;padding:25px 0;border-top:1px solid #bdc8d0;border-bottom:1px solid #bdc8d0}.rt-job-story .rt-facts>div{flex:1}.rt-job-story .rt-facts strong{font-size:43px}.rt-job-story .rt-facts span{margin-top:7px;font-size:15px}.rt-job-story .rt-columns{display:grid;margin-top:42px;grid-template-columns:1fr 1fr;gap:54px}.rt-job-story .rt-columns article+article{padding-left:50px;border-left:1px solid #c8d0d6}.rt-job-story .rt-columns article>span{color:#d71932;font-size:13px;font-weight:900;letter-spacing:.14em}.rt-job-story .rt-columns ul{margin-top:17px}.rt-job-story .rt-columns li{margin-top:13px;font-size:18px;line-height:1.35}.rt-job-story>footer{position:absolute;right:0;bottom:0;left:0;height:330px;padding:48px 60px;background:#102237;color:#fff}.rt-job-story>footer>div strong{display:block;margin-top:10px;font-size:31px;line-height:1.12}.rt-job-story>footer small{color:#ef8290;font-weight:900;letter-spacing:.16em}.rt-job-story>footer .rt-cta{position:absolute;top:58px;right:60px}.rt-job-story>footer>p{position:absolute;right:60px;bottom:38px;left:60px;padding-top:17px;border-top:1px solid rgba(255,255,255,.22);font-size:15px}
CSS,
            [self::JOB_WAGENMEISTER, MarketingCreativeFormat::Post] => <<<'CSS'
.rt-job-post{background:#102237;color:#fff}.rt-job-post>.rt-photo{position:absolute;top:0;right:0;width:49%;height:895px}.rt-job-post>.rt-photo:after{position:absolute;inset:0;background:linear-gradient(90deg,#102237 0,transparent 30%),linear-gradient(0deg,#102237 0,transparent 24%);content:""}.rt-job-post>.rt-photo img{object-position:64% center}.rt-job-post .rt-photo-code{position:absolute;z-index:2;right:34px;bottom:30px;color:#fff;font-size:12px;font-weight:900;letter-spacing:.16em}.rt-job-post>.rt-copy{position:absolute;top:0;bottom:185px;left:0;width:62%;padding:46px 60px}.rt-job-post .rt-brand-lockup{width:245px}.rt-job-post .rt-kicker{margin-top:68px}.rt-job-post h1{margin-top:13px;font-size:72px;line-height:.9;letter-spacing:-.05em}.rt-job-post .rt-subtitle{max-width:570px;margin-top:20px;font-size:25px;line-height:1.18}.rt-job-post .rt-intro{max-width:560px;margin-top:22px;color:#cad4dc;font-size:18px}.rt-job-post ul{display:grid;margin-top:25px;grid-template-columns:1fr 1fr;gap:10px 23px}.rt-job-post li{font-size:15px}.rt-job-post .rt-cta{margin-top:31px}.rt-job-post>footer{position:absolute;right:0;bottom:0;left:0;display:flex;height:185px;padding:38px 55px;align-items:center;background:#f4f1ea;color:#102237}.rt-job-post footer .rt-facts{flex:1}.rt-job-post footer .rt-facts>div{flex:1}.rt-job-post footer .rt-facts strong{font-size:32px}.rt-job-post footer .rt-facts span{margin-top:5px;font-size:12px}.rt-job-post footer>strong{font-size:15px}
CSS,
            [self::JOB_WAGENMEISTER, MarketingCreativeFormat::Web] => <<<'CSS'
.rt-job-web{display:grid;background:#102237;color:#fff;grid-template-columns:56% 44%}.rt-job-web>.rt-copy{padding:38px 54px}.rt-job-web .rt-brand-lockup{width:220px}.rt-job-web .rt-kicker{margin-top:30px;font-size:13px}.rt-job-web h1{margin-top:9px;font-size:59px;line-height:.88;letter-spacing:-.052em}.rt-job-web .rt-subtitle{max-width:570px;margin-top:13px;font-size:22px}.rt-job-web .rt-intro{max-width:550px;margin-top:14px;color:#cbd5dd;font-size:15px}.rt-job-web .rt-actions{display:flex;margin-top:20px;align-items:center;gap:20px}.rt-job-web .rt-cta{min-height:49px;padding:0 21px;font-size:14px}.rt-job-web .rt-actions span{font-size:13px}.rt-job-web>.rt-photo{height:630px}.rt-job-web>.rt-photo:after{position:absolute;inset:0;background:linear-gradient(90deg,#102237 0,transparent 25%);content:""}.rt-job-web>.rt-photo img{object-position:59% center}.rt-job-web .rt-photo-code{position:absolute;right:25px;bottom:24px;color:#fff;font-size:11px;font-weight:900;letter-spacing:.14em}.rt-job-web>footer{position:absolute;right:22px;bottom:73px;width:430px;padding:19px 21px;background:rgba(16,34,55,.94)}.rt-job-web footer .rt-facts>div{flex:1}.rt-job-web footer .rt-facts strong{font-size:27px}.rt-job-web footer .rt-facts span{margin-top:5px;color:#fff;font-size:10px}
CSS,
            [self::INFO_WAGENMEISTER_ROLE, MarketingCreativeFormat::Story] => <<<'CSS'
.rt-role-story{background:#f4f1ea}.rt-role-story>header{height:610px;padding:52px 60px}.rt-role-story .rt-brand-lockup{width:260px}.rt-role-story .rt-kicker{margin-top:76px}.rt-role-story h1{max-width:900px;margin-top:15px;font-size:83px;line-height:.9;letter-spacing:-.055em}.rt-role-story .rt-subtitle{max-width:800px;margin-top:24px;font-size:29px}.rt-role-story>.rt-photo{height:500px;background:#102237}.rt-role-story>.rt-photo img{object-position:center 38%}.rt-role-story>.rt-photo:after{position:absolute;inset:0;background:linear-gradient(90deg,rgba(16,34,55,.25),transparent 55%),linear-gradient(0deg,rgba(16,34,55,.55),transparent 42%);content:""}.rt-role-story>.rt-photo span{position:absolute;right:46px;bottom:34px;color:#fff;font-size:13px;font-weight:900;letter-spacing:.17em}.rt-role-story .rt-role-body{height:610px;padding:45px 60px}.rt-role-story .rt-intro{font-size:21px}.rt-role-story .rt-facts{margin-top:31px;gap:18px}.rt-role-story .rt-facts>div{flex:1;padding:18px 21px;background:#102237;color:#fff}.rt-role-story .rt-facts strong{color:#d71932;font-size:31px}.rt-role-story .rt-facts span{margin-top:6px;font-size:14px}.rt-role-story .rt-role-body article{display:grid;margin-top:29px;padding-top:24px;border-top:1px solid #c4ccd2;grid-template-columns:240px 1fr}.rt-role-story .rt-role-body article>span{color:#d71932;font-size:13px;font-weight:900;letter-spacing:.13em}.rt-role-story .rt-role-body ul{display:grid;grid-template-columns:1fr 1fr;gap:9px 22px}.rt-role-story .rt-role-body li{font-size:16px;line-height:1.32}.rt-role-story>footer{position:absolute;right:0;bottom:0;left:0;display:flex;height:200px;padding:43px 60px;align-items:flex-start;background:#102237;color:#fff}.rt-role-story>footer p{margin-left:auto;padding-top:20px}.rt-role-story .rt-cta{min-width:290px}
CSS,
            [self::INFO_WAGENMEISTER_ROLE, MarketingCreativeFormat::Post] => <<<'CSS'
.rt-role-post{background:#f4f1ea}.rt-role-post>header{position:absolute;z-index:2;top:0;left:0;width:62%;height:650px;padding:45px 72px 45px 52px;background:#f4f1ea;clip-path:polygon(0 0,100% 0,86% 100%,0 100%)}.rt-role-post .rt-brand-lockup{width:230px}.rt-role-post .rt-kicker{margin-top:65px}.rt-role-post h1{margin-top:11px;font-size:64px;line-height:.9;letter-spacing:-.05em}.rt-role-post .rt-subtitle{margin-top:19px;font-size:24px;line-height:1.18}.rt-role-post>.rt-photo{position:absolute;top:0;right:0;width:53%;height:650px;background:#102237}.rt-role-post>.rt-photo img{object-position:center 32%}.rt-role-post .rt-role-body{position:absolute;right:0;bottom:100px;left:0;height:370px;padding:55px 54px 35px;background:#102237;color:#fff}.rt-role-post .rt-intro{max-width:880px;color:#e2e8ed;font-size:18px}.rt-role-post .rt-facts{display:grid;margin-top:25px;grid-template-columns:repeat(3,minmax(0,1fr));gap:34px}.rt-role-post .rt-facts strong{color:#d71932;font-size:34px}.rt-role-post .rt-facts span{margin-top:5px;font-size:14px}.rt-role-post .rt-cta{position:absolute;right:54px;bottom:34px}.rt-role-post>footer{position:absolute;right:0;bottom:0;left:0;display:flex;height:100px;padding:0 54px;align-items:center;justify-content:space-between}.rt-role-post>footer span{font-size:12px;font-weight:900;letter-spacing:.16em}.rt-role-post>footer strong{font-size:15px}
CSS,
            [self::INFO_WAGENMEISTER_ROLE, MarketingCreativeFormat::Web] => <<<'CSS'
.rt-role-web{display:grid;background:#f4f1ea;grid-template-columns:42% 58%}.rt-role-web>.rt-photo{height:630px;background:#102237}.rt-role-web>.rt-photo:after{position:absolute;inset:0;background:linear-gradient(0deg,rgba(16,34,55,.62),transparent 48%);content:""}.rt-role-web>.rt-photo img{object-position:center 30%}.rt-role-web>.rt-photo span{position:absolute;bottom:28px;left:30px;color:#fff;font-size:11px;font-weight:900;letter-spacing:.16em}.rt-role-web .rt-role-body{padding:37px 48px}.rt-role-web .rt-brand-lockup{width:210px}.rt-role-web .rt-kicker{margin-top:24px;font-size:12px}.rt-role-web h1{max-width:640px;margin-top:8px;font-size:49px;line-height:.91;letter-spacing:-.05em}.rt-role-web .rt-subtitle{margin-top:13px;font-size:20px}.rt-role-web .rt-intro{margin-top:14px;font-size:14px}.rt-role-web .rt-facts{margin-top:19px;padding-top:15px;border-top:1px solid #c4ccd2}.rt-role-web .rt-facts>div{flex:1}.rt-role-web .rt-facts strong{color:#d71932;font-size:25px}.rt-role-web .rt-facts span{margin-top:3px;font-size:11px}.rt-role-web .rt-cta{margin-top:19px;min-height:47px;padding:0 20px;font-size:13px}
CSS,
            [self::INFO_GERMANY_NETWORK, MarketingCreativeFormat::Story] => <<<'CSS'
.rt-network-story{background:#f4f1ea}.rt-network-story>header{height:570px;padding:52px 60px}.rt-network-story .rt-brand-lockup{width:255px}.rt-network-story .rt-kicker{margin-top:65px}.rt-network-story h1{max-width:900px;margin-top:14px;font-size:88px;line-height:.89;letter-spacing:-.057em}.rt-network-story .rt-subtitle{max-width:800px;margin-top:23px;font-size:28px}.rt-network-story>.rt-map{position:absolute;top:520px;right:0;width:720px;height:930px}.rt-network-story>.rt-map img{object-fit:contain}.rt-network-story>.rt-map span{position:absolute;right:48px;bottom:68px;color:#53616d;font-size:13px;font-weight:900;letter-spacing:.17em}.rt-network-story .rt-network-body{position:absolute;top:620px;left:0;width:490px;padding:0 0 0 60px}.rt-network-story .rt-intro{font-size:20px}.rt-network-story .rt-facts{display:grid;margin-top:42px;gap:25px}.rt-network-story .rt-facts>div{padding-bottom:19px;border-bottom:1px solid #c5cdd3}.rt-network-story .rt-facts strong{color:#d71932;font-size:47px}.rt-network-story .rt-facts span{margin-top:5px;font-size:15px}.rt-network-story ul{margin-top:35px}.rt-network-story li{margin-top:14px;font-size:17px}.rt-network-story>footer{position:absolute;right:0;bottom:0;left:0;height:310px;padding:52px 60px;background:#102237;color:#fff}.rt-network-story>footer .rt-cta{min-width:270px}.rt-network-story>footer>div{position:absolute;top:52px;right:60px;display:grid;text-align:right;gap:6px}.rt-network-story>footer>div strong{font-size:25px}.rt-network-story>footer>p{position:absolute;right:60px;bottom:40px;left:60px;padding-top:16px;border-top:1px solid rgba(255,255,255,.22)}
CSS,
            [self::INFO_GERMANY_NETWORK, MarketingCreativeFormat::Post] => <<<'CSS'
.rt-network-post{background:#102237;color:#fff}.rt-network-post>header{position:absolute;z-index:2;top:0;right:0;left:0;height:390px;padding:45px 52px}.rt-network-post .rt-brand-lockup{width:235px}.rt-network-post .rt-kicker{margin-top:45px}.rt-network-post h1{margin-top:11px;font-size:66px;line-height:.9;letter-spacing:-.05em}.rt-network-post .rt-subtitle{max-width:690px;margin-top:17px;font-size:23px}.rt-network-post>.rt-map{position:absolute;top:290px;right:0;width:650px;height:665px}.rt-network-post>.rt-map img{object-fit:contain}.rt-network-post .rt-network-body{position:absolute;z-index:2;bottom:100px;left:0;width:470px;padding:0 0 40px 52px}.rt-network-post .rt-facts{display:grid;gap:18px}.rt-network-post .rt-facts>div{padding-bottom:15px;border-bottom:1px solid rgba(255,255,255,.22)}.rt-network-post .rt-facts strong{color:#ef5267;font-size:42px}.rt-network-post .rt-facts span{margin-top:4px;font-size:14px}.rt-network-post .rt-intro{margin-top:25px;color:#dce4ea;font-size:17px}.rt-network-post .rt-cta{margin-top:25px}.rt-network-post>footer{position:absolute;right:0;bottom:0;left:0;display:flex;height:100px;padding:0 52px;align-items:center;justify-content:space-between;background:#f4f1ea;color:#102237}
CSS,
            [self::INFO_GERMANY_NETWORK, MarketingCreativeFormat::Web] => <<<'CSS'
.rt-network-web{display:grid;background:#102237;color:#fff;grid-template-columns:58% 42%}.rt-network-web .rt-network-body{padding:38px 52px}.rt-network-web .rt-brand-lockup{width:215px}.rt-network-web .rt-kicker{margin-top:26px;font-size:13px}.rt-network-web h1{max-width:650px;margin-top:9px;font-size:57px;line-height:.89;letter-spacing:-.053em}.rt-network-web .rt-subtitle{max-width:600px;margin-top:13px;font-size:20px}.rt-network-web .rt-intro{max-width:610px;margin-top:13px;color:#d0dae1;font-size:14px}.rt-network-web .rt-facts{margin-top:18px}.rt-network-web .rt-facts>div{flex:1}.rt-network-web .rt-facts strong{color:#ef5267;font-size:29px}.rt-network-web .rt-facts span{margin-top:4px;font-size:11px}.rt-network-web .rt-actions{display:flex;margin-top:19px;align-items:center;gap:18px}.rt-network-web .rt-cta{min-height:47px;padding:0 21px;font-size:13px}.rt-network-web .rt-actions span{font-size:14px;font-weight:800}.rt-network-web>.rt-map{height:630px;background:#f4f1ea}.rt-network-web>.rt-map img{object-fit:contain}.rt-network-web>.rt-map span{position:absolute;right:24px;bottom:21px;color:#53616d;font-size:10px;font-weight:900;letter-spacing:.15em}
CSS,
        };

        return $base.$specific;
    }
}
