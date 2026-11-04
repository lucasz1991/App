<?php

declare(strict_types=1);

namespace App\Support\OutlookAddin;

use Illuminate\Contracts\Support\Htmlable;
use RuntimeException;

/** Unpublished U2e projection. Its caller admits publication, identity and author HTML. */
final class OutlookUniversalPresentation
{
    public const REVISION = 'outlook-universal-u2e-v1';

    public const DESIGN_SOURCE = 'c7efab2c64890dc57386448be880122e1e284c6c35a2392fcad5ed5094f94b6b';

    // C-K13's separator plus the exact C-K15 fallback/exact-height additions.
    // DESIGN_SOURCE still identifies the unchanged original U2e reference.
    private const TEMPLATE_SHA256 = '2ad0c5a96b3b03a0e4a633ec87fa4aa79eba30f4d3b4a979937a5f5c7871dd12';

    private const ASSETS = [
        'logo' => ['/m/b983af0049.png', 'b983af0049926c128807d605a51a68ce90fea06c5c340f7728b105a96d7f021a', 400, 68],
        'email' => ['/m/9dfa25b253.png', '9dfa25b253b065c73173b884939db2a032453c44eb2ba6e9e65434a95a072675', 44, 44],
        'mobile' => ['/m/d92bd192b6.png', 'd92bd192b664c536fe29b1c927023bca44041de90bc9bfe34e6df03be5006d32', 44, 44],
        'web' => ['/m/1593d63cca.png', '1593d63ccacf8c884b71870fe1478f218ea0f541e918d84115978bc2b75c64ff', 44, 44],
        'location' => ['/m/a38bc7d27c.png', 'a38bc7d27c9c8c04c2a20815ad9576c76aebfe0bd651f9c5e90909b83bdf6925', 44, 44],
        'cornerTopLeft' => ['/m/8a9ee1e161.png', '8a9ee1e1614f8e48fc374fb99ccd088ea33b4f3f156a63ed5294d23ab840e50d', 16, 16],
        'cornerTopRight' => ['/m/4b1a2aaba8.png', '4b1a2aaba8a4720445c7b80d1599765da5b728776eb876ea2e31facd8866db1c', 16, 16],
        'dispatchMail' => ['/m/19125261c2.png', '19125261c2bc8274933b7a1c9efd1c5db23cd751c65fc1f88d2dcae22e4481b1', 72, 72],
        'whatsapp' => ['/m/f9d88b899d.png', 'f9d88b899d574851c64d02c9835a5b5fcf8d7c68e05d6c9b3f5618d3ac38bbd9', 72, 72],
        'dispatchPhone' => ['/m/1111670ac8.png', '1111670ac86e12aff6ad7809e11959b7af3c1275d4c5f2832b60934992f8f19a', 72, 72],
        'cornerBottomLeft' => ['/m/b4b1fbea07.png', 'b4b1fbea07044c782344b98404fc91c41b5a9e844ef5535f150d7649d62f3160', 16, 16],
        'cornerBottomRight' => ['/m/f1b083c20b.png', 'f1b083c20b84f813007ca8d1b08c01ef77e08a819f12dc701a1ed94a2d7fd3fc', 16, 16],
        'train' => ['/m/14d7943c88.png', '14d7943c888b83659542baaa26172dde70e51df8fc893a4c0cc48b14e47f6351', 1139, 190],
    ];

    public function __construct(private readonly ?string $baseUrl = null) {}

