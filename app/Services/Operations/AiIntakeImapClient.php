<?php

namespace App\Services\Operations;

use App\Support\Operations\AiDispositionSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Webklex\PHPIMAP\Attribute;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\IMAP;
use Webklex\PHPIMAP\Message;

/** The mailbox stays under EXAMINE; parsing detached MIME cannot alter flags. */
class AiIntakeImapClient
{
    private const MAX_MESSAGE_BYTES = 25 * 1024 * 1024;

    private const MAX_HEADER_BYTES = 262144;

    protected function connect(array $settings): Client
    {
        $client = (new ClientManager)->make([
            'host' => $settings['imap_host'], 'port' => (int) $settings['imap_port'],
            'encryption' => $settings['imap_encryption'], 'validate_cert' => true,
            'username' => $settings['imap_username'], 'password' => $settings['imap_password'],
            'protocol' => 'imap', 'authentication' => null, 'timeout' => 30,
        ]);
        $client->connect();

        return $client;
    }

    private function withMailbox(array $settings, callable $callback, ?string $folder = null): mixed
    {
        $folder = $this->validFolder($folder ?? $settings['imap_folder']);
        $client = $this->connect($settings);
        try {
            $snapshot = $client->checkFolder($folder);
            $client->setActiveFolder($folder);

            return $callback($client, $snapshot);
        } finally {
            $client->disconnect();
        }
    }

    /** List every matching UID, then fetch headers and flags for this page only. */
    public function browse(array $settings, string $folder, int $page = 1, int $perPage = 20, string $search = ''): array
    {
        $folder = $this->validFolder($folder);
        $perPage = max(1, min(50, $perPage));
        $search = trim(mb_substr($search, 0, 200));
        if (preg_match('/[\x00-\x1f\x7f]/', $search)) {
            throw new RuntimeException('mailbox_search_invalid');
        }

        return $this->withMailbox($settings, function (Client $client, array $snapshot) use ($folder, $page, $perPage, $search): array {
            $validity = $this->validUid((int) ($snapshot['uidvalidity'] ?? 0));
            $criteria = ['ALL'];
            if ($search !== '') {
                $quoted = '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $search).'"';
                $criteria = ['CHARSET UTF-8', 'OR SUBJECT '.$quoted, 'OR FROM '.$quoted.' TO '.$quoted];
            }
            $connection = $client->getConnection();
            $found = (array) $connection->search($criteria, IMAP::ST_UID)->validatedData();
            $uids = array_values(array_unique(array_filter(array_map('intval', $found), fn (int $uid): bool => $uid > 0 && $uid <= 4294967295)));
            rsort($uids, SORT_NUMERIC);
            $total = count($uids);
            $page = max(1, min(max(1, (int) ceil($total / $perPage)), $page));
            $selected = array_slice($uids, ($page - 1) * $perPage, $perPage);
            $messages = [];
            if ($selected !== []) {
                // A partial PEEK bounds each literal at the server, including hostile headers.
                // Two fetch items also preserve the BODY response key in Webklex's parser.
                $summaries = (array) $connection->fetch(['BODY.PEEK[HEADER]<0.'.self::MAX_HEADER_BYTES.'>', 'FLAGS'], $selected, null, IMAP::ST_UID)->validatedData();
                foreach ($selected as $uid) {
                    // A concurrent expunge must never create a phantom row.
                    $summary = (array) ($summaries[$uid] ?? []);
                    $raw = '';
                    foreach ($summary as $key => $value) {
                        if (is_string($key) && str_starts_with(strtoupper($key), 'BODY[HEADER]')) {
                            $raw = (string) $value;
                            break;
                        }
                    }
                    if ($raw === '') {
                        continue;
                    }
                    $message = Message::fromString(substr($raw, 0, self::MAX_HEADER_BYTES)."\r\n\r\n");
                    $messages[] = array_replace($this->messageSummary($message), [
                        'uid' => $uid,
                        'seen' => in_array('\\seen', array_map('strtolower', (array) ($summary['FLAGS'] ?? [])), true),
                    ]);
                }
            }

            return ['folder' => $folder, 'uid_validity' => $validity, 'total' => $total,
                'page' => $page, 'per_page' => $perPage, 'messages' => $messages];
        }, $folder);
    }

