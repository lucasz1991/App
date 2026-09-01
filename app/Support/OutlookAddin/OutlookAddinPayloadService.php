<?php

namespace App\Support\OutlookAddin;

use App\Enums\MailDocumentKind;
use App\Models\User;
use App\Support\EmailTemplateBuilder;
use App\Support\Mail\SignatureArtifactVersion;
use RuntimeException;
use Throwable;

final class OutlookAddinPayloadService
{
    private const MAX_SIGNATURE_CHARACTERS = 30000;

    private const MAX_MEDIA_BYTES = 2097152;

    private const TRANSPARENT_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M/wHwAF/gL+Qn5ZAAAAAElFTkSuQmCC';

    private const SIGNATURE_RESPONSIVE_CSS = <<<'CSS'
<style>
table,td{border-collapse:collapse;mso-table-lspace:0;mso-table-rspace:0}img{border:0;outline:none;text-decoration:none}.rt-sign-stage,.rt-sign-content-frame{width:100%}.rt-sign-train-layer{overflow:hidden}.rt-sign-content{position:relative;z-index:1}
@media only screen and (max-width:600px){
.rt-pad{box-sizing:border-box!important;padding-left:16px!important;padding-right:16px!important}.rt-sign-stage,.rt-sign-content-frame{height:auto!important;max-height:none!important;min-height:0!important;overflow:visible!important}.rt-sign-train-layer{position:static!important;width:100%!important;height:auto!important;max-height:none!important;max-width:none!important;margin:0!important;overflow:hidden!important}.rt-sign-train-frame,.rt-sign-train-slot{height:auto!important}.rt-sign-train,.rt-sign-train-mso{display:block!important;width:135%!important;max-width:none!important;height:auto!important;margin:0 0 0 -35%!important}.rt-sign-layout,.rt-sign-layout>tbody,.rt-sign-top-row,.rt-sign-company-row{display:block!important;width:100%!important}.rt-sign-top-row>td,.rt-sign-company-row>td{box-sizing:border-box!important;display:block!important;width:100%!important}.rt-sign-logo{padding:12px 0 14px!important;text-align:left!important}.rt-sign-logo img{width:150px!important;margin-left:0!important}.rt-sign-identity{padding:10px 0 0!important}.rt-sign-company{padding:14px 0 0!important;border-left:0!important;border-top:1px solid #dfe3e6!important;text-align:left!important}.rt-company-contact{float:none!important;display:table!important;width:100%!important;margin:10px 0 0!important}.rt-company-contact td{text-align:left!important}.rt-contact img{width:18px!important;height:18px!important}.rt-contact td.rt-contact-icon{width:18px!important}.rt-contact td.rt-contact-text{padding-left:7px!important;font-size:12px!important;line-height:17px!important}
}
</style>
CSS;

    /** @return array<string, mixed> */
    public function forUser(User $user): array
    {
        try {
            $templateSnapshot = EmailTemplateBuilder::publishedDocumentSnapshot(MailDocumentKind::Template);
            $signatureSnapshot = EmailTemplateBuilder::publishedDocumentSnapshot(MailDocumentKind::Signature);

            if ($templateSnapshot === null || $signatureSnapshot === null) {
                throw new RuntimeException('Vorlage und Signatur muessen aktiv veroeffentlicht sein.');
            }

            $builder = new EmailTemplateBuilder($user);
            [$signatureHtml, $signatureMedia] = $this->localizeRemoteImages(
                $this->withMarker(
                    self::SIGNATURE_RESPONSIVE_CSS.$builder->buildOutlookAddinSignatureHtml('light'),
                ),
            );
            $signatureHtml = $this->compactSignature($signatureHtml);
            [$templateHtml, $templateMedia] = $this->materializeTemplateCids(
                $this->withMarker($builder->buildOutlookAddinTemplateHtml('light')),
                $signatureSnapshot['html'],
            );

            if (mb_strlen($signatureHtml, 'UTF-8') > self::MAX_SIGNATURE_CHARACTERS) {
                throw new RuntimeException('Die veroeffentlichte Signatur ueberschreitet das Outlook-Limit von 30.000 Zeichen ('.mb_strlen($signatureHtml, 'UTF-8').').');
            }

            return [
                'schema' => 1,
                'marker' => (string) config('outlook_addin.marker', 'RT-SIGNATURE-MANAGED-V1'),
                'signature' => [
                    'html' => $signatureHtml,
                    'media' => $signatureMedia,
                ],
                'template' => [
                    'html' => $templateHtml,
                    'media' => $templateMedia,
                ],
                'version' => [
                    'signature' => $this->snapshotHash($signatureSnapshot),
                    'template' => $this->snapshotHash($templateSnapshot),
                ],
            ];
        } catch (OutlookAddinException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new OutlookAddinException(
                'Die veroeffentlichte Outlook-Vorlage ist noch nicht einsatzbereit.',
                409,
                'outlook_addin_publication_invalid',
                $exception,
            );
        }
    }

    private function withMarker(string $html): string
    {
        $marker = preg_replace(
            '/[^A-Z0-9_-]/i',
            '',
            (string) config('outlook_addin.marker', 'RT-SIGNATURE-MANAGED-V1'),
        ) ?: 'RT-SIGNATURE-MANAGED-V1';

        return "<!-- {$marker} -->\n"
            .'<span aria-hidden="true" style="display:none!important;mso-hide:all;font-size:0;line-height:0;max-height:0;overflow:hidden;">'
            .$marker
            .'</span>'
            .$html;
    }

    private function compactSignature(string $html): string
    {
        $html = preg_replace_callback(
            '~<!--(.*?)-->~s',
            static function (array $match): string {
                $comment = (string) $match[1];

                return str_contains($comment, '[if')
                    || str_contains($comment, 'RT-SIGNATURE-MANAGED-V1')
                    ? $match[0]
                    : '';
            },
            $html,
        ) ?? $html;
        $html = preg_replace('~>\s+<~', '><', $html) ?? $html;

        return trim($html);
    }

    /**
     * @return array{0: string, 1: list<array{name: string, contentId: string, base64: string}>}
     */
    private function localizeRemoteImages(string $html): array
    {
        $mediaByPath = [];
        $replaced = preg_replace_callback(
            '~\bsrc\s*=\s*(["\'])(.*?)\1~i',
            function (array $match) use (&$mediaByPath): string {
                $source = html_entity_decode((string) $match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $path = $this->mailAssetPath($source);
                $path = $this->preferStaticPng($path);

                if (! array_key_exists($path, $mediaByPath)) {
                    $mediaByPath[$path] = $this->attachment($path);
                }

                return 'src='.$match[1].'cid:'.$mediaByPath[$path]['contentId'].$match[1];
            },
            $html,
        );

        if (! is_string($replaced)) {
            throw new RuntimeException('Die Signaturbilder konnten nicht in CID-Anhaenge umgewandelt werden.');
        }

        if (preg_match('~<img\b[^>]*\bsrc\s*=\s*["\'](?!cid:)~i', $replaced)) {
            throw new RuntimeException('Die Signatur enthaelt eine nicht portable Bildquelle.');
        }

        $media = array_values($mediaByPath);
        $this->assertMediaSize($media);

        return [$replaced, $media];
    }

    private function mailAssetPath(string $source): string
    {
        $parts = parse_url($source);
        $path = rawurldecode((string) ($parts['path'] ?? ''));
        $sourceHost = strtolower((string) ($parts['host'] ?? ''));

        if (! in_array(($parts['scheme'] ?? ''), ['http', 'https'], true)
            || $sourceHost === ''
            || ! preg_match('~^/mail-assets/([A-Za-z0-9._-]+)$~', $path, $match)) {
            throw new RuntimeException(
                'Die Signatur enthaelt eine nicht freigegebene Bildquelle: '.substr($source, 0, 180)
            );
        }

        $file = public_path('mail-assets/'.$match[1]);
        if (! is_file($file) || ! is_readable($file)) {
            throw new RuntimeException('Ein Signaturbild fehlt auf dem Server.');
        }

        return $file;
    }

    private function preferStaticPng(string $path): string
    {
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'gif') {
            return $path;
        }

        $png = preg_replace('/\.gif$/i', '.png', $path);

        return is_string($png) && is_file($png) ? $png : $path;
    }

    /** @return array{name: string, contentId: string, base64: string} */
    private function attachment(string $path, ?string $contentId = null): array
    {
        $binary = file_get_contents($path);
        if (! is_string($binary)) {
            throw new RuntimeException('Ein Outlook-Signaturbild konnte nicht gelesen werden.');
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (! in_array($extension, ['png', 'gif', 'jpg', 'jpeg'], true)) {
            throw new RuntimeException('Die Signatur enthaelt ein nicht unterstuetztes Bildformat.');
        }

        $contentId ??= 'railtime-'.substr(hash('sha256', $binary), 0, 20).'.'.$extension;

        return [
            'name' => $contentId,
            'contentId' => $contentId,
            'base64' => base64_encode($binary),
        ];
    }

    /**
     * @return array{0: string, 1: list<array{name: string, contentId: string, base64: string}>}
     */
    private function materializeTemplateCids(string $html, string $signatureDocument): array
    {
        $artifactVersion = SignatureArtifactVersion::detect(
            MailDocumentKind::Signature,
            $signatureDocument,
        );
        $mark = str_replace('.gif', '.png', EmailTemplateBuilder::emailMarkAsset('light', $artifactVersion));
        $logo = str_replace('.gif', '.png', EmailTemplateBuilder::signatureLogoAsset('light', $artifactVersion));
        $train = $this->mailAssetPath(EmailTemplateBuilder::signatureTrainStillUrl('light', $artifactVersion));
        $mapping = [
            'railtime-mark' => public_path('mail-assets/'.$mark),
            'railtime-mark-still' => public_path('mail-assets/'.$mark),
            'railtime-logo' => public_path('mail-assets/'.$logo),
            'railtime-logo-still' => public_path('mail-assets/'.$logo),
            'railtime-train' => $train,
            'railtime-train-still' => $train,
            'railtime-icon-location' => public_path('mail-assets/contact-location.png'),
            'railtime-icon-phone' => public_path('mail-assets/contact-phone.png'),
            'railtime-icon-mobile' => public_path('mail-assets/contact-mobile.png'),
            'railtime-icon-email' => public_path('mail-assets/contact-email.png'),
            'railtime-icon-web' => public_path('mail-assets/contact-web.png'),
        ];

        preg_match_all('~\bsrc\s*=\s*["\']cid:([^"\']+)["\']~i', $html, $matches);
        $requested = array_values(array_unique($matches[1] ?? []));
        $media = [];

        foreach ($requested as $contentId) {
            if ($contentId === 'railtime-train-idle') {
                $mailContentId = 'railtime-train-idle.png';
                $html = $this->replaceCid($html, $contentId, $mailContentId);
                $media[] = [
                    'name' => $mailContentId,
                    'contentId' => $mailContentId,
                    'base64' => self::TRANSPARENT_PNG,
                ];

                continue;
            }

            $path = $mapping[$contentId] ?? null;
            if (! is_string($path) || ! is_file($path) || ! is_readable($path)) {
                throw new RuntimeException("Das Outlook-Medium {$contentId} fehlt.");
            }

            $attachment = $this->attachment($path);
            $html = $this->replaceCid($html, $contentId, $attachment['contentId']);
            $media[] = $attachment;
        }

        $this->assertMediaSize($media);

        return [$html, $media];
    }

    private function replaceCid(string $html, string $from, string $to): string
    {
        $replaced = preg_replace(
            '~cid:'.preg_quote($from, '~').'(?=["\'])~i',
            'cid:'.$to,
            $html,
        );

        return is_string($replaced) ? $replaced : $html;
    }

    /** @param list<array{name: string, contentId: string, base64: string}> $media */
    private function assertMediaSize(array $media): void
    {
        $bytes = array_sum(array_map(
            static fn (array $attachment): int => (int) (strlen($attachment['base64']) * 0.75),
            $media,
        ));

        if ($bytes > self::MAX_MEDIA_BYTES) {
            throw new RuntimeException('Die Outlook-Medien ueberschreiten das sichere Paketlimit.');
        }
    }

    /** @param array{html: string, css: string} $snapshot */
    private function snapshotHash(array $snapshot): string
    {
        return substr(hash('sha256', $snapshot['html']."\0".$snapshot['css']), 0, 16);
    }
}