    /**
     * @param  array{sourceHash:string,kind:'employee'|'system',identity:array{name:string,role:string},direct:list<array{type:'email'|'phone',value:string,label:string}>,company:array{name:string,website:string,websiteLabel:string,street:string,postalCity:string,registration:string,confidentiality:string},dispatch:array{email:string,whatsapp:string,phone:string,phoneLabel:string}}  $descriptor
     * @param  array{mode?:'editor'|'send',showGreeting?:bool,greeting?:string,showFarewell?:bool,farewell?:string,farewellName?:bool}  $options
     * @return array{html:string,bodyHtml:string,signatureHtml:string,headCss:string,headCssHash:string,templateHash:string,marker:string,id:string,version:string,header:string,headers:array<string,string>,sourceHash:string,designSourceHash:string,authorContentHash:string,mode:string,utf16Length:int,media:list<array{name:string,url:string,sha256:string,mime:string,width:int,height:int}>}
     */
    public function render(array $descriptor, Htmlable $trustedAuthorContent, array $options = []): array
    {
        self::validate($descriptor, $options);
        $root = $this->root();
        $template = self::template();
        // Keep the pinned design file intact; give the ownership cell its own
        // identifier before any opaque author/quote bytes are substituted.
        $template = str_replace('<td class="rt-col rt-col-person" id="{{MARKER}}"',
            '<td class="rt-col rt-col-person" id="{{MARKER}}-cell"', $template, $cellMarkers);
        if ($cellMarkers !== 1) {
            self::fail();
        }
        $mode = $options['mode'] ?? 'editor';
        $wording = [
            'showGreeting' => $options['showGreeting'] ?? false,
            'greeting' => ($options['showGreeting'] ?? false) ? $options['greeting'] : '',
            'showFarewell' => $options['showFarewell'] ?? false,
            'farewell' => ($options['showFarewell'] ?? false) ? $options['farewell'] : '',
            'farewellName' => ($options['showFarewell'] ?? false) && ($options['farewellName'] ?? true),
        ];
        // Author text and delivery mode must not change signature ownership.
        $version = substr(hash('sha256', self::REVISION."\0".self::TEMPLATE_SHA256."\0".json_encode(
            self::canonical([$descriptor, $wording]), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        )), 0, 24);
        $marker = 'rt-sig-u2-'.$version;
        $author = $trustedAuthorContent->toHtml();
        if (! is_string($author) || strlen($author) > 262144 || ! mb_check_encoding($author, 'UTF-8')
            || str_contains($author, "\0")) {
            self::fail();
        }
        [$assets, $media] = $this->assets($root, $descriptor['direct']);
        $values = [
            'MARKER' => $marker,
            'DOCUMENT_TITLE' => $descriptor['company']['name'].' – Vorlage mit Signatur',
            'IDENTITY_NAME' => $descriptor['identity']['name'],
            'IDENTITY_ROLE' => $descriptor['identity']['role'],
            'COMPANY_NAME' => $descriptor['company']['name'],
            'COMPANY_WEBSITE' => $descriptor['company']['website'],
            'COMPANY_WEBSITE_LABEL' => $descriptor['company']['websiteLabel'],
            'COMPANY_MAP_URL' => 'https://maps.google.com/?q='.rawurlencode($descriptor['company']['street'].', '.$descriptor['company']['postalCity']),
            'COMPANY_STREET' => $descriptor['company']['street'],
            'COMPANY_POSTAL_CITY' => $descriptor['company']['postalCity'],
            'LEGAL_REGISTRATION' => $descriptor['company']['registration'],
            'LEGAL_CONFIDENTIALITY' => $descriptor['company']['confidentiality'],
            'DISPATCH_EMAIL' => $descriptor['dispatch']['email'],
            'DISPATCH_WHATSAPP' => $descriptor['dispatch']['whatsapp'],
            'DISPATCH_PHONE' => $descriptor['dispatch']['phone'],
        ];
        $tokens = [];
        foreach ($values as $name => $value) {
            $tokens['{{'.$name.'}}'] = self::escape($value);
        }
        foreach ($assets as $name => $url) {
            $tokens['{{ASSET_'.$name.'}}'] = self::escape($url);
        }
        $tokens['{{DISPATCH_PHONE_LABEL}}'] = str_replace(' ', '&nbsp;', self::escape($descriptor['dispatch']['phoneLabel']));
        $tokens['{{DIRECT_ROWS}}'] = self::directRows($descriptor['direct'], $assets, $mode);
        $tokens['{{GREETING_HTML}}'] = $wording['showGreeting'] ? self::greeting($wording['greeting']) : '';
        $tokens['{{FAREWELL_HTML}}'] = $wording['showFarewell'] ? self::farewell($wording['farewell'], $wording['farewellName'] ? $descriptor['identity']['name'] : null) : '';
        $tokens['{{AUTHOR_CONTENT}}'] = $author;

        $body = self::body($template);
        if ($mode === 'editor') {
            // Only the fixed template is transformed. Author HTML is substituted
            // afterwards, so user links and quoted markup remain opaque bytes.
            $body = self::withoutTextLinks($body);
        }
        $signature = self::withoutMessageRow($body);
        $css = self::headCss($template);
        $bodyHtml = strtr($body, $tokens);
        $html = $mode === 'send' ? strtr($template, $tokens) : $bodyHtml;
        $header = 'u2e-'.$version;

        return [
            'html' => $html, 'bodyHtml' => $bodyHtml, 'signatureHtml' => strtr($signature, $tokens),
            'headCss' => $mode === 'send' ? $css : '', 'headCssHash' => hash('sha256', $mode === 'send' ? $css : ''),
            'templateHash' => self::TEMPLATE_SHA256, 'marker' => $marker, 'id' => $marker, 'version' => $version,
            'header' => $header, 'headers' => ['X-RailTime-Template' => $header],
            'sourceHash' => $descriptor['sourceHash'], 'designSourceHash' => self::DESIGN_SOURCE,
            'authorContentHash' => hash('sha256', $author), 'mode' => $mode,
            'utf16Length' => intdiv(strlen(mb_convert_encoding($html, 'UTF-16LE', 'UTF-8')), 2), 'media' => $media,
        ];
    }

