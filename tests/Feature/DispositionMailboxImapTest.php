<?php

namespace Tests\Feature;

use App\Services\Operations\AiIntakeImapClient;
use App\Support\Operations\AiDispositionSettings;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Config;
use Webklex\PHPIMAP\Connection\Protocols\ImapProtocol;
use Webklex\PHPIMAP\Connection\Protocols\ProtocolInterface;
use Webklex\PHPIMAP\Connection\Protocols\Response;
use Webklex\PHPIMAP\IMAP;

class DispositionMailboxImapTest extends TestCase
{
    public function test_complete_uid_inventory_pages_old_messages_and_batches_headers_without_body_fetches(): void
    {
        [$imap, $protocol] = $this->transport('INBOX', 42);
        $protocol->shouldReceive('search')->with(['ALL'], IMAP::ST_UID)->once()
            ->andReturn($this->response([2, 10, 1024, 2000, 3000, 2, 0]));
        $protocol->shouldReceive('fetch')->with(['BODY.PEEK[HEADER]<0.262144>', 'FLAGS'], [10, 2], null, IMAP::ST_UID)->once()
            ->andReturn($this->response([
                10 => ['BODY[HEADER]<0>' => $this->header('Older message'), 'FLAGS' => ['\\Seen']],
                2 => ['BODY[HEADER]<0>' => $this->header('Oldest message'), 'FLAGS' => []],
            ]));

        $result = $imap->browse(AiDispositionSettings::DEFAULTS, 'INBOX', 2, 3);

        $this->assertSame(5, $result['total']);
        $this->assertSame(2, $result['page']);
        $this->assertSame(3, $result['per_page']);
        $this->assertSame(42, $result['uid_validity']);
        $this->assertSame([10, 2], array_column($result['messages'], 'uid'));
        $this->assertSame([true, false], array_column($result['messages'], 'seen'));
        $this->assertSame('Older message', $result['messages'][0]['subject']);
        $this->assertSame('Customer <customer@example.test>', $result['messages'][0]['from']);
        $this->assertSame('Dispatch <dispatch@example.test>', $result['messages'][0]['to']);
        $this->assertArrayNotHasKey('text', $result['messages'][0]);
    }

    public function test_empty_mailbox_has_a_stable_first_page_and_no_header_request(): void
    {
        [$imap, $protocol] = $this->transport('INBOX', 42);
        $protocol->shouldReceive('search')->with(['ALL'], IMAP::ST_UID)->once()->andReturn($this->response([]));

        $this->assertSame(['folder' => 'INBOX', 'uid_validity' => 42, 'total' => 0,
            'page' => 1, 'per_page' => 50, 'messages' => []], $imap->browse(AiDispositionSettings::DEFAULTS, 'INBOX', PHP_INT_MAX, 1000));
    }

    public function test_header_search_quotes_metacharacters_without_interpolating_an_imap_command(): void
    {
        [$imap, $protocol] = $this->transport('INBOX', 42);
        $quoted = '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], 'ACME "Rail" \\').'"';
        $protocol->shouldReceive('search')->with(['CHARSET UTF-8', 'OR SUBJECT '.$quoted, 'OR FROM '.$quoted.' TO '.$quoted], IMAP::ST_UID)
            ->once()->andReturn($this->response([]));

        $this->assertSame([], $imap->browse(AiDispositionSettings::DEFAULTS, 'INBOX', 1, 20, 'ACME "Rail" \\')['messages']);
    }

    public function test_concurrently_removed_header_is_skipped_without_a_phantom_mail(): void
    {
        [$imap, $protocol] = $this->transport('INBOX', 42);
        $protocol->shouldReceive('search')->once()->andReturn($this->response([8]));
        $protocol->shouldReceive('fetch')->with(['BODY.PEEK[HEADER]<0.262144>', 'FLAGS'], [8], null, IMAP::ST_UID)->once()->andReturn($this->response([]));

        $this->assertSame([], $imap->browse(AiDispositionSettings::DEFAULTS, 'INBOX')['messages']);
    }