    /** Only a selectable folder actually advertised by this server is eligible. */
    public function sentFolder(array $settings): ?string
    {
        $client = $this->connect($settings);
        try {
            $folders = (array) $client->getConnection()->folders('', '*')->validatedData();
            $fallback = null;
            foreach ($folders as $path => $info) {
                if (! is_string($path) || ! is_array($info)) {
                    continue;
                }
                $flags = array_map('strtolower', (array) ($info['flags'] ?? []));
                if (in_array('\\noselect', $flags, true) || in_array('\\nonexistent', $flags, true)) {
                    continue;
                }
                $path = $this->validFolder($path);
                if (in_array('\\sent', $flags, true)) {
                    return $path;
                }
                $delimiter = (string) ($info['delimiter'] ?? '');
                $parts = $delimiter !== '' ? explode($delimiter, $path) : [$path];
                $name = mb_strtolower((string) end($parts));
                if ($fallback === null && in_array($name, ['sent', 'sent items', 'sent messages', 'sent mail', 'gesendet', 'gesendete elemente', 'gesendete objekte', 'gesendete nachrichten', 'gesendete mails'], true)) {
                    $fallback = $path;
                }
            }

            return $fallback;
        } finally {
            $client->disconnect();
        }
    }

    /** Detached MIME display never stores attachments or changes mailbox flags. */
    public function readMessage(array $settings, string $folder, int $uid, int $expectedValidity): array
    {
        $this->validUid($uid);
        $this->validUid($expectedValidity);
        $folder = $this->validFolder($folder);

        return $this->withMailbox($settings, function (Client $client, array $snapshot) use ($folder, $uid, $expectedValidity): array {
            $this->assertValidity($snapshot, $expectedValidity);
            $connection = $client->getConnection();
            $sizes = (array) $connection->sizes([$uid], IMAP::ST_UID)->validatedData();
            $size = (int) ($sizes[$uid] ?? 0);
            if ($size < 1) {
                throw new RuntimeException('mailbox_message_unavailable');
            }
            if ($size > self::MAX_MESSAGE_BYTES) {
                throw new RuntimeException('mailbox_message_size');
            }
            $literal = (array) $connection->fetch(['BODY.PEEK[]<0.'.(self::MAX_MESSAGE_BYTES + 1).'>', 'RFC822.SIZE'], [$uid], null, IMAP::ST_UID)->validatedData();
            $raw = '';
            foreach ((array) ($literal[$uid] ?? []) as $key => $value) {
                if (is_string($key) && str_starts_with(strtoupper($key), 'BODY[]')) {
                    $raw = (string) $value;
                    break;
                }
            }
            if ($raw === '') {
                throw new RuntimeException('mailbox_message_unavailable');
            }
            if (strlen($raw) > self::MAX_MESSAGE_BYTES) {
                throw new RuntimeException('mailbox_message_size');
            }
            $message = Message::fromString($raw);
            $attachments = [];
            foreach ($message->getAttachments() as $attachment) {
                $name = basename(str_replace('\\', '/', (string) $attachment->getName()));
                $attachments[] = ['name' => $this->plainLabel($name, 180), 'size' => max(0, (int) $attachment->getSize())];
            }

            return array_replace($this->messageSummary($message), [
                'uid' => $uid, 'uid_validity' => $expectedValidity, 'folder' => $folder,
                'text' => $this->messageText($message), 'attachments' => $attachments,
            ]);
        }, $folder);
    }

    private function validFolder(string $folder): string
    {
        if ($folder === '' || strlen($folder) > 512 || preg_match('/[\x00-\x1f\x7f]/', $folder)) {
            throw new RuntimeException('mailbox_folder_invalid');
        }

        return $folder;
    }

    private function validUid(int $uid): int
    {
        if ($uid < 1 || $uid > 4294967295) {
            throw new RuntimeException('mailbox_uid_invalid');
        }

        return $uid;
    }

    private function assertValidity(array $snapshot, int $expectedValidity): void
    {
        $validity = $this->validUid((int) ($snapshot['uidvalidity'] ?? 0));
        if ($validity !== $expectedValidity) {
            throw new RuntimeException('mailbox_uid_validity_changed');
        }
    }

    private function messageSummary(Message $message): array
    {
        return ['subject' => $this->plainLabel((string) $message->getSubject(), 180),
            'from' => $this->addresses($message->getFrom()), 'to' => $this->addresses($message->getTo()),
            'date' => $this->plainLabel((string) $message->getDate(), 100),
            'message_id' => $this->plainLabel((string) $message->getMessageId(), 998)];
    }

    private function addresses(Attribute $addresses): string
    {
        $result = [];
        foreach ($addresses->all() as $address) {
            $mail = $this->plainLabel((string) $address->mail, 254);
            $name = $this->plainLabel((string) ($address->personal ?? ''), 100);
            $result[] = $name !== '' ? $name.' <'.$mail.'>' : $mail;
            if (count($result) >= 20) {
                break;
            }
        }

        return mb_substr(implode(', ', $result), 0, 2000);
    }