    private function root(): string
    {
        $approved = rtrim((string) config('app.url'), '/');
        $root = rtrim($this->baseUrl ?? $approved, '/');
        $parts = parse_url($root);
        if ($root !== $approved || ! filter_var($root, FILTER_VALIDATE_URL) || ! is_array($parts)
            || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment']) || ! empty($parts['path'])) {
            self::fail();
        }

        return $root;
    }

    /** @return array{array<string,string>,list<array{name:string,url:string,sha256:string,mime:string,width:int,height:int}>} */
    private function assets(string $root, array $contacts): array
    {
        $urls = $media = [];
        foreach (self::ASSETS as $name => [$path, $sha, $width, $height]) {
            $file = public_path(ltrim($path, '/'));
            $size = is_file($file) && is_readable($file) ? @getimagesize($file) : false;
            if (! is_array($size) || $size[0] !== $width || $size[1] !== $height || ($size['mime'] ?? null) !== 'image/png'
                || ! hash_equals($sha, (string) hash_file('sha256', $file))) {
                self::fail();
            }
            $urls[$name] = $root.$path;
            if ($name === 'mobile' && ! in_array('phone', array_column($contacts, 'type'), true)) {
                continue;
            }
            $media[] = ['name' => $name, 'url' => $root.$path, 'sha256' => $sha, 'mime' => 'image/png', 'width' => $width, 'height' => $height];
        }

        return [$urls, $media];
    }

    private static function directRows(array $contacts, array $assets, string $mode): string
    {
        $result = '';
        foreach ($contacts as $index => $contact) {
            $source = $assets[$contact['type'] === 'email' ? 'email' : 'mobile'];
            $label = '<span style="color:#142231;text-decoration:none;">'.self::escape($contact['label']).'</span>';
            if ($mode === 'send') {
                $href = ($contact['type'] === 'email' ? 'mailto:' : 'tel:').$contact['value'];
                $label = '<a href="'.self::escape($href).'" style="color:#142231;text-decoration:none;">'.$label.'</a>';
            }
            $padding = $index === array_key_last($contacts) ? '0' : '0 0 5px';
            $result .= '<tr><td width="26" valign="top" style="width:26px;padding:2px 0 0 0;vertical-align:top;"><img src="'.self::escape($source).'" width="15" height="15" alt="" border="0" style="display:block;border:0;outline:none;text-decoration:none;width:15px;height:15px;max-width:100%;"></td>'
                .'<td valign="top" style="padding:'.$padding.';vertical-align:top;font-family:\'Trebuchet MS\', Arial, sans-serif;font-size:13px;line-height:19px;color:#142231;"><span class="rt-contact" style="font-family:\'Trebuchet MS\', Arial, sans-serif;font-size:13px;line-height:19px;color:#142231;">'.$label.'</span></td></tr>';
        }

        return $result;
    }