    public function test_special_use_sent_folder_wins_over_a_common_name_and_skips_unselectable_folders(): void
    {
        [$imap, $protocol] = $this->transport();
        $protocol->shouldReceive('folders')->with('', '*')->once()->andReturn($this->response([
            'Sent' => ['delimiter' => '/', 'flags' => []],
            'Container' => ['delimiter' => '/', 'flags' => ['\\Sent', '\\Noselect']],
            'INBOX.Archive.Outgoing' => ['delimiter' => '.', 'flags' => ['\\Sent']],
        ]));

        $this->assertSame('INBOX.Archive.Outgoing', $imap->sentFolder(AiDispositionSettings::DEFAULTS));
    }

    public function test_installed_vendor_fetch_parses_the_bounded_peek_response_with_uid_and_flags(): void
    {
        $protocol = new class(Config::make()) extends ImapProtocol
        {
            public array $request = [];

            private bool $returned = false;

            public function __destruct() {}

            public function sendRequest(string $command, array $tokens = [], ?string &$tag = null): Response
            {
                $tag = 'TEST';
                $this->request = [$command, $tokens];

                return Response::empty()->setCanBeEmpty(true);
            }

            public function readLine(Response $response, array|string &$tokens = [], string $wantedTag = '*', bool $dontParse = false): bool
            {
                if ($this->returned) {
                    $tokens = ['OK'];

                    return true;
                }
                $this->returned = true;
                $tokens = ['1', 'FETCH', ['UID', '8', 'BODY[HEADER]<0>', "Subject: Existing\r\n", 'FLAGS', ['\\Seen']]];

                return false;
            }
        };

        $result = $protocol->fetch(['BODY.PEEK[HEADER]<0.262144>', 'FLAGS'], [8], null, IMAP::ST_UID)->validatedData();

        $this->assertSame(['UID FETCH', ['8:8', '(BODY.PEEK[HEADER]<0.262144> FLAGS)']], $protocol->request);
        $this->assertSame("Subject: Existing\r\n", $result[8]['BODY[HEADER]<0>']);
        $this->assertSame(['\\Seen'], $result[8]['FLAGS']);
    }

    public function test_installed_client_uses_examine_not_select_for_mailbox_reads(): void
    {
        $protocol = Mockery::mock(ProtocolInterface::class);
        $protocol->shouldReceive('connected')->andReturn(true);
        $protocol->shouldReceive('examineFolder')->with('INBOX')->once()->andReturn($this->response(['uidvalidity' => 42]));
        $protocol->shouldReceive('search')->with(['ALL'], IMAP::ST_UID)->once()->andReturn($this->response([]));
        $protocol->shouldReceive('logout')->once()->andReturn(Response::empty()->setCanBeEmpty(true));
        $protocol->shouldNotReceive('selectFolder', 'store', 'expunge');
        $client = (new ClientManager)->make(['host' => 'localhost', 'username' => 'synthetic', 'password' => '']);
        $client->connection = $protocol;
        $imap = new class($client) extends AiIntakeImapClient
        {
            public function __construct(private readonly Client $testClient) {}

            protected function connect(array $settings): Client
            {
                return $this->testClient;
            }
        };

        $this->assertSame([], $imap->browse(AiDispositionSettings::DEFAULTS, 'INBOX')['messages']);
        $this->assertNull($client->getActiveFolder());
        $client->connection = null;
    }

    public function test_sent_folder_fallback_is_an_existing_selectable_nested_folder(): void
    {
        [$imap, $protocol] = $this->transport();
        $protocol->shouldReceive('folders')->with('', '*')->once()->andReturn($this->response([
            'Sent' => ['delimiter' => '/', 'flags' => ['\\NonExistent']],
            'INBOX/Gesendet' => ['delimiter' => '/', 'flags' => []],
        ]));

        $this->assertSame('INBOX/Gesendet', $imap->sentFolder(AiDispositionSettings::DEFAULTS));
    }

    public function test_no_sent_folder_is_invented_when_server_has_no_selectable_match(): void
    {
        [$imap, $protocol] = $this->transport();
        $protocol->shouldReceive('folders')->with('', '*')->once()->andReturn($this->response([
            'INBOX' => ['delimiter' => '/', 'flags' => []],
            'Sent' => ['delimiter' => '/', 'flags' => ['\\Noselect']],
        ]));

        $this->assertNull($imap->sentFolder(AiDispositionSettings::DEFAULTS));
    }

