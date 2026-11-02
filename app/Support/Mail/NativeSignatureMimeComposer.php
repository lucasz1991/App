<?php

declare(strict_types=1);

namespace App\Support\Mail;

use DOMDocument;
use DOMXPath;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use RuntimeException;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\Multipart\AlternativePart;
use Symfony\Component\Mime\Part\TextPart;

/** Local preparation only: no transport, caller, mailbox permission or activation. */
final class NativeSignatureMimeComposer
{
    public const MAILBOX = 'it-technik@rail-time.de';

    public const SUBJECT = '[TEST] RailTime – native MIME-Vorbereitung R787';

    /** The envelope is deliberately not configurable. The descriptor must come
     * from a separately admitted publication/identity, never from mail HTML.
     *
     * @param  list<string>  $references
     */
    public function itSelfTest(array $descriptor, Htmlable $trustedAuthorContent, array $rendererOptions = [], ?string $inReplyTo = null, array $references = []): Email
    {
        [$reply, $thread] = self::thread($inReplyTo, $references);
        // Read opaque author/quote bytes once. Only the independent plain-text
        // alternative is parsed; the HTML passed to MIME is never rewritten.
        $author = $trustedAuthorContent->toHtml();
        $rendered = (new NativeSignatureHandoffRenderer)->render($descriptor, new HtmlString($author), $rendererOptions);
        self::assertCompleteDocument($rendered, $descriptor['sourceHash'], $author);
        $plain = self::plainText($author, $descriptor, $rendererOptions);
        $mailbox = new Address(self::MAILBOX, 'RailTime IT-Test');
        $email = (new Email)->from($mailbox)->to($mailbox)->replyTo($mailbox)
            ->subject(($reply === null ? '' : 'AW: ').self::SUBJECT)
            ->text($plain, 'utf-8')->html($rendered['html'], 'utf-8');
        // Explicit base64 parts preserve the complete HEAD and every original
        // HTML byte, including mixed author LF/CRLF; no CSS inliner is invoked.
        $email->setBody(new AlternativePart(
            new TextPart($plain, 'utf-8', 'plain', 'base64'),
            new TextPart($rendered['html'], 'utf-8', 'html', 'base64'),
        ));
        $email->getHeaders()->addTextHeader('X-RailTime-Native-Preparation', 'local-only-v1');
        if ($reply !== null) {
            $email->getHeaders()->addIdHeader('In-Reply-To', $reply);
            $email->getHeaders()->addIdHeader('References', $thread);
        }
        $email->ensureValidity();

        return $email;
    }

    private static function assertCompleteDocument(array $output, string $sourceHash, string $author): void
    {
        $html = $output['html'];
        $headEnd = strpos($html, '</head>');
        if (! str_starts_with($html, '<!DOCTYPE html>') || ! str_ends_with(rtrim($html), '</html>')
            || $headEnd === false || ! preg_match('/\Art-handoff-[0-9a-f]{32}\z/', $output['scopeId'])
            || ! hash_equals($sourceHash, $output['sourceHash'])
            || ! hash_equals(hash('sha256', $author), $output['authorContentHash'])
            || ! hash_equals(hash('sha256', $output['headCss']), $output['headCssHash'])
            || ! NativeSignatureHandoffRenderer::recognizesHeadCss($output['headCss'], $output['scopeId'])
            || substr_count($html, $output['bodyHtml']) !== 1) {
            self::invalid();
        }
        $head = substr($html, 0, $headEnd);
        $pattern = '~<style data-rt-native-style="'.preg_quote($output['scopeId'], '~').'">(.*?)</style>~s';
        if (substr_count($head, '<head>') !== 1 || substr_count($head, '<style') !== 1
            || preg_match($pattern, $head, $style) !== 1
            || ! NativeSignatureHandoffRenderer::recognizesHeadStyleSheet($style[1], $output['scopeId'])) {
            self::invalid();
        }
    }