    private static function greeting(string $text): string
    {
        return '<p style="margin:0 0 28px;font-family:\'Trebuchet MS\', Arial, sans-serif;font-size:27px;line-height:35px;font-weight:bold;color:#142231;"><span style="font-family:\'Trebuchet MS\', Arial, sans-serif;font-size:27px;line-height:35px;font-weight:bold;color:#142231;">'.self::escape($text).'</span></p>';
    }

    private static function farewell(string $text, ?string $name): string
    {
        return '<p style="margin:0;font-family:Arial, sans-serif;font-size:16px;line-height:26px;color:#33414f;"><span style="font-family:Arial, sans-serif;font-size:16px;line-height:26px;color:#33414f;">'.self::escape($text).($name === null ? '' : '<br>'.self::escape($name)).'</span></p>';
    }

    private static function withoutTextLinks(string $html): string
    {
        return (string) preg_replace_callback('~<a\b[^>]*>(.*?)</a>~s', static fn (array $match): string => str_contains($match[1], '<img ') ? $match[0] : $match[1], $html);
    }

    private static function body(string $template): string
    {
        if (preg_match('~<body\b[^>]*>\n?(.*?)</body>~s', $template, $match) !== 1) {
            self::fail();
        }

        return $match[1];
    }

    private static function withoutMessageRow(string $body): string
    {
        $start = strpos($body, '<tr><td class="rt-text"');
        $next = strpos($body, '<tr class="rt-sig-row">');
        if ($start === false || $next === false || $start >= $next || substr($body, $next - 10, 10) !== '</td></tr>') {
            self::fail();
        }

        return substr($body, 0, $start).substr($body, $next);
    }

    private static function headCss(string $template): string
    {
        if (preg_match('~<style type="text/css">(.*?)</style>~s', $template, $match) !== 1) {
            self::fail();
        }

        return $match[1];
    }

    private static function template(): string
    {
        $path = resource_path('mail-templates/u2e-universal-v1.html');
        $source = is_file($path) && is_readable($path) ? @file_get_contents($path) : false;
        if (! is_string($source) || ! hash_equals(self::TEMPLATE_SHA256, hash('sha256', str_replace("\r\n", "\n", $source)))) {
            self::fail();
        }

        return str_replace("\r\n", "\n", $source);
    }