    public function test_reading_sent_mail_examines_requested_folder_and_returns_only_plain_mime_and_metadata(): void
    {
        Storage::fake('local');
        $raw = $this->header('Visible <img onerror=evil()> title')."Content-Type: multipart/mixed; boundary=sample\r\n\r\n".
            "--sample\r\nContent-Type: text/html; charset=utf-8\r\n\r\n".
            '<p>Hello &amp; customer</p><script>evil()</script><style>body{display:none}</style>'.
            '<iframe src="https://evil.test">hidden iframe</iframe><img src="https://evil.test" onerror="evil()">'.
            '<p>Second line</p>'."\r\n--sample\r\n".
            "Content-Type: application/octet-stream; name=\"../../report.txt\"\r\n".
            "Content-Disposition: attachment; filename=\"../../report.txt\"\r\nContent-Transfer-Encoding: base64\r\n\r\n".
            base64_encode('sample file')."\r\n--sample--\r\n";
        [$imap, $protocol] = $this->transport('INBOX.Gesendet', 42);
        $protocol->shouldReceive('sizes')->with([8], IMAP::ST_UID)->once()->andReturn($this->response([8 => strlen($raw)]));
        $protocol->shouldReceive('fetch')->with(['BODY.PEEK[]<0.26214401>', 'RFC822.SIZE'], [8], null, IMAP::ST_UID)->once()
            ->andReturn($this->response([8 => ['BODY[]<0>' => $raw, 'RFC822.SIZE' => strlen($raw)]]));

        $result = $imap->readMessage(AiDispositionSettings::DEFAULTS, 'INBOX.Gesendet', 8, 42);

        $this->assertSame('INBOX.Gesendet', $result['folder']);
        $this->assertSame(42, $result['uid_validity']);
        $this->assertSame('Hello & customer'."\n".'Second line', $result['text']);
        $this->assertSame('report.txt', $result['attachments'][0]['name']);
        $this->assertGreaterThan(0, $result['attachments'][0]['size']);
        $this->assertArrayNotHasKey('raw', $result);
        $this->assertArrayNotHasKey('html', $result);
        $this->assertArrayNotHasKey('content', $result['attachments'][0]);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_plain_text_mime_retains_readable_text_and_caps_body_without_html_execution(): void
    {
        $raw = $this->header('Text')."Content-Type: text/plain; charset=utf-8\r\n\r\n".'<script>literal text</script>'.str_repeat('a', 65000)."\0";
        [$imap, $protocol] = $this->transport('INBOX', 42);
        $protocol->shouldReceive('sizes')->once()->andReturn($this->response([8 => strlen($raw)]));
        $protocol->shouldReceive('fetch')->with(['BODY.PEEK[]<0.26214401>', 'RFC822.SIZE'], [8], null, IMAP::ST_UID)->once()
            ->andReturn($this->response([8 => ['BODY[]<0>' => $raw, 'RFC822.SIZE' => strlen($raw)]]));

        $result = $imap->readMessage(AiDispositionSettings::DEFAULTS, 'INBOX', 8, 42);

        $this->assertSame(60000, mb_strlen($result['text']));
        $this->assertStringStartsWith('<script>literal text</script>', $result['text']);
        $this->assertStringNotContainsString("\0", $result['text']);
    }

    public function test_changed_uid_validity_blocks_message_read_before_sizes_or_body_fetch(): void
    {
        [$imap] = $this->transport('INBOX', 43);
        $this->expectExceptionMessage('mailbox_uid_validity_changed');

        $imap->readMessage(AiDispositionSettings::DEFAULTS, 'INBOX', 8, 42);
    }

    public function test_unknown_uid_fails_instead_of_creating_a_header_fallback_for_read_or_import(): void
    {
        foreach (['readMessage', 'fetch'] as $method) {
            [$imap, $protocol] = $this->transport('INBOX', 42);
            $protocol->shouldReceive('sizes')->with([8], IMAP::ST_UID)->once()->andReturn($this->response([]));
            try {
                if ($method === 'fetch') {
                    $imap->fetch(AiDispositionSettings::DEFAULTS, 8, 42);
                } else {
                    $imap->readMessage(AiDispositionSettings::DEFAULTS, 'INBOX', 8, 42);
                }
                $this->fail('Missing UID must fail.');
            } catch (RuntimeException $error) {
                $this->assertSame('mailbox_message_unavailable', $error->getMessage());
            }
        }
    }

    public function test_oversized_message_read_is_rejected_before_requesting_the_literal(): void
    {
        [$imap, $protocol] = $this->transport('INBOX', 42);
        $protocol->shouldReceive('sizes')->with([8], IMAP::ST_UID)->once()->andReturn($this->response([8 => 25 * 1024 * 1024 + 1]));
        $this->expectExceptionMessage('mailbox_message_size');

        $imap->readMessage(AiDispositionSettings::DEFAULTS, 'INBOX', 8, 42);
    }

    public function test_literal_size_is_checked_even_if_the_server_underreports_the_message_size(): void
    {
        [$imap, $protocol] = $this->transport('INBOX', 42);
        $protocol->shouldReceive('sizes')->with([8], IMAP::ST_UID)->once()->andReturn($this->response([8 => 100]));
        $protocol->shouldReceive('fetch')->with(['BODY.PEEK[]<0.26214401>', 'RFC822.SIZE'], [8], null, IMAP::ST_UID)->once()
            ->andReturn($this->response([8 => ['BODY[]<0>' => str_repeat('x', 25 * 1024 * 1024 + 1)]]));
        $this->expectExceptionMessage('mailbox_message_size');

        $imap->readMessage(AiDispositionSettings::DEFAULTS, 'INBOX', 8, 42);
    }

    public function test_missing_uid_validity_is_rejected_instead_of_binding_zero_to_a_mail(): void
    {
        [$imap] = $this->transport('INBOX', 0);
        $this->expectExceptionMessage('mailbox_uid_invalid');

        $imap->browse(AiDispositionSettings::DEFAULTS, 'INBOX');
    }

    public function test_invalid_uid_folder_and_search_inputs_fail_before_connecting(): void
    {
        $imap = Mockery::mock(AiIntakeImapClient::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $imap->shouldNotReceive('connect');
        foreach ([
            fn () => $imap->readMessage(AiDispositionSettings::DEFAULTS, 'INBOX', 0, 42),
            fn () => $imap->readMessage(AiDispositionSettings::DEFAULTS, 'INBOX', 8, 0),
            fn () => $imap->fetch(AiDispositionSettings::DEFAULTS, -1, 42),
            fn () => $imap->browse(AiDispositionSettings::DEFAULTS, "INBOX\r\nLOGOUT"),
            fn () => $imap->browse(AiDispositionSettings::DEFAULTS, 'INBOX', 1, 20, "test\r\nLOGOUT"),
        ] as $action) {
            try {
                $action();
                $this->fail('Invalid input must fail.');
            } catch (RuntimeException $error) {
                $this->assertStringStartsWith('mailbox_', $error->getMessage());
            }
        }
    }

    private function header(string $subject): string
    {
        return "From: Customer <customer@example.test>\r\nTo: Dispatch <dispatch@example.test>\r\nSubject: ".$subject.
            "\r\nDate: Fri, 9 Oct 2026 08:00:00 +0200\r\nMessage-ID: <sample@example.test>\r\n";
    }

    private function response(array $value): Response
    {
        $response = Mockery::mock(Response::class);
        $response->shouldReceive('validatedData')->once()->andReturn($value);

        return $response;
    }

    private function transport(?string $folder = null, int $validity = 42): array
    {
        $protocol = Mockery::mock(ProtocolInterface::class);
        $protocol->shouldNotReceive('selectFolder', 'store', 'expunge', 'deleteMessage', 'appendMessage');
        $client = Mockery::mock(Client::class);
        if ($folder !== null) {
            // checkFolder delegates to EXAMINE in the installed public client API.
            $client->shouldReceive('checkFolder')->with($folder)->once()->andReturn(['uidvalidity' => $validity, 'uidnext' => 4000]);
            $client->shouldReceive('setActiveFolder')->with($folder)->once();
        }
        $client->shouldNotReceive('openFolder');
        $client->shouldReceive('getConnection')->andReturn($protocol);
        $client->shouldReceive('disconnect')->once();
        $imap = new class($client) extends AiIntakeImapClient
        {
            public function __construct(private readonly Client $testClient) {}

            protected function connect(array $settings): Client
            {
                return $this->testClient;
            }
        };

        return [$imap, $protocol];
    }
}