    /** @return array{?string,list<string>} */
    private static function thread(?string $inReplyTo, array $references): array
    {
        if (! array_is_list($references) || count($references) > 20 || ($inReplyTo === null && $references !== [])) {
            self::invalid();
        }
        if ($inReplyTo === null) {
            return [null, []];
        }
        $reply = self::messageId($inReplyTo);
        $thread = array_map(self::messageId(...), $references);
        if (count(array_unique($thread)) !== count($thread) || ($thread !== [] && end($thread) !== $reply)) {
            self::invalid();
        }

        return [$reply, $thread === [] ? [$reply] : $thread];
    }

    private static function messageId(mixed $value): string
    {
        if (! is_string($value) || strlen($value) > 254 || preg_match('/[\x00-\x20\x7f-\xff]/', $value)) {
            self::invalid();
        }
        if (str_starts_with($value, '<') && str_ends_with($value, '>')) {
            $value = substr($value, 1, -1);
        }
        $label = '[A-Za-z0-9](?:[A-Za-z0-9\-]{0,61}[A-Za-z0-9])?';
        if (! preg_match('/\A[A-Za-z0-9][A-Za-z0-9_+\-]*(?:\.[A-Za-z0-9][A-Za-z0-9_+\-]*)*@'.$label.'(?:\.'.$label.')*\z/', $value)) {
            self::invalid();
        }

        return $value;
    }

    private static function plainText(string $author, array $data, array $options): string
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $dom->loadHTML('<?xml encoding="UTF-8"><html><body>'.$author.'</body></html>', LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $xpath = new DOMXPath($dom);
        foreach (iterator_to_array($xpath->query('//head|//style|//script|//noscript|//template|//comment()')) as $node) {
            $node->parentNode?->removeChild($node);
        }
        foreach (iterator_to_array($xpath->query('//br|//p|//div|//tr|//h1|//h2|//h3|//li|//pre|//blockquote')) as $node) {
            $node->parentNode?->insertBefore($dom->createTextNode("\n"), $node);
            if ($node->nextSibling) {
                $node->parentNode?->insertBefore($dom->createTextNode("\n"), $node->nextSibling);
            } else {
                $node->parentNode?->appendChild($dom->createTextNode("\n"));
            }
        }
        $text = $dom->getElementsByTagName('body')->item(0)?->textContent ?? '';
        $text = preg_replace('/\bRT-(?:TEMPLATE-MANAGED-V1|SIGNATURE-MANAGED-V1|COMPOSE-EDITABLE-(?:START|END)-V1|SIGNATURE-VERSION|NATIVE-LAYOUT-RESTORE-V[0-9]+)(?::[A-Za-z0-9_-]+)*/', '', $text) ?? '';
        $text = trim(preg_replace('/\n[\t \r\n]*\n/', "\n\n", str_replace("\r", '', $text)) ?? '');
        $blocks = [];
        if ($options['showGreeting'] ?? false) {
            $blocks[] = $options['greeting'];
        }
        $blocks[] = $text;
        if ($options['showFarewell'] ?? false) {
            $blocks[] = $options['farewell'].(($options['farewellName'] ?? true) ? "\n".$data['identity']['name'] : '');
        }
        $blocks[] = implode("\n", array_filter([$data['identity']['name'], $data['identity']['role'], ...array_column($data['direct'], 'label')], static fn (string $value): bool => $value !== ''));
        $blocks[] = implode("\n", [$data['company']['name'], $data['company']['website'], $data['company']['street'], $data['company']['postalCity']]);
        $blocks[] = "Dispo 24/7 erreichbar\nE-Mail: ".$data['dispatch']['email']."\nWhatsApp: ".$data['dispatch']['whatsapp']."\nTelefon: ".$data['dispatch']['phoneLabel'];
        $blocks[] = $data['company']['registration']."\n".$data['company']['confidentiality'];

        return implode("\n\n", array_values(array_filter($blocks, static fn (string $value): bool => trim($value) !== '')));
    }

    private static function invalid(): never
    {
        throw new RuntimeException('Die lokale native MIME-Vorbereitung ist ungueltig.');
    }
}
