<?php

declare(strict_types=1);

namespace App\Support\Mail;

use App\Enums\MailDocumentKind;
use App\Models\MailDocument;
use App\Support\CompanyData;
use App\Support\EmailTemplateBuilder;
use App\Support\OutlookAddin\OutlookLinkedAnimatedDelivery;
use App\Support\OutlookAddin\OutlookStaticLinkedTestProfiles;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\HtmlString;
use RuntimeException;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\RawMessage;
use Throwable;

/** Explicit one-recipient system test only; never installed into normal delivery. */
final class NativeSystemMailSelfTest
{
    public const SUBJECT = '[TEST] RailTime – native Systemvorlage R787';

    public function prepare(string $operation): array
    {
        $config = $this->configuration();
        $source = $this->source();
        $directory = $this->directory($operation);
        if (is_file($directory.'/record.json')) {
            return $this->read($directory, $source['hash'], $config['hash']);
        }
        $email = (new NativeSignatureMimeComposer)->itSelfTest($source['descriptor'], new HtmlString('<p>Technischer Selbsttest der veröffentlichten RailTime-Systemsignatur mit vollständiger HEAD-CSS.</p><p>Diese Nachricht wurde gezielt an das IT-Testpostfach vorbereitet.</p>'));
        // No caller-selected sender: retain the application's already configured
        // system identity, never an employee's From address.
        $email->from(new Address($config['from'], $config['name']))->subject(self::SUBJECT);
        $email->getHeaders()->remove('X-RailTime-Native-Preparation');
        $email->getHeaders()->addTextHeader('X-RailTime-Native-System-Test', $operation);
        $email->getHeaders()->addIdHeader('Message-ID', 'native-system-'.$operation.'@'.substr(strrchr($config['from'], '@'), 1));
        $mime = $email->toString();
        if ($source['hash'] !== $this->source()['hash'] || $config['hash'] !== $this->configuration()['hash']) {
            self::fail('SOURCE_CHANGED');
        }
        $record = ['schema' => 1, 'operation' => $operation, 'state' => 'prepared', 'sourceHash' => $source['hash'], 'configHash' => $config['hash'],
            'mimeHash' => hash('sha256', $mime), 'bytes' => strlen($mime), 'sender' => $config['from'], 'recipient' => NativeSignatureMimeComposer::MAILBOX];
        $record['bindingMac'] = hash_hmac('sha256', json_encode($record, JSON_THROW_ON_ERROR), (string) config('app.key'));
        self::exclusive($directory.'/message.eml', $mime);
        self::exclusive($directory.'/record.json', json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return $record;
    }

    public function send(string $operation): array
    {
        $config = $this->configuration();
        $source = $this->source();
        $directory = $this->directory($operation);
        $record = $this->read($directory, $source['hash'], $config['hash']);
        $transport = app('mail.manager')->mailer($config['mailer'])->getSymfonyTransport();
        if (! $transport instanceof EsmtpTransport || ! $transport->getStream() instanceof SocketStream
            || $transport->getStream()->getHost() !== $config['smtp']['host'] || $transport->getStream()->getPort() !== (int) $config['smtp']['port']
            || $transport->getUsername() !== $config['smtp']['username']) {
            self::fail('TRANSPORT_INVALID');
        }
        $streamOptions = $transport->getStream()->getStreamOptions();
        if (! self::safeSslOptions($streamOptions['ssl'] ?? [])) {
            self::fail('TRANSPORT_INVALID');
        }
        $transport->getStream()->setStreamOptions(array_replace_recursive($streamOptions, ['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false]]));
        $transport->setRequireTls(true);
        $transport->getStream()->setTimeout(20);
        // The durable exclusive claim is retained on every outcome, including
        // exceptions and process termination. No retry or claim reset exists.
        self::exclusive($directory.'/send.claim', json_encode(['mimeHash' => $record['mimeHash'], 'operation' => $operation], JSON_THROW_ON_ERROR));
        try {
            if ($source['hash'] !== $this->source()['hash'] || $config['hash'] !== $this->configuration()['hash']) {
                self::fail('SOURCE_CHANGED');
            }
            $mime = $this->readMime($directory, $record);
            $sent = $transport->send(new RawMessage($mime), new Envelope(new Address($config['from']), [new Address(NativeSignatureMimeComposer::MAILBOX)]));
            if ($sent === null) {
                self::fail('UNCONFIRMED');
            }
            $result = ['state' => 'submitted', 'mimeHash' => $record['mimeHash']];
            self::exclusive($directory.'/result.json', json_encode($result, JSON_THROW_ON_ERROR));

            return $result + ['operation' => $operation];
        } catch (Throwable) {
            if (! file_exists($directory.'/result.json')) {
                try {
                    self::exclusive($directory.'/result.json', json_encode(['state' => 'uncertain', 'mimeHash' => $record['mimeHash']], JSON_THROW_ON_ERROR));
                } catch (Throwable) {
                    // The already durable send.claim still prohibits another attempt.
                }
            }
            self::fail('UNCERTAIN');
        }
    }

    private function configuration(): array
    {
        $mailer = config('mail.default');
        $smtp = is_string($mailer) && preg_match('/\A[a-z0-9_-]{1,40}\z/', $mailer) ? config('mail.mailers.'.$mailer) : null;
        $from = config('mail.from.address');
        $name = config('mail.from.name');
        $key = config('app.key');
        $ssl = is_array($smtp) ? ($smtp['stream']['ssl'] ?? []) : [];
        if (! is_array($smtp) || ($smtp['transport'] ?? null) !== 'smtp' || ! empty($smtp['url'])
            || ! is_string($smtp['host'] ?? null) || ! preg_match('/\A[A-Za-z0-9][A-Za-z0-9.\-]{0,252}\z/', $smtp['host'])
            || ! in_array((int) ($smtp['port'] ?? 0), [465, 587], true)
            || ! in_array($smtp['encryption'] ?? '', ['ssl', 'tls'], true)
            || ! is_array($smtp['stream'] ?? []) || ! self::safeSslOptions($ssl) || ! self::safeSslOptions($smtp)
            || (! empty($smtp['local_domain']) && (! is_string($smtp['local_domain']) || ! preg_match('/\A[A-Za-z0-9][A-Za-z0-9.\-]{0,252}\z/', $smtp['local_domain'])))
            || ! is_string($smtp['username'] ?? null) || trim($smtp['username']) === '' || preg_match('/[\x00-\x20\x7f]/', $smtp['username'])
            || ! is_string($smtp['password'] ?? null) || $smtp['password'] === '' || str_contains($smtp['password'], "\0")
            || ! is_string($from) || ! filter_var($from, FILTER_VALIDATE_EMAIL) || preg_match('/[\x00-\x20\x7f]/', $from)
            || ! is_string($name) || trim($name) === '' || mb_strlen($name) > 120 || ! mb_check_encoding($name, 'UTF-8') || preg_match('/[\x00-\x1f\x7f]/', $name)
            || ! is_string($key) || $key === '') {
            self::fail('CONFIG_INVALID');
        }
        $public = ['mailer' => $mailer, 'from' => $from, 'name' => $name, 'smtp' => $smtp];

        return $public + ['hash' => hash_hmac('sha256', json_encode($public, JSON_THROW_ON_ERROR), $key)];
    }

    private function source(): array
    {
        $generation = $this->generation();
        $templates = MailDocument::query()->published()->where('kind', 'template')->where('is_outlook_template', false)->get();
        $signatures = MailDocument::query()->published()->where('kind', 'signature')->get();
        if ($templates->count() !== 1 || $signatures->count() !== 1 || SignaturePersonalColumns::requested($signatures[0]->published_html)
            || OutlookStaticLinkedTestProfiles::signatureProfile(['html' => $signatures[0]->published_html]) !== OutlookLinkedAnimatedDelivery::PROFILE) {
            self::fail('SOURCE_INVALID');
        }
        $store = app(PublishedMailDocumentSnapshotStore::class);
        foreach ([MailDocumentKind::Template, MailDocumentKind::Signature] as $kind) {
            if ($store->hasExplicitSnapshot($kind)) {
                self::fail('SOURCE_INVALID');
            }
        }
        $snapshot = [$store->freshSnapshot(MailDocumentKind::Template), $store->freshSnapshot(MailDocumentKind::Signature)];
        $expected = array_map(static fn (MailDocument $document): array => ['html' => trim($document->published_html), 'css' => trim((string) $document->published_css)], [$templates[0], $signatures[0]]);
        if ($snapshot !== $expected) {
            self::fail('SOURCE_INVALID');
        }
        $company = CompanyData::all(true);
        $compiled = EmailTemplateBuilder::buildSystemMailHtml(new HtmlString('<p>Bound system source.</p>'));
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $dom->loadHTML('<?xml encoding="UTF-8">'.$compiled, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $xpath = new DOMXPath($dom);
        $cards = $xpath->query('//table[contains(concat(" ",normalize-space(@class)," ")," rt-v17-dispatch-block ")]');
        if ($cards->length !== 1) {
            self::fail('SOURCE_INVALID');
        }
        $routes = [];
        foreach ($xpath->query('.//a[@href]', $cards->item(0)) as $link) {
            $href = $link->getAttribute('href');
            $routes[$href] = true;
        }
        $select = static function (string $prefix) use ($routes): string {
            $found = array_values(array_filter(array_keys($routes), static fn (string $value): bool => str_starts_with($value, $prefix)));
            if (count($found) !== 1) {
                self::fail('SOURCE_INVALID');
            }

            return $found[0];
        };
        $phone = EmailTemplateBuilder::telHref($company['phone']);
        $emergency = EmailTemplateBuilder::telHref($company['emergency_phone']);
        if ($select('tel:') !== 'tel:'.$emergency || count($routes) !== 3) {
            self::fail('SOURCE_INVALID');
        }
        $hash = hash('sha256', json_encode([$templates[0]->getRawOriginal(), $signatures[0]->getRawOriginal(), $snapshot, $company, array_keys($routes), $generation], JSON_THROW_ON_ERROR));
        $legal = implode(' · ', array_filter(['Geschäftsführung: '.$company['managing_directors'], 'Registergericht: '.$company['register_court'], 'HRB '.$company['commercial_register_number'], 'USt-IdNr.: '.$company['vat_id'], $company['tax_number'] !== '' ? 'Steuernummer: '.$company['tax_number'] : '']));

        return ['hash' => $hash, 'descriptor' => ['sourceHash' => $hash, 'kind' => 'system', 'identity' => ['name' => $company['name'], 'role' => 'Systemnachricht'],
            'direct' => [['type' => 'email', 'value' => $company['email'], 'label' => $company['email']], ['type' => 'phone', 'value' => $phone, 'label' => $phone]],
            'company' => ['name' => $company['name'], 'website' => EmailTemplateBuilder::webHref($company['website']), 'websiteLabel' => EmailTemplateBuilder::webLabel($company['website']), 'street' => $company['street'], 'postalCity' => trim($company['postal_code'].' '.$company['city']), 'registration' => $legal,
                'confidentiality' => 'Diese E-Mail kann vertrauliche Informationen enthalten. Sollten Sie nicht der vorgesehene Empfänger sein, informieren Sie bitte den Absender und löschen Sie diese Nachricht.'],
            'dispatch' => ['email' => substr($select('mailto:'), 7), 'whatsapp' => $select('https://wa.me/'), 'phone' => $emergency, 'phoneLabel' => $emergency]]];
    }

    private function generation(): array
    {
        $files = [__FILE__, (new \ReflectionClass(NativeSignatureHandoffRenderer::class))->getFileName(),
            (new \ReflectionClass(NativeSignatureMimeComposer::class))->getFileName(), resource_path('mail-templates/native-signature-handoff-v1.html')];
        $hashes = [];
        foreach ($files as $path) {
            if (! is_string($path) || ! is_file($path) || is_link($path) || ! is_readable($path)) {
                self::fail('SOURCE_INVALID');
            }
            $hash = hash_file('sha256', $path);
            if (! is_string($hash)) {
                self::fail('SOURCE_INVALID');
            }
            $hashes[] = $hash;
        }

        return [NativeSignatureHandoffRenderer::REVISION, $hashes, OutlookLinkedAnimatedDelivery::fingerprint(), config('app.url')];
    }

    private function directory(string $operation): string
    {
        if (! preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $operation)) {
            self::fail('OPERATION_INVALID');
        }
        $base = storage_path('app/private/native-system-selftest');
        foreach ([storage_path(), storage_path('app'), storage_path('app/private'), $base, $base.'/'.$operation] as $path) {
            if (is_link($path) || (file_exists($path) && ! is_dir($path))) {
                self::fail('STORAGE_INVALID');
            }
            if (! is_dir($path) && ! @mkdir($path, 0700)) {
                self::fail('STORAGE_INVALID');
            }
        }

        return $base.'/'.$operation;
    }

    private function read(string $directory, string $sourceHash, string $configHash): array
    {
        $path = $directory.'/record.json';
        if (! is_file($path) || is_link($path) || filesize($path) > 4096) {
            self::fail('PREPARE_REQUIRED');
        }
        $record = json_decode(file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
        if (! is_array($record)) {
            self::fail('MIME_INVALID');
        }
        $sealed = $record;
        unset($sealed['bindingMac']);
        if (($record['schema'] ?? null) !== 1 || ($record['state'] ?? '') !== 'prepared' || ($record['operation'] ?? '') !== basename($directory)
            || ! is_string($record['bindingMac'] ?? null) || ! hash_equals(hash_hmac('sha256', json_encode($sealed, JSON_THROW_ON_ERROR), (string) config('app.key')), $record['bindingMac'])
            || ($record['sourceHash'] ?? '') !== $sourceHash || ($record['configHash'] ?? '') !== $configHash
            || ($record['recipient'] ?? '') !== NativeSignatureMimeComposer::MAILBOX) {
            self::fail('SOURCE_CHANGED');
        }
        $this->readMime($directory, $record);
        if (file_exists($directory.'/send.claim')) {
            self::fail('ALREADY_CLAIMED');
        }

        return $record;
    }

    private function readMime(string $directory, array $record): string
    {
        $path = $directory.'/message.eml';
        if (! is_file($path) || is_link($path) || filesize($path) > 500000) {
            self::fail('MIME_INVALID');
        }
        $mime = file_get_contents($path);
        if (! is_string($mime) || strlen($mime) !== ($record['bytes'] ?? null) || ! hash_equals((string) ($record['mimeHash'] ?? ''), hash('sha256', $mime))) {
            self::fail('MIME_INVALID');
        }

        return $mime;
    }

    private static function exclusive(string $path, string $bytes): void
    {
        $handle = @fopen($path, 'x+b');
        if ($handle === false) {
            self::fail('ALREADY_CLAIMED');
        }
        try {
            if (! @chmod($path, 0600) || fwrite($handle, $bytes) !== strlen($bytes) || ! fflush($handle) || ! fsync($handle)) {
                self::fail('STORAGE_INVALID');
            }
        } finally {
            fclose($handle);
        }
    }

    private static function fail(string $code): never
    {
        throw new RuntimeException('NATIVE_SYSTEM_TEST_'.$code);
    }

    private static function safeSslOptions(mixed $options): bool
    {
        return is_array($options)
            && (! array_key_exists('verify_peer', $options) || $options['verify_peer'] === true)
            && (! array_key_exists('verify_peer_name', $options) || $options['verify_peer_name'] === true)
            && (! array_key_exists('allow_self_signed', $options) || $options['allow_self_signed'] === false);
    }
}
