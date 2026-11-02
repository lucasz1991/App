<?php

declare(strict_types=1);

namespace App\Support\Mail;

use App\Support\OutlookAddin\OutlookLinkedAnimatedDelivery;
use Illuminate\Contracts\Support\Htmlable;
use RuntimeException;

/** Unused native document renderer. Publication/identity admission belongs to its trusted caller. */
final class NativeSignatureHandoffRenderer
{
    public const REVISION = 'native-signature-handoff-v1';

    public const DESIGN_SOURCE = '8210c549eb27aec006bca093b4197fbb3f19da21fc853bb49f6ffc00640f2916';

    private const ASSETS = [
        'logo' => ['/m/b983af0049.png', 'b983af0049926c128807d605a51a68ce90fea06c5c340f7728b105a96d7f021a', 'image/png', 400, 68],
        'email' => ['/m/9dfa25b253.png', '9dfa25b253b065c73173b884939db2a032453c44eb2ba6e9e65434a95a072675', 'image/png', 44, 44],
        'mobile' => ['/m/d92bd192b6.png', 'd92bd192b664c536fe29b1c927023bca44041de90bc9bfe34e6df03be5006d32', 'image/png', 44, 44],
        'web' => ['/m/1593d63cca.png', '1593d63ccacf8c884b71870fe1478f218ea0f541e918d84115978bc2b75c64ff', 'image/png', 44, 44],
        'location' => ['/m/a38bc7d27c.png', 'a38bc7d27c9c8c04c2a20815ad9576c76aebfe0bd651f9c5e90909b83bdf6925', 'image/png', 44, 44],
        'dispatchMail' => ['/m/19125261c2.png', '19125261c2bc8274933b7a1c9efd1c5db23cd751c65fc1f88d2dcae22e4481b1', 'image/png', 72, 72],
        'whatsapp' => ['/m/f9d88b899d.png', 'f9d88b899d574851c64d02c9835a5b5fcf8d7c68e05d6c9b3f5618d3ac38bbd9', 'image/png', 72, 72],
        'dispatchPhone' => ['/m/1111670ac8.png', '1111670ac86e12aff6ad7809e11959b7af3c1275d4c5f2832b60934992f8f19a', 'image/png', 72, 72],
        'number' => ['/m/ef89fd23c6.png', 'ef89fd23c6e9791cc03dfddb31e7f8e319b0b1992b1a0e839b4ebb771b18c674', 'image/png', 216, 40],
        'train' => ['/mail-assets/zug-dampf-outlook-brand-v2-light.gif', '6aeef403af2bd639b8fee076312a37b6b3e27f22c148ae835ca8d5d43fcc3a2e', 'image/gif', 695, 151],
    ];

    public function __construct(private readonly ?string $baseUrl = null) {}