    private function plainLabel(string $text, int $limit): string
    {
        return mb_substr(trim(preg_replace('/[\x00-\x1f\x7f]/u', ' ', mb_convert_encoding($text, 'UTF-8', 'UTF-8')) ?? ''), 0, $limit);
    }

    private function messageText(Message $message): string
    {
        $text = trim($message->getTextBody());
        if ($text === '') {
            $html = preg_replace('#<(script|style|iframe|object|embed)\b[^>]*>.*?</\1\s*>#is', '', $message->getHTMLBody()) ?? '';
            $html = preg_replace('#<(br\s*/?|/p|/div|/li|/tr|/h[1-6])\s*>#i', "\n", $html) ?? '';
            $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        $text = preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u', '', $text) ?? '';

        return mb_substr(trim($text), 0, 60000);
    }

    public function snapshot(array $settings): array
    {
        return $this->withMailbox($settings, fn (Client $client, array $info): array => [
            'uid_validity' => (int) ($info['uidvalidity'] ?? 0), 'uid_next' => (int) ($info['uidnext'] ?? 0),
            'messages' => (int) ($info['exists'] ?? 0),
        ]);
    }

    public function uidsAfter(array $settings, int $after, int $limit): array
    {
        return $this->withMailbox($settings, function (Client $client, array $snapshot) use ($after, $limit): array {
            $end = min((int) ($snapshot['uidnext'] ?? 1) - 1, $after + max(100, $limit * 10));
            if ($end <= $after) {
                return ['uids' => [], 'scan_through' => $after];
            }
            $uids = $client->getConnection()->search(['UID '.max(1, $after + 1).':'.$end], IMAP::ST_UID)->validatedData();
            $uids = array_values(array_unique(array_filter(array_map('intval', (array) $uids), fn ($uid) => $uid > $after && $uid <= $end)));
            sort($uids, SORT_NUMERIC);

            return ['uids' => array_slice($uids, 0, $limit), 'scan_through' => count($uids) > $limit ? $uids[$limit - 1] : $end];
        });
    }

    public function recent(array $settings, int $limit): array
    {
        return $this->withMailbox($settings, function (Client $client, array $snapshot) use ($limit): array {
            $uids = array_map('intval', $client->getConnection()->search(['UID '.max(1, (int) ($snapshot['uidnext'] ?? 1) - 1000).':*'], IMAP::ST_UID)->validatedData());
            rsort($uids, SORT_NUMERIC);
            $result = [];
            foreach (array_slice($uids, 0, $limit) as $uid) {
                $raw = $client->getConnection()->headers([$uid], 'RFC822', IMAP::ST_UID)->validatedData();
                $message = Message::fromString(((string) ($raw[$uid] ?? ''))."\r\n\r\n");
                $result[] = ['uid' => $uid, 'subject' => mb_substr((string) $message->getSubject(), 0, 180), 'date' => mb_substr((string) $message->getDate(), 0, 100)];
            }

            return ['uid_validity' => (int) ($snapshot['uidvalidity'] ?? 0), 'messages' => $result];
        });
    }

    public function fetch(array $settings, int $uid, int $expectedValidity): array
    {
        $this->validUid($uid);
        $this->validUid($expectedValidity);

        return $this->withMailbox($settings, function (Client $client, array $snapshot) use ($settings, $uid, $expectedValidity): array {
            $this->assertValidity($snapshot, $expectedValidity);
            $sizes = $client->getConnection()->sizes([$uid], IMAP::ST_UID)->validatedData();
            if ((int) ($sizes[$uid] ?? 0) < 1) {
                throw new RuntimeException('mailbox_message_unavailable');
            }
            if ((int) $sizes[$uid] > self::MAX_MESSAGE_BYTES) {
                $headers = $client->getConnection()->headers([$uid], 'RFC822', IMAP::ST_UID)->validatedData();
                $header = (string) ($headers[$uid] ?? '');
                if ($header === '') {
                    throw new RuntimeException('mailbox_message_unavailable');
                }
                $source = $this->headerFallback(substr($header, 0, self::MAX_HEADER_BYTES), $settings, $uid, $expectedValidity);

                return array_replace($source, ['source_error' => 'mailbox_message_size', 'raw_archive_complete' => false, 'declared_size' => (int) ($sizes[$uid] ?? 0)]);
            }
            $literal = $client->getConnection()->fetch(['RFC822'], [$uid], null, IMAP::ST_UID)->validatedData();
            $raw = (string) ($literal[$uid] ?? '');
            if ($raw === '') {
                throw new RuntimeException('mailbox_message_unavailable');
            }
            if (strlen($raw) > self::MAX_MESSAGE_BYTES) {
                throw new RuntimeException('mailbox_message_size');
            }
            try {
                return $this->parse($raw, $settings, $uid, $expectedValidity);
            } catch (\Throwable $error) {
                $separator = strpos($raw, "\r\n\r\n");
                $source = $this->headerFallback(substr($raw, 0, $separator === false ? min(strlen($raw), 262144) : $separator + 4), $settings, $uid, $expectedValidity);
                $source['raw'] = $raw;
                $source['source_error'] = in_array($error->getMessage(), ['mailbox_attachment_limit', 'mailbox_attachment_size'], true) ? $error->getMessage() : 'mailbox_mime_parse_failed';
                $source['raw_archive_complete'] = true;

                return $source;
            }
        });
    }

    private function headerFallback(string $raw, array $settings, int $uid, int $validity): array
    {
        try {
            return $this->parse($raw, $settings, $uid, $validity);
        } catch (\Throwable) {
            return ['mailbox_id' => AiDispositionSettings::mailboxId($settings), 'uid_validity' => $validity, 'uid' => $uid,
                'from' => '', 'reply_to' => '', 'message_id' => null, 'in_reply_to' => null, 'references' => [],
                'subject' => 'E-Mail benötigt manuelle Prüfung', 'text' => '', 'raw' => $raw, 'attachments' => [], '_cleanup' => [],
                'source_error' => 'mailbox_mime_parse_failed', 'raw_archive_complete' => true];
        }
    }

    public function parse(string $raw, array $settings, int $uid, int $uidValidity): array
    {
        $this->validUid($uid);
        $this->validUid($uidValidity);
        $message = Message::fromString($raw);
        $from = $message->getFrom();
        $replyTo = $message->getReplyTo();
        $sender = $from->count() === 1 ? strtolower(trim((string) $from->first()->mail)) : '';
        $reply = $replyTo->count() === 1 ? strtolower(trim((string) $replyTo->first()->mail)) : ($replyTo->count() > 1 ? 'ambiguous' : '');
        $text = $this->messageText($message);
        $attachments = [];
        $cleanup = [];
        $total = 0;
        try {
            foreach ($message->getAttachments() as $attachment) {
                if (count($attachments) >= (int) $settings['max_attachment_count']) {
                    throw new RuntimeException('mailbox_attachment_limit');
                }
                $content = (string) $attachment->getContent();
                $total += strlen($content);
                if ($total > (int) $settings['max_total_kilobytes'] * 1024) {
                    throw new RuntimeException('mailbox_attachment_size');
                }
                $path = 'ai-disposition/transport/'.Str::uuid();
                if (! Storage::disk('local')->put($path, $content)) {
                    throw new RuntimeException('mailbox_attachment_storage');
                }
                $cleanup[] = $path;
                $name = basename(str_replace('\\', '/', (string) $attachment->getName()));
                $attachments[] = new UploadedFile(Storage::disk('local')->path($path), mb_substr($name, 0, 180), null, null, true);
            }
        } catch (\Throwable $error) {
            Storage::disk('local')->delete($cleanup);
            throw $error;
        }
        $header = $message->getHeader();
        $auto = strtolower(trim((string) $header->get('auto_submitted')));
        $precedence = strtolower(trim((string) $header->get('precedence')));

        $source = ['mailbox_id' => AiDispositionSettings::mailboxId($settings), 'uid_validity' => $uidValidity, 'uid' => $uid,
            'from' => $sender, 'reply_to' => $reply, 'message_id' => (string) $message->getMessageId(),
            'in_reply_to' => (string) $header->get('in_reply_to'), 'references' => (string) $header->get('references'),
            'subject' => mb_substr((string) $message->getSubject(), 0, 180), 'text' => mb_substr(trim($text), 0, 60000), 'raw' => $raw,
            'attachments' => $attachments, '_cleanup' => $cleanup,
            'auto_submitted' => $auto, 'precedence' => $precedence, 'list_id' => (string) $header->get('list_id'),
            'is_bounce' => $sender === '' || str_contains(strtolower($sender), 'mailer-daemon@') || str_contains(strtolower($sender), 'postmaster@')];
        if ($from->count() !== 1 || $replyTo->count() > 1) {
            $source['source_error'] = 'mailbox_sender_ambiguous';
        }

        return $source;
    }
}
