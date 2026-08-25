<?php

namespace App\Services\Marketing;

use App\Enums\MarketingCreativeFormat;
use App\Support\CompanyData;
use InvalidArgumentException;

final class MarketingCareerTemplateCatalog
{
    public function __construct(private readonly MarketingPremiumTemplateCatalog $premium) {}

    /** @return list<string> */
    public function templateKeys(): array
    {
        return [
            MarketingTemplateFactory::CAREER_JOB_WAGENMEISTER,
            MarketingTemplateFactory::CAREER_JOB_TRIEBFAHRZEUGFUEHRER,
            MarketingTemplateFactory::CAREER_JOB_ARBEITSZUGFUEHRER,
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
            throw new InvalidArgumentException('Unbekannte RailTime-Karrierevorlage: '.$templateKey);
        }

        $role = $this->role($templateKey);
        $content = $this->content($templateKey, $role, CompanyData::all());
        $premiumJob = $this->premium->definitionByKey(MarketingTemplateFactory::PREMIUM_JOB_WAGENMEISTER);
        $variants = [];

        foreach (MarketingCreativeFormat::cases() as $format) {
            if ($format === MarketingCreativeFormat::Story) {
                $html = $this->storyHtml($role);
                $css = $this->storyCss();
            } else {
                $source = $premiumJob['variants'][$format->value];
                $html = str_replace(
                    [
                        'JOB / 001',
                        'RailTime-Wagenmeister im Einsatz zwischen Güterwagen',
                    ],
                    [
                        'JOB / '.$role['code'],
                        'RailTime-Team im Einsatz zwischen Güterwagen',
                    ],
                    $source['html'],
                );
                $css = $source['css'].$this->eightBenefitLayoutCss($format);
            }

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
                        'schema' => MarketingTemplateFactory::CAREER_SEED_VERSION,
                    ],
                ],
                'html' => $html,
                'css' => $css,
            ];
        }

        return [
            'title' => $role['creative_title'],
            'shared_content' => $content,
            'variants' => $variants,
        ];
    }

    /**
     * @return array{
     *     code:string,
     *     creative_title:string,
     *     job_title:string,
     *     intro:string,
     *     tasks:list<string>,
     *     profile:list<string>
     * }
     */
    private function role(string $templateKey): array
    {
        return match ($templateKey) {
            MarketingTemplateFactory::CAREER_JOB_WAGENMEISTER => [
                'code' => 'WAGENMEISTER',
                'creative_title' => 'Wagenmeister (m/w/d) – Sicherheit beginnt mit deinem Blick',
                'job_title' => 'Wagenmeister (m/w/d)',
                'intro' => 'Du prüfst Güterwagen, dokumentierst präzise und hältst gemeinsam mit deinem Team den Bahnbetrieb sicher in Bewegung.',
                'tasks' => [
                    'Güterwagen und Züge technisch untersuchen',
                    'Bremsproben durchführen und Schäden dokumentieren',
                    'Mit Disposition, Betrieb und Werkstätten abstimmen',
                ],
                'profile' => [
                    'Qualifikation als Wagenmeister',
                    'Sicherheitsbewusste und zuverlässige Arbeitsweise',
                    'Flexibilität bei Einsatzzeiten und Einsatzorten',
                    'Teamgeist und klare Kommunikation',
                ],
            ],
            MarketingTemplateFactory::CAREER_JOB_TRIEBFAHRZEUGFUEHRER => [
                'code' => 'TRIEBFAHRZEUGFÜHRER',
                'creative_title' => 'Triebfahrzeugführer (m/w/d) – Gemeinsam sicher in Bewegung',
                'job_title' => 'Triebfahrzeugführer (m/w/d)',
                'intro' => 'Du führst Triebfahrzeuge sicher und behältst Zug, Strecke und Fahrplan jederzeit zuverlässig im Blick.',
                'tasks' => [
                    'Triebfahrzeuge im Güter- und Arbeitszugverkehr führen',
                    'Fahrten vorbereiten und betriebliche Unterlagen prüfen',
                    'Unregelmäßigkeiten erkennen und sicher kommunizieren',
                ],
                'profile' => [
                    'Gültiger Triebfahrzeugführerschein',
                    'Sicherheitsbewusste und konzentrierte Arbeitsweise',
                    'Flexibilität bei Einsatzzeiten und Einsatzorten',
                    'Teamgeist und klare Kommunikation',
                ],
            ],
            MarketingTemplateFactory::CAREER_JOB_ARBEITSZUGFUEHRER => [
                'code' => 'ARBEITSZUGFÜHRER',
                'creative_title' => 'Arbeitszugführer (m/w/d) – Sicherheit auf jeder Baustelle',
                'job_title' => 'Arbeitszugführer (m/w/d)',
                'intro' => 'Du führst Arbeitszüge sicher und koordinierst Baustelle, Betrieb und Beteiligte zuverlässig im Einsatz.',
                'tasks' => [
                    'Arbeitszüge vorbereiten und im Baubetrieb führen',
                    'Betriebliche Abläufe auf der Baustelle koordinieren',
                    'Sicherheitsrelevante Vorgänge dokumentieren und abstimmen',
                ],
                'profile' => [
                    'Qualifikation für das Führen von Arbeitszügen',
                    'Sicherheitsbewusste und strukturierte Arbeitsweise',
                    'Flexibilität bei Einsatzzeiten und Einsatzorten',
                    'Teamgeist und klare Kommunikation',
                ],
            ],
        };
    }

    /**
     * @param  array{job_title:string,intro:string,tasks:list<string>,profile:list<string>}  $role
     * @param  array<string,string>  $company
     * @return array<string,mixed>
     */
    private function content(string $templateKey, array $role, array $company): array
    {
        return [
            'contact_phone' => $company['phone'],
            'contact_email' => $company['email'],
            'website' => preg_replace('#^https?://#i', '', rtrim($company['website'], '/')),
            'company_name' => $company['name'],
            'company_address' => CompanyData::addressLine($company),
            'template_key' => $templateKey,
            'seed_version' => MarketingTemplateFactory::CAREER_SEED_VERSION,
            'preferred_preview_format' => MarketingCreativeFormat::Story->value,
            'kicker' => 'Wir erweitern unser Team.',
            'title' => 'Gemeinsam bringen wir Sicherheit auf die Schiene.',
            'subtitle' => $role['job_title'],
            'intro' => $role['intro'],
            'facts' => [
                ['value' => 'DE', 'label' => 'deutschlandweit im Einsatz'],
                ['value' => 'VZ', 'label' => 'Vollzeit'],
                ['value' => '∞', 'label' => 'unbefristete Perspektive'],
            ],
            'tasks' => $role['tasks'],
            'profile' => $role['profile'],
            'benefits' => [
                'Unbefristeter Vertrag',
                'Betriebliche Altersvorsorge (bAV)',
                'Überdurchschnittliche Bezahlung',
                'Attraktive Zuschläge',
                'Corporate Benefits',
                'Wellpass',
                'Moderne Ausstattung & Weiterbildung',
                'Starkes Team & direkte Wege',
            ],
            'cta_label' => 'Jetzt bewerben',
            'cta_url' => 'https://www.rail-time.de/de/karriere',
            'editorial_note' => 'Aufgaben, Anforderungen und Leistungen sind vor Veröffentlichung mit der aktuellen Stellenausschreibung abzugleichen.',
        ];
    }

    /** @param array{code:string} $role */
    private function storyHtml(array $role): string
    {
        $code = $role['code'];
        $track = $this->storyTrackHtml();

        return <<<HTML
<main class="rt-marketing-canvas rt-career-story">
  <figure class="rt-career-hero"><img src="/rt-brand/img/wagenmeister-team-gleis.jpeg" alt="RailTime-Team im Einsatz zwischen Güterwagen"></figure>
  <header class="rt-career-mast"><div class="rt-brand rt-brand-lockup rt-brand-lockup-reverse" data-rt-brand-lockup="official"><img class="rt-brand-logo" src="/rt-brand/img/logo-horizontal-darkbg.png" alt="RT Rail Time GmbH"></div><span class="rt-career-code">KARRIERE / {$code}</span></header>
  <section class="rt-career-copy"><p class="rt-kicker" data-rt-binding="kicker"></p><h1 data-rt-binding="title"></h1><p class="rt-role-title" data-rt-binding="subtitle"></p></section>
  <section class="rt-career-details"><p class="rt-intro" data-rt-binding="intro"></p><div class="rt-detail-grid"><article><header><img src="/rt-brand/icons/job-tasks.svg" alt=""><span><small>01 / Dein Einsatz</small><strong>Deine Aufgaben</strong></span></header><ul data-rt-binding-list="tasks"></ul></article><article><header><img src="/rt-brand/icons/job-profile.svg" alt=""><span><small>02 / Was zählt</small><strong>Deine Anforderungen</strong></span></header><ul data-rt-binding-list="profile"></ul></article></div></section>
  <section class="rt-benefit-map"><header><span>03 / Dein Plus bei RailTime</span><h2>Deine Benefits.</h2><p>Mehr Sicherheit. Mehr Perspektive. Mehr für dich.</p></header>{$track}<div class="rt-benefit-route-copy"><span>RT / BENEFIT-GLEIS 08</span><strong>Acht Stationen.<br>Ein starkes Paket.</strong></div><ol class="rt-benefit-list" data-rt-binding-list="benefits"></ol></section>
  <footer class="rt-career-footer"><a class="rt-cta" data-rt-binding="cta_label" data-rt-binding-href="cta_url"></a><div><strong>rail-time.de/karriere</strong><span>Deutschlandweit im Einsatz</span></div></footer>
</main>
HTML;
    }

    private function storyTrackHtml(): string
    {
        return <<<'HTML'
<img class="rt-track" src="/rt-brand/icons/benefit-track-u.svg" alt="" aria-hidden="true">
HTML;
    }

    private function storyCss(): string
    {
        $base = <<<'CSS'
@font-face{font-family:Manrope;src:url("/rt-brand/fonts/manrope-latin.woff2") format("woff2");font-style:normal;font-weight:400 800;font-display:swap}@font-face{font-family:"Space Mono";src:url("/rt-brand/fonts/space-mono-700-latin.woff2") format("woff2");font-style:normal;font-weight:700;font-display:swap}*{box-sizing:border-box}.rt-marketing-canvas{position:relative;overflow:hidden;width:1080px;height:1920px;margin:0;background:#080d13;color:#fff;font-family:Manrope,Arial,sans-serif}.rt-career-story:before{position:absolute;z-index:1;inset:0;background-image:linear-gradient(rgba(255,255,255,.025) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.025) 1px,transparent 1px);background-size:54px 54px;content:"";pointer-events:none}.rt-career-story h1,.rt-career-story h2,.rt-career-story p,.rt-career-story figure{margin:0}.rt-career-hero{position:absolute;z-index:0;inset:0 0 auto;height:610px;background:#080d13}.rt-career-hero img{display:block;width:100%;height:100%;object-fit:cover;object-position:center 38%;filter:saturate(.82) contrast(1.08)}.rt-career-hero:after{position:absolute;inset:0;background:linear-gradient(180deg,rgba(7,12,18,.24),rgba(7,12,18,.3) 42%,#080d13 98%),linear-gradient(90deg,rgba(7,12,18,.94) 0,rgba(7,12,18,.8) 44%,rgba(7,12,18,.38) 76%,rgba(7,12,18,.22));content:""}.rt-career-mast{position:absolute;z-index:4;top:58px;right:58px;left:58px;display:flex;align-items:center;justify-content:space-between}.rt-brand-lockup{width:250px}.rt-brand-logo{display:block;width:100%;height:auto}.rt-career-code,.rt-kicker,.rt-detail-grid small,.rt-benefit-map>header>span,.rt-career-footer span{font-family:"Space Mono",monospace;font-weight:700;letter-spacing:.12em;text-transform:uppercase}.rt-career-code{font-size:13px}.rt-career-copy{position:absolute;z-index:3;top:180px;right:58px;left:58px}.rt-kicker{color:#ff214c;font-size:17px}.rt-career-copy h1{max-width:850px;margin-top:18px;font-size:76px;font-weight:800;letter-spacing:-.06em;line-height:.92}.rt-role-title{max-width:840px;margin-top:24px;color:#ff214c;font-size:39px;font-weight:800;letter-spacing:-.035em;line-height:1.05}.rt-career-details{position:absolute;z-index:3;top:565px;right:58px;left:58px;padding:30px 32px 28px;border-top:4px solid #e4002b;background:linear-gradient(145deg,rgba(18,27,38,.98),rgba(8,13,19,.98));box-shadow:0 24px 60px rgba(0,0,0,.26)}.rt-career-details>.rt-intro{max-width:930px;color:#e5e9ed;font-size:20px;font-weight:600;line-height:1.38}.rt-detail-grid{display:grid;margin-top:24px;grid-template-columns:repeat(2,minmax(0,1fr));gap:20px}.rt-detail-grid article{min-width:0;padding:20px 22px;border:1px solid rgba(255,255,255,.13);background:linear-gradient(145deg,rgba(22,31,42,.97),rgba(10,15,22,.97))}.rt-detail-grid article header{display:flex;align-items:center;gap:14px}.rt-detail-grid article header>img{display:block;width:46px;height:46px;padding:10px;background:#e4002b;object-fit:contain}.rt-detail-grid article header span{display:grid}.rt-detail-grid small{color:#ff3158;font-size:9px}.rt-detail-grid strong{margin-top:3px;font-size:18px}.rt-detail-grid ul{display:grid;margin:14px 0 0;padding:0;list-style:none;gap:8px}.rt-detail-grid li{position:relative;padding-left:19px;color:#dce2e7;font-size:14px;font-weight:600;line-height:1.28}.rt-detail-grid li:before{position:absolute;top:.62em;left:0;width:8px;height:3px;border-right:2px solid #ff214c;border-bottom:2px solid #ff214c;content:"";transform:rotate(45deg)}.rt-benefit-map{position:absolute;z-index:2;top:1042px;right:42px;left:42px;height:675px;padding:27px 16px 18px;border:1px solid rgba(255,255,255,.1);background:radial-gradient(80% 60% at 52% 53%,rgba(228,0,43,.15),transparent 70%),linear-gradient(145deg,rgba(14,22,31,.98),rgba(6,10,15,.99))}.rt-benefit-map>header{position:absolute;z-index:4;top:28px;left:26px}.rt-benefit-map>header>span{color:#ff3158;font-size:10px}.rt-benefit-map h2{margin-top:3px;font-size:34px;font-weight:800;letter-spacing:-.04em}.rt-benefit-map>header p{margin-top:2px;color:#aeb8c2;font-size:13px}.rt-track{position:absolute;z-index:1;top:253px;right:52px;left:52px;height:286px}.rt-track-top,.rt-track-bottom{position:absolute;right:132px;left:0;height:27px;background:repeating-linear-gradient(90deg,#303b46 0 7px,transparent 7px 31px),linear-gradient(180deg,transparent 0 4px,#8b98a5 4px 8px,transparent 8px 19px,#8b98a5 19px 23px,transparent 23px)}.rt-track-top{top:0}.rt-track-bottom{bottom:0}.rt-track-curve{position:absolute;top:4px;right:0;width:158px;height:278px;border-top:7px double #8b98a5;border-right:7px double #8b98a5;border-bottom:7px double #8b98a5;border-radius:0 142px 142px 0}.rt-track-curve:before{position:absolute;top:10px;right:10px;bottom:10px;left:0;border-top:4px dashed #3b4651;border-right:4px dashed #3b4651;border-bottom:4px dashed #3b4651;border-radius:0 126px 126px 0;content:""}.rt-benefit-list{position:absolute;z-index:3;top:126px;right:14px;left:14px;display:grid;height:536px;margin:0;padding:0;counter-reset:benefit;grid-template-columns:repeat(4,minmax(0,1fr));grid-template-rows:118px 118px;gap:300px 12px;list-style:none}.rt-benefit-list li{position:relative;display:flex;min-width:0;padding:15px 14px 14px 52px;align-items:center;border:1px solid rgba(255,255,255,.14);border-top:3px solid #e4002b;background:linear-gradient(145deg,rgba(22,31,42,.99),rgba(8,13,19,.99));font-size:16px;font-weight:750;letter-spacing:-.02em;line-height:1.15;counter-increment:benefit}.rt-benefit-list li:after{position:absolute;top:16px;left:13px;display:grid;width:31px;height:31px;place-items:center;border:1px solid rgba(255,49,88,.58);background:rgba(228,0,43,.15) url("/rt-brand/icons/job-benefits.svg") center/19px 19px no-repeat;content:""}.rt-benefit-list li:before{position:absolute;z-index:5;right:calc(50% - 27px);bottom:-50px;display:grid;width:54px;height:54px;place-items:center;border:7px solid #e4002b;border-radius:50%;background:#0b1118;box-shadow:0 0 0 5px rgba(228,0,43,.22);color:#fff;font-family:"Space Mono",monospace;font-size:11px;content:counter(benefit,decimal-leading-zero)}.rt-benefit-list li:nth-child(n+5):before{top:-47px;bottom:auto}.rt-career-footer{position:absolute;z-index:4;right:0;bottom:0;left:0;display:flex;height:168px;padding:39px 58px;align-items:center;border-top:4px solid #e4002b;background:#05090d}.rt-cta{display:inline-flex;min-height:58px;padding:0 25px;align-items:center;justify-content:center;border-radius:30px;background:#e4002b;color:#fff;font-size:15px;font-weight:800;text-decoration:none}.rt-cta:after{margin-left:20px;content:"→"}.rt-career-footer>div{display:grid;margin-left:auto;text-align:right;gap:7px}.rt-career-footer strong{font-size:24px}.rt-career-footer span{color:#aeb8c2;font-size:10px}
CSS;

        return $base.$this->refinedStoryTrackCss();
    }

    private function refinedStoryTrackCss(): string
    {
        return <<<'CSS'
.rt-benefit-map{top:1025px;height:700px;overflow:hidden;border-color:rgba(255,255,255,.13);border-radius:20px;background:radial-gradient(70% 58% at 51% 53%,rgba(228,0,43,.18),transparent 72%),linear-gradient(145deg,rgba(15,24,34,.99),rgba(6,10,15,.995));box-shadow:inset 0 1px 0 rgba(255,255,255,.035),0 26px 70px rgba(0,0,0,.24)}
.rt-benefit-map:after{position:absolute;z-index:0;right:-110px;bottom:-150px;width:480px;height:480px;border:1px solid rgba(255,255,255,.035);border-radius:50%;content:""}
.rt-benefit-map>header p{max-width:520px;margin-top:5px;color:#b7c1ca;font-size:14px;font-weight:600}
.rt-benefit-route-copy{position:absolute;z-index:2;top:341px;left:70px;display:grid;gap:10px}
.rt-benefit-route-copy span{color:#ff3158;font-family:"Space Mono",monospace;font-size:9px;font-weight:700;letter-spacing:.13em;text-transform:uppercase}
.rt-benefit-route-copy strong{max-width:310px;color:#f5f7f8;font-size:27px;font-weight:800;letter-spacing:-.035em;line-height:1.02}
.rt-track{right:auto;display:block;width:calc(100% - 104px);object-fit:fill;filter:drop-shadow(0 14px 24px rgba(0,0,0,.32))}
.rt-benefit-list li{padding:16px 15px 15px 59px;border-top:0;border-radius:16px;background:linear-gradient(90deg,#ff3158 0 54px,transparent 54px) left top/100% 3px no-repeat,linear-gradient(145deg,rgba(21,31,42,.995),rgba(9,14,21,.995));box-shadow:inset 0 1px 0 rgba(255,255,255,.035),0 13px 28px rgba(0,0,0,.18);font-size:16.5px;line-height:1.13}
.rt-benefit-list li:after{top:17px;left:14px;width:38px;height:38px;border-radius:10px;background-size:21px 21px}
.rt-benefit-list li:before{right:calc(50% - 27px);border-width:5px;background:#0a1118;box-shadow:0 0 0 5px rgba(228,0,43,.2),inset 0 0 0 2px #d7dfe5}
.rt-benefit-list li:nth-child(5){grid-column:4}
.rt-benefit-list li:nth-child(6){grid-column:3}
.rt-benefit-list li:nth-child(7){grid-column:2}
.rt-benefit-list li:nth-child(8){grid-column:1}
.rt-benefit-list li:nth-child(n+5){grid-row:2}
.rt-benefit-list li:nth-child(1):after,.rt-benefit-list li:nth-child(7):after{background-image:url("/rt-brand/icons/job-profile.svg")}
.rt-benefit-list li:nth-child(4):after,.rt-benefit-list li:nth-child(8):after{background-image:url("/rt-brand/icons/job-tasks.svg")}
CSS;
    }

    private function eightBenefitLayoutCss(MarketingCreativeFormat $format): string
    {
        return match ($format) {
            MarketingCreativeFormat::Post => <<<'CSS'
.rt-job-premium-post .rt-copy{top:142px}.rt-job-premium-post h1{font-size:56px;line-height:.9}.rt-job-premium-post .rt-subtitle{margin-top:12px;font-size:20px}.rt-job-premium-post .rt-intro{margin-top:11px;font-size:13px;line-height:1.34}.rt-job-premium-post>.rt-job-details{top:444px;bottom:253px;grid-template-columns:repeat(2,minmax(0,1fr));grid-template-rows:184px 189px;gap:10px 12px}.rt-job-premium-post .rt-job-details .rt-job-card{padding:12px 14px}.rt-job-premium-post .rt-job-card__head{gap:10px}.rt-job-premium-post .rt-job-card__icon{width:35px;height:35px}.rt-job-premium-post .rt-job-card__heading strong{font-size:12px}.rt-job-premium-post .rt-job-card__list{margin-top:7px}.rt-job-premium-post .rt-job-card__list li{min-height:27px;padding:4px 0 4px 18px;font-size:10.6px}.rt-job-premium-post .rt-job-card--benefits{display:grid;grid-column:1/-1;grid-template-columns:168px minmax(0,1fr)}.rt-job-premium-post .rt-job-card--benefits .rt-job-card__head{height:100%;padding-right:13px;border-right:1px solid rgba(255,255,255,.1)}.rt-job-premium-post .rt-job-card--benefits .rt-job-card__list{display:grid;margin:0 0 0 13px;grid-template-columns:repeat(4,minmax(0,1fr));grid-template-rows:repeat(2,minmax(0,1fr));gap:0 10px}.rt-job-premium-post .rt-job-card--benefits .rt-job-card__list li{min-height:0;padding:5px 3px 5px 17px;font-size:10.2px;line-height:1.16}
CSS,
            MarketingCreativeFormat::Web => <<<'CSS'
.rt-job-premium-web h1{font-size:38px;line-height:.9}.rt-job-premium-web .rt-subtitle{font-size:14px}.rt-job-premium-web .rt-intro{font-size:10px}.rt-job-premium-web .rt-job-card--benefits{grid-template-columns:132px minmax(0,1fr)}.rt-job-premium-web .rt-job-card--benefits .rt-job-card__list{grid-template-columns:repeat(4,minmax(0,1fr));grid-template-rows:repeat(2,minmax(0,1fr));gap:0 7px}.rt-job-premium-web .rt-job-card--benefits .rt-job-card__list li{min-height:0;padding:3px 1px 3px 14px;font-size:8.2px;line-height:1.12}
CSS,
            MarketingCreativeFormat::Story => '',
        };
    }
}
