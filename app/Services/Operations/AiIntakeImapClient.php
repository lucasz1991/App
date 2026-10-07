<?php

namespace App\Services\Operations;

use App\Support\Operations\AiDispositionSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\IMAP;
use Webklex\PHPIMAP\Message;

/** The mailbox stays under EXAMINE; parsing detached MIME cannot alter flags. */
class AiIntakeImapClient
{
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

    private function withMailbox(array $settings, callable $callback): mixed
    {
        $client = $this->connect($settings);
        try {
            $snapshot = $client->checkFolder($settings['imap_folder']);
            $client->setActiveFolder($settings['imap_folder']);

            return $callback($client, $snapshot);
        } finally {
            $client->disconnect();
        }
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
        return $this->withMailbox($settings, function (Client $client, array $snapshot) use ($settings, $uid, $expectedValidity): array {
            if ((int) ($snapshot['uidvalidity'] ?? 0) !== $expectedValidity) {
                throw new RuntimeException('mailbox_uid_validity_changed');
            }
            $sizes = $client->getConnection()->sizes([$uid], IMAP::ST_UID)->validatedData();
            $headers = $client->getConnection()->headers([$uid], 'RFC822', IMAP::ST_UID)->validatedData();
            if ((int) ($sizes[$uid] ?? 0) < 1 || (int) $sizes[$uid] > 25 * 1024 * 1024) {
                $source = $this->parse((string) ($headers[$uid] ?? '')."\r\n\r\n", $settings, $uid, $expectedValidity);

                return $source + ['source_error' => 'mailbox_message_size', 'raw_archive_complete' => false, 'declared_size' => (int) ($sizes[$uid] ?? 0)];
            }
            $body = $client->getConnection()->content([$uid], 'RFC822', IMAP::ST_UID)->validatedData();
            $raw = (string) ($headers[$uid] ?? '')."\r\n\r\n".(string) ($body[$uid] ?? '');
            try {
                return $this->parse($raw, $settings, $uid, $expectedValidity);
            } catch (\Throwable $error) {
                $source = $this->parse((string) ($headers[$uid] ?? '')."\r\n\r\n", $settings, $uid, $expectedValidity);
                $source['raw'] = $raw;
                $source['source_error'] = in_array($error->getMessage(), ['mailbox_attachment_limit', 'mailbox_attachment_size'], true) ? $error->getMessage() : 'mailbox_mime_parse_failed';
                $source['raw_archive_complete'] = true;

                return $source;
            }
        });
    }

    public function parse(string $raw, array $settings, int $uid, int $uidValidity): array
    {
        $message = Message::fromString($raw);
        $from = $message->getFrom();
        $replyTo = $message->getReplyTo();
        $sender = $from->count() === 1 ? strtolower(trim((string) $from->first()->mail)) : '';
        $reply = $replyTo->count() === 1 ? strtolower(trim((string) $replyTo->first()->mail)) : ($replyTo->count() > 1 ? 'ambiguous' : '');
        $text = trim($message->getTextBody());
        if ($text === '') {
            $text = html_entity_decode(strip_tags(preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $message->getHTMLBody())), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
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