    private static function validate(array $data, array $options): void
    {
        self::keys($data, ['sourceHash', 'kind', 'identity', 'direct', 'company', 'dispatch']);
        if (! is_string($data['sourceHash']) || ! preg_match('/\A[0-9a-f]{64}\z/', $data['sourceHash']) || ! in_array($data['kind'], ['employee', 'system'], true)) {
            self::fail();
        }
        foreach (['identity', 'company', 'dispatch', 'direct'] as $name) {
            if (! is_array($data[$name])) {
                self::fail();
            }
        }
        self::keys($data['identity'], ['name', 'role']);
        self::keys($data['company'], ['name', 'website', 'websiteLabel', 'street', 'postalCity', 'registration', 'confidentiality']);
        self::keys($data['dispatch'], ['email', 'whatsapp', 'phone', 'phoneLabel']);
        foreach ($data['identity'] as $value) {
            self::text($value, 120);
        }
        foreach (['name' => 120, 'website' => 2048, 'websiteLabel' => 255, 'street' => 255, 'postalCity' => 255, 'registration' => 4000, 'confidentiality' => 8000] as $name => $maximum) {
            self::text($data['company'][$name], $maximum);
        }
        if ($data['kind'] === 'system' && $data['identity']['name'] !== $data['company']['name']) {
            self::fail();
        }
        $website = parse_url($data['company']['website']);
        if (! filter_var($data['company']['website'], FILTER_VALIDATE_URL) || ! is_array($website) || ! in_array($website['scheme'] ?? '', ['https', 'http'], true)
            || empty($website['host']) || isset($website['user']) || isset($website['pass'])) {
            self::fail();
        }
        if (! array_is_list($data['direct']) || count($data['direct']) < 1 || count($data['direct']) > 3) {
            self::fail();
        }
        $emails = 0;
        foreach ($data['direct'] as $contact) {
            if (! is_array($contact)) {
                self::fail();
            }
            self::keys($contact, ['type', 'value', 'label']);
            self::text($contact['label'], 254);
            if ($contact['type'] === 'email') {
                self::email($contact['value']);
                $emails++;
                if ($contact['label'] !== $contact['value']) {
                    self::fail();
                }
            } elseif ($contact['type'] === 'phone') {
                self::phone($contact['value'], $contact['label']);
            } else {
                self::fail();
            }
        }
        if ($emails !== 1 || $data['direct'][0]['type'] !== 'email') {
            self::fail();
        }
        self::email($data['dispatch']['email']);
        self::phone($data['dispatch']['phone'], $data['dispatch']['phoneLabel']);
        if (! is_string($data['dispatch']['whatsapp']) || ! preg_match('~\Ahttps://wa\.me/[0-9]{3,15}\z~', $data['dispatch']['whatsapp'])) {
            self::fail();
        }
        if (array_diff(array_keys($options), ['mode', 'showGreeting', 'greeting', 'showFarewell', 'farewell', 'farewellName']) !== []
            || (array_key_exists('mode', $options) && ! in_array($options['mode'], ['editor', 'send'], true))) {
            self::fail();
        }
        foreach (['showGreeting', 'showFarewell', 'farewellName'] as $name) {
            if (array_key_exists($name, $options) && ! is_bool($options[$name])) {
                self::fail();
            }
        }
        foreach (['greeting', 'farewell'] as $name) {
            if (array_key_exists($name, $options)) {
                self::text($options[$name], 300, true);
            }
        }
        if ((($options['showGreeting'] ?? false) && trim($options['greeting'] ?? '') === '')
            || (($options['showFarewell'] ?? false) && trim($options['farewell'] ?? '') === '')) {
            self::fail();
        }
    }

    private static function canonical(array $data): array
    {
        if (! array_is_list($data)) {
            ksort($data);
        }
        foreach ($data as &$value) {
            if (is_array($value)) {
                $value = self::canonical($value);
            }
        }

        return $data;
    }

    private static function keys(array $data, array $expected): void
    {
        $keys = array_keys($data);
        sort($keys);
        sort($expected);
        if ($keys !== $expected) {
            self::fail();
        }
    }

    private static function text(mixed $value, int $maximum, bool $allowEmpty = false): void
    {
        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8') || (! $allowEmpty && trim($value) === '')
            || mb_strlen($value, 'UTF-8') > $maximum || preg_match('/[\x00-\x1f\x7f]/', $value)) {
            self::fail();
        }
    }

    private static function email(mixed $value): void
    {
        self::text($value, 254);
        if (! filter_var($value, FILTER_VALIDATE_EMAIL)) {
            self::fail();
        }
    }

    private static function phone(mixed $value, mixed $label): void
    {
        self::text($value, 16);
        self::text($label, 32);
        if (! preg_match('/\A\+?[0-9]{3,15}\z/', $value) || ! preg_match('/\A[+0-9()\p{Zs}.\/-]+\z/u', $label)
            || preg_replace('/[()\p{Zs}.\/-]/u', '', $label) !== $value) {
            self::fail();
        }
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    private static function fail(): never
    {
        throw new RuntimeException('Der U2e-Signaturentwurf ist ungueltig oder nicht freigegeben.');
    }
}