    /**
     * @param  array{sourceHash:string,kind:'employee'|'system',identity:array{name:string,role:string},direct:list<array{type:'email'|'phone',value:string,label:string}>,company:array{name:string,website:string,websiteLabel:string,street:string,postalCity:string,registration:string,confidentiality:string},dispatch:array{email:string,whatsapp:string,phone:string,phoneLabel:string}}  $descriptor
     * @param  array{scopeId?:string,showGreeting?:bool,greeting?:string,showFarewell?:bool,farewell?:string,farewellName?:bool}  $options
     * @return array{html:string,bodyHtml:string,signatureHtml:string,headCss:string,headCssHash:string,templateHash:string,scopeId:string,version:string,sourceHash:string,authorContentHash:string,media:list<array{name:string,url:string,sha256:string,mime:string,width:int,height:int}>}
     */
    public function render(array $descriptor, Htmlable $trustedAuthorContent, array $options = []): array
    {
        $this->validate($descriptor, $options);
        $approvedRoot = rtrim((string) config('app.url'), '/');
        $root = rtrim($this->baseUrl ?? $approvedRoot, '/');
        $parts = parse_url($root);
        if ($root !== $approvedRoot || ! filter_var($root, FILTER_VALIDATE_URL) || ! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || ! empty($parts['path'])) {
            self::fail();
        }
        $scope = 'rt-handoff-'.($options['scopeId'] ?? bin2hex(random_bytes(16)));
        $author = $trustedAuthorContent->toHtml();
        if (! is_string($author) || strlen($author) > 262144 || ! mb_check_encoding($author, 'UTF-8')
            || str_contains($author, "\0") || str_contains($author, $scope)) {
            self::fail();
        }
        $template = self::template();
        $body = self::between($template, '<!-- RT_NATIVE_BODY_START -->', '<!-- RT_NATIVE_BODY_END -->');
        $css = self::between($template, '/* RT_NATIVE_CSS_START */', '/* RT_NATIVE_CSS_END */');
        $message = '<!-- RT_NATIVE_MESSAGE_START -->'.self::between($body, '<!-- RT_NATIVE_MESSAGE_START -->', '<!-- RT_NATIVE_MESSAGE_END -->').'<!-- RT_NATIVE_MESSAGE_END -->';
        $signature = str_replace($message, '', $body, $removed);
        if ($removed !== 1) {
            self::fail();
        }
        $catalog = OutlookLinkedAnimatedDelivery::fingerprint();
        $media = [];
        $assets = [];
        foreach (self::ASSETS as $name => [$path, $sha, $mime, $width, $height]) {
            $key = $mime === 'image/gif' ? 'animated:'.$path : $path;
            if (($catalog[$key] ?? '') !== $sha) {
                self::fail();
            }
            $assets[$name] = $root.$path;
            if ($name !== 'number' || $descriptor['dispatch']['phone'] === '+491601881839') {
                $media[] = ['name' => $name, 'url' => $root.$path, 'sha256' => $sha, 'mime' => $mime, 'width' => $width, 'height' => $height];
            }
        }
        $versionOptions = $options;
        unset($versionOptions['scopeId']);
        $version = substr(hash('sha256', self::REVISION."\0".hash('sha256', $template)."\0".json_encode([$descriptor, $versionOptions, $media, hash('sha256', $author)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), 0, 16);
        $values = [
            'SCOPE_ID' => $scope, 'VERSION' => $version, 'SOURCE_HASH' => $descriptor['sourceHash'], 'KIND' => $descriptor['kind'],
            'DOCUMENT_TITLE' => 'RailTime · '.$descriptor['company']['name'],
            'IDENTITY_NAME' => $descriptor['identity']['name'], 'IDENTITY_ROLE' => $descriptor['identity']['role'],
            'COMPANY_NAME' => $descriptor['company']['name'], 'COMPANY_WEBSITE' => $descriptor['company']['website'], 'COMPANY_WEBSITE_LABEL' => $descriptor['company']['websiteLabel'],
            'COMPANY_STREET' => $descriptor['company']['street'], 'COMPANY_POSTAL_CITY' => $descriptor['company']['postalCity'],
            'LEGAL_REGISTRATION' => $descriptor['company']['registration'], 'LEGAL_CONFIDENTIALITY' => $descriptor['company']['confidentiality'],
            'ASSET_LOGO' => $assets['logo'], 'ASSET_WEB' => $assets['web'], 'ASSET_LOCATION' => $assets['location'], 'ASSET_TRAIN' => $assets['train'],
        ];
        $tokens = [];
        foreach ($values as $name => $value) {
            $tokens['{{'.$name.'}}'] = self::escape($value);
        }
        $tokens['{{DIRECT_ROWS}}'] = $this->directRows($descriptor['direct'], $assets);
        $tokens['{{DISPATCH_ACTIONS_DESKTOP}}'] = $this->actions($descriptor['dispatch'], $assets, false);
        $tokens['{{DISPATCH_ACTIONS_MOBILE}}'] = $this->actions($descriptor['dispatch'], $assets, true);
        $tokens['{{GREETING_HTML}}'] = ($options['showGreeting'] ?? false) ? '<h1 style="margin:0 0 28px;font-family:Trebuchet MS,Arial,sans-serif;font-size:27px;line-height:35px;font-weight:bold;color:#142231;">'.self::escape($options['greeting'] ?? '').'</h1>' : '';
        $tokens['{{FAREWELL_HTML}}'] = ($options['showFarewell'] ?? false) ? '<p style="margin:0;font-family:Arial,sans-serif;font-size:16px;line-height:26px;color:#33414f;">'.self::escape($options['farewell'] ?? '').(($options['farewellName'] ?? true) ? '<br>'.self::escape($descriptor['identity']['name']) : '').'</p>' : '';
        // strtr replaces only template tokens: author bytes containing {{...}} are never reinterpreted.
        $tokens['{{AUTHOR_CONTENT}}'] = $author;

        return [
            'html' => strtr($template, $tokens), 'bodyHtml' => strtr($body, $tokens), 'signatureHtml' => strtr($signature, $tokens),
            'headCss' => strtr($css, $tokens), 'headCssHash' => hash('sha256', strtr($css, $tokens)), 'templateHash' => hash('sha256', $template),
            'scopeId' => $scope, 'version' => $version, 'sourceHash' => $descriptor['sourceHash'],
            'authorContentHash' => hash('sha256', $author), 'media' => $media,
        ];
    }

    /** Exact fixed-template admission, never a general editable CSS allowlist. */
    public static function headCssForScope(string $scopeId): string
    {
        if (! preg_match('/\Art-handoff-[0-9a-f]{32}\z/', $scopeId)) {
            self::fail();
        }
        $template = self::template();

        return strtr(self::between($template, '/* RT_NATIVE_CSS_START */', '/* RT_NATIVE_CSS_END */'), ['{{SCOPE_ID}}' => $scopeId]);
    }

    public static function recognizesHeadCss(string $css, string $scopeId): bool
    {
        try {
            return hash_equals(self::headCssForScope($scopeId), $css);
        } catch (RuntimeException) {
            return false;
        }
    }

    /** The emitted STYLE carries one fixed comment wrapper, never arbitrary surrounding CSS. */
    public static function recognizesHeadStyleSheet(string $css, string $scopeId): bool
    {
        if (self::recognizesHeadCss($css, $scopeId)) {
            return true;
        }
        try {
            if (! preg_match('/\Art-handoff-[0-9a-f]{32}\z/', $scopeId)) {
                return false;
            }
            $wrapped = self::between(self::template(), '<style data-rt-native-style="{{SCOPE_ID}}">', '</style>');

            return hash_equals(strtr($wrapped, ['{{SCOPE_ID}}' => $scopeId]), $css);
        } catch (RuntimeException) {
            return false;
        }
    }

    private function validate(array $data, array $options): void
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
        foreach (['name' => 120, 'website' => 2048, 'websiteLabel' => 255, 'street' => 255, 'postalCity' => 255, 'registration' => 4000, 'confidentiality' => 8000] as $name => $limit) {
            self::text($data['company'][$name], $limit);
        }
        if ($data['kind'] === 'system' && $data['identity']['name'] !== $data['company']['name']) {
            self::fail();
        }
        $site = parse_url($data['company']['website']);
        if (! filter_var($data['company']['website'], FILTER_VALIDATE_URL) || ! is_array($site) || ! in_array($site['scheme'] ?? '', ['https', 'http'], true) || empty($site['host']) || isset($site['user']) || isset($site['pass'])) {
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
        if (array_diff(array_keys($options), ['scopeId', 'showGreeting', 'greeting', 'showFarewell', 'farewell', 'farewellName']) !== []) {
            self::fail();
        }
        if (array_key_exists('scopeId', $options) && (! is_string($options['scopeId']) || ! preg_match('/\A[0-9a-f]{32}\z/', $options['scopeId']))) {
            self::fail();
        }
        foreach (['showGreeting', 'showFarewell', 'farewellName'] as $name) {
            if (array_key_exists($name, $options) && ! is_bool($options[$name])) {
                self::fail();
            }
        }
        foreach (['greeting', 'farewell'] as $name) {
            if (array_key_exists($name, $options)) {
                self::text($options[$name], 300, allowEmpty: true);
            }
        }
        if ((($options['showGreeting'] ?? false) && trim($options['greeting'] ?? '') === '')
            || (($options['showFarewell'] ?? false) && trim($options['farewell'] ?? '') === '')) {
            self::fail();
        }
    }

    private function directRows(array $contacts, array $assets): string
    {
        $html = '';
        foreach ($contacts as $contact) {
            $href = ($contact['type'] === 'email' ? 'mailto:' : 'tel:').$contact['value'];
            $source = $assets[$contact['type'] === 'email' ? 'email' : 'mobile'];
            $html .= '<tr><td width="26" valign="top" style="width:15px;padding:2px 11px 5px 0;vertical-align:top;line-height:15px;"><img src="'.self::escape($source).'" width="15" height="15" border="0" alt="" style="display:block;border:0;width:15px;max-width:100%;height:auto;"></td>'
                .'<td valign="top" style="padding:0 0 5px;vertical-align:top;font-family:Trebuchet MS,Arial,sans-serif;font-size:13px;line-height:19px;color:#142231;overflow-wrap:anywhere;word-wrap:break-word;"><a href="'.self::escape($href).'" style="color:#142231;text-decoration:none;overflow-wrap:anywhere;word-wrap:break-word;">'.self::escape($contact['label']).'</a></td></tr>';
        }

        return $html;
    }

    private function actions(array $dispatch, array $assets, bool $mobile): string
    {
        $default = $dispatch['phone'] === '+491601881839';
        [$width, $left, $phone, $number, $icon] = $mobile ? [170, 48, 28, 94, 20] : [215, $default ? 75 : 62, 32, $default ? 108 : 121, 24];
        $link = static fn (string $href, string $source, string $alt): string => '<a href="'.self::escape($href).'" style="color:#ffffff;text-decoration:none;"><img src="'.self::escape($source).'" width="'.$icon.'" height="'.$icon.'" alt="'.$alt.'" style="display:block;border:0;width:'.$icon.'px;height:'.$icon.'px;"></a>';
        $numberHtml = $default ? '<img class="r784-number-image" src="'.self::escape($assets['number']).'" width="'.$number.'" height="'.($mobile ? 17 : 20).'" alt="+49 160 1881839" style="display:block;width:'.$number.'px;height:auto;border:0;">' : '<span style="color:#ffffff;font-family:Arial,sans-serif;font-size:'.($mobile ? 12 : 13).'px;line-height:20px;font-weight:bold;white-space:nowrap;">'.self::escape($dispatch['phoneLabel']).'</span>';
        $imageClass = static fn (string $html): string => str_replace('<img ', '<img class="r784-action-icon" ', $html);
        $leftHtml = self::slots('r784-links', [[$icon, $imageClass($link('mailto:'.$dispatch['email'], $assets['dispatchMail'], 'E-Mail an die Disposition'))], [8, '&nbsp;'], [$icon, $imageClass($link($dispatch['whatsapp'], $assets['whatsapp'], 'WhatsApp'))]]);
        $phoneHtml = self::slots('r784-phone', [[2, '&nbsp;'], [$icon, $imageClass($link('tel:'.$dispatch['phone'], $assets['dispatchPhone'], 'Telefon'))], [6, '&nbsp;']]);
        $pad = $mobile ? '0' : '8px 0';

        return '<table class="rt-cta-actions r784-actions'.($mobile ? ' r784-mobile-actions' : '').'" role="presentation" width="'.$width.'" border="0" cellspacing="0" cellpadding="0" style="width:'.$width.'px;max-width:100%;border-collapse:collapse;table-layout:fixed;'.($mobile ? '' : 'border-top:1px solid #ffffff;').'"><tbody><tr>'
            .'<td class="r784-actions-left" width="'.$left.'" valign="middle" style="width:'.$left.'px;padding:'.$pad.';vertical-align:middle;">'.$leftHtml.'</td>'
            .'<td class="r784-actions-phone" width="'.$phone.'" valign="middle" style="width:'.$phone.'px;padding:'.$pad.';vertical-align:middle;">'.$phoneHtml.'</td>'
            .'<td class="r784-actions-number" width="'.$number.'" valign="middle" nowrap style="width:'.$number.'px;padding:'.$pad.';padding-left:0;text-align:left;vertical-align:middle;white-space:nowrap;"><a href="tel:'.self::escape($dispatch['phone']).'" style="color:#ffffff;text-decoration:none;">'.$numberHtml.'</a></td>'
            .'</tr></tbody></table>';
    }

    private static function slots(string $class, array $slots): string
    {
        $width = array_sum(array_column($slots, 0));
        $html = '<table class="'.$class.'" role="presentation" width="'.$width.'" border="0" cellspacing="0" cellpadding="0" style="width:'.$width.'px;border-collapse:collapse;table-layout:fixed;"><tbody><tr>';
        foreach ($slots as $index => [$size, $content]) {
            $html .= '<td class="'.$class.'-slot-'.$index.'" width="'.$size.'" valign="middle" style="width:'.$size.'px;padding:0;font-size:0;line-height:0;">'.$content.'</td>';
        }

        return $html.'</tr></tbody></table>';
    }

    private static function between(string $html, string $start, string $end): string
    {
        if (substr_count($html, $start) !== 1 || substr_count($html, $end) !== 1 || ($a = strpos($html, $start)) >= ($b = strpos($html, $end))) {
            self::fail();
        }

        return substr($html, $a + strlen($start), $b - $a - strlen($start));
    }

    private static function template(): string
    {
        $path = resource_path('mail-templates/native-signature-handoff-v1.html');
        $template = is_file($path) && is_readable($path) ? @file_get_contents($path) : false;
        if (! is_string($template) || ! str_contains($template, self::DESIGN_SOURCE)) {
            self::fail();
        }

        return $template;
    }

    private static function keys(array $value, array $expected): void
    {
        $keys = array_keys($value);
        sort($keys);
        sort($expected);
        if ($keys !== $expected) {
            self::fail();
        }
    }

    private static function text(mixed $value, int $maximum, bool $allowEmpty = false): void
    {
        if (! is_string($value) || (! $allowEmpty && trim($value) === '') || mb_strlen($value, 'UTF-8') > $maximum || ! mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x1f\x7f]/', $value)) {
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
        if (! preg_match('/\A\+?[0-9]{3,15}\z/', $value) || ! preg_match('/\A[+0-9()\p{Zs}.\/-]+\z/u', $label) || preg_replace('/[()\p{Zs}.\/-]/u', '', $label) !== $value) {
            self::fail();
        }
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    private static function fail(): never
    {
        throw new RuntimeException('Der native Signatur-Deskriptor ist ungueltig oder nicht freigegeben.');
    }
}
