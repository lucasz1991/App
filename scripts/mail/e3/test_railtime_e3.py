"""Local bytes/files/SQLite only: no SMTP, listeners, Exchange or production data."""
import base64
from contextlib import contextmanager
from pathlib import Path
import json
import os
import struct
import subprocess
import importlib.util
import sys
import tempfile
import unittest
from unittest.mock import patch
from email import policy as email_policy
from email.parser import BytesParser

sys.path[:0] = [str(Path(__file__).resolve().parent), str(Path(__file__).resolve().parent.parent)]
from railtime_e3 import (Admission, Connection, Deferred, E3Error, Envelope, PilotPolicy,
                        PipeWorker, ReinjectFailure, TicketStore, parse_fields, digest)
from railtime_e3_protocol import (MilterSession, HDR_LEADSPC, ADD_HEADERS, CHANGE_HEADERS,
                                ACCEPT, CONTINUE, REJECT, TEMPFAIL, policy_request)
from railtime_transit import HtmlResult, PILOT
from railtime_transit_cid import PINNED_MEDIA, APP_ORIGIN
from railtime_e3_cli import runtime_window, local_json, smtp_reinject

HEADER = 'u2e-' + '1' * 24
SECRET = b'a' * 64  # Public synthetic fixture; never a provisioned credential.
CONNECTION = Connection('40.92.0.10', 'TLSv1.3', 256)
ENVELOPE = Envelope(PILOT, (PILOT,), 'ABC123')
HTML = b'<html><head></head><body><p>Author = &amp; exact.</p>OLD<blockquote>Quoted OLD</blockquote></body></html>'


def mime(extra=b'', body=None):
    return (b'From: <' + PILOT.encode() + b'>\r\nTo: <' + PILOT.encode() + b'>\r\n'
            + b'X-RailTime-Transit-Ingress: ' + SECRET + b'\r\nX-RailTime-Template: ' + HEADER.encode()
            + b'\r\nMessage-ID: <synthetic@fixture.invalid>\r\n'
            + extra + b'Content-Type: text/html; charset=utf-8\r\nContent-Transfer-Encoding: base64\r\n\r\n'
            + base64.b64encode(HTML if body is None else body))


def add_header(message, header):
    start = message.index(b'\r\n\r\n')
    return message[:start] + b'\r\n' + header + message[start:]


def queued(message, ticket, *, trace=False):
    output = add_header(message, b'X-RailTime-Local-Admission: e3p1; ticket=' + ticket.encode())
    if trace:
        output = (b'Received: from synthetic.fixture [40.92.0.10]\r\n by plesk.follow-flow.de (Postfix) with ESMTPS id ABC123\r\n'
                  + b' for <' + PILOT.encode() + b'>; Tue, 03 Nov 2026 12:00:00 +0000\r\n' + output)
    return output


class E3Tests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.clock = [1000.0]
        self.store = TicketStore(Path(self.temp.name) / 'private', b'k' * 32, lambda: self.clock[0])
        self.policy = PilotPolicy('11111111-1111-4111-8111-111111111111', 42, HEADER, ('40.92.0.0/15',))
        self.snapshot = {'schema': 1, 'ok': False, 'code': 'U2E_TRANSIT_SOURCE_REJECTED', 'retryable': False}
        self.calls = []
        def resolve(request):
            self.calls.append(request)
            return self.snapshot
        self.admission = Admission(self.policy, self.store, SECRET, resolve)

    def issue(self, message=None):
        return self.admission.eod(mime() if message is None else message, CONNECTION, ENVELOPE)

    def sender(self, output, envelope, token):
        self.store.admit_return(token, output, Envelope(PILOT, (PILOT,), 'RET123'))
        return 'RET123'

    def worker(self, send=None, transform=None):
        return PipeWorker(self.store, self.policy, transform or (lambda *args: self.fail('No transform on unadmitted source')), send or self.sender)

    def test_rcpt_exact_pilot_only_and_ordinary_plesk_recipients_remain_dunno(self):
        self.assertEqual('OK', self.policy.rcpt_policy(CONNECTION, ENVELOPE, PILOT))
        for connection in [Connection('1.1.1.1', 'TLSv1.3', 256), Connection(CONNECTION.peer, '', 0), Connection(CONNECTION.peer, 'TLSv1', 256)]:
            self.assertTrue(self.policy.rcpt_policy(connection, ENVELOPE, PILOT).startswith('550'))
            self.assertEqual('DUNNO', self.policy.rcpt_policy(connection, ENVELOPE, 'local@example.test'))
        self.assertTrue(self.policy.rcpt_policy(CONNECTION, Envelope('foreign@example.test', ()), PILOT).startswith('550'))
        attrs = {'request': 'smtpd_access_policy', 'protocol_state': 'RCPT', 'client_address': CONNECTION.peer,
                 'encryption_protocol': 'TLSv1.3', 'encryption_keysize': '256', 'sender': PILOT, 'recipient': PILOT}
        self.assertEqual('OK', policy_request(attrs, self.admission))

    def test_ingress_requires_actual_peer_tls_exact_envelope_and_all_recipients(self):
        for connection, envelope in [(Connection('1.1.1.1', 'TLSv1.3', 256), ENVELOPE),
                                     (Connection(CONNECTION.peer, '', 0), ENVELOPE),
                                     (CONNECTION, Envelope('foreign@example.test', (PILOT,), 'ABC123')),
                                     (CONNECTION, Envelope(PILOT, (PILOT, 'foreign@example.test'), 'ABC123')),
                                     (CONNECTION, Envelope(PILOT, (PILOT, PILOT), 'ABC123')),
                                     (CONNECTION, Envelope(PILOT, (PILOT,), ''))]:
            with self.subTest(connection=connection, envelope=envelope), self.assertRaises(E3Error):
                self.admission.eod(mime(), connection, envelope)
        self.assertEqual([], self.calls)

    def test_missing_wrong_duplicate_folded_and_forged_headers_are_never_admitted(self):
        original = mime()
        changes = [original.replace(SECRET, b'b' * 64), original.replace(b'X-RailTime-Transit-Ingress: '+SECRET+b'\r\n', b''),
                   add_header(original, b'X-RailTime-Transit-Ingress: '+SECRET),
                   add_header(original, b'X-RailTime-Template: '+HEADER.encode()),
                   original.replace(HEADER.encode(), b'u2e-'+b'2'*24),
                   original.replace(SECRET, SECRET[:32]+b'\r\n '+SECRET[32:]),
                   add_header(original, b'X-RailTime-Local-Admission: e3p1; ticket='+b'c'*64),
                   add_header(original, b'X-RailTime-Transit-Result: v1; transformed')]
        for changed in changes:
            with self.subTest(digest=digest(changed)), self.assertRaises(E3Error):
                self.issue(changed)
        self.assertEqual([], self.calls)

    def test_non_candidate_has_no_ticket_or_resolver_call(self):
        message = b'From: local@example.test\r\nTo: local@example.test\r\n\r\nNormal mail\r\n'
        self.assertIsNone(self.admission.eod(message, Connection('127.0.0.1', '', 0), Envelope('local@example.test', ('local@example.test',), 'ABC123')))
        self.assertEqual([], self.calls)

    def test_resolver_snapshot_binding_and_retryable_failure_before_smtp250(self):
        self.snapshot = {'schema': 1, 'ok': False, 'code': 'U2E_TRANSIT_BINDING_INVALID', 'retryable': True}
        with self.assertRaises(Deferred):
            self.issue()
        self.assertEqual(1, len(self.calls))
        self.assertEqual({'schema': 1, 'tenantId': self.policy.tenant_id, 'userId': 42, 'envelopeSender': PILOT, 'templateHeader': HEADER}, self.calls[0])
        self.assertEqual([], list(self.store.root.glob('*.json')))

    def test_ticket_binds_capture_headers_body_queue_peer_and_final_rcpts(self):
        ticket = self.issue()
        original = queued(mime(), ticket, trace=True)
        self.assertEqual(ticket, self.store.bind(original, ENVELOPE, CONNECTION.peer, self.policy.local_host)[0])
        for altered, envelope, peer in [(original.replace(b'ABC123', b'WRONGQ'), ENVELOPE, CONNECTION.peer),
                                       (original.replace(base64.b64encode(HTML), base64.b64encode(HTML+b' changed')), ENVELOPE, CONNECTION.peer),
                                       (original+b'A', ENVELOPE, CONNECTION.peer),
                                       (original, Envelope(PILOT, ('foreign@example.test',), ENVELOPE.queue_id), CONNECTION.peer),
                                       (original, Envelope(PILOT, (PILOT,), 'OTHERQ'), CONNECTION.peer),
                                       (original, ENVELOPE, '40.92.0.11')]:
            with self.subTest(peer=peer, envelope=envelope), self.assertRaises(Deferred):
                self.store.bind(altered, envelope, peer, self.policy.local_host)

    def test_foreign_or_extra_received_cannot_be_discarded_from_binding(self):
        ticket = self.issue()
        source = queued(mime(), ticket)
        for prefix in [b'Received: by foreign.invalid (Postfix) with ESMTPS id ABC123\r\n',
                       b'Received: by plesk.follow-flow.de (Postfix) with ESMTPS id OTHERQ\r\n',
                       b'Received: by plesk.follow-flow.de (Postfix) with ESMTPS id ABC123\r\n'*2]:
            with self.assertRaises(Deferred):
                self.store.bind(prefix+source, ENVELOPE, CONNECTION.peer, self.policy.local_host)

    def test_immutable_ticket_hmac_capture_tamper_and_expiry_fail_closed(self):
        ticket = self.issue()
        path = self.store._path(ticket, 'json')
        original = path.read_bytes()
        os.chmod(path, 0o600)
        path.write_bytes(original.replace(b'ABC123', b'OTHERQ'))
        with self.assertRaises(Deferred):
            self.store.read(ticket)
        path.write_bytes(original)
        self.clock[0] += 7*86400
        with self.assertRaises(Deferred):
            self.store.read(ticket)
        self.assertTrue(self.store._path(ticket, 'eml').is_file())

    def test_unchanged_return_scrubs_controls_preserves_payload_and_acks_only_once(self):
        ticket = self.issue()
        source = queued(mime(), ticket, trace=True)
        worker = self.worker()
        self.assertEqual({'status': 'submitted'}, worker.process(source, ENVELOPE, CONNECTION.peer))
        output = self.store._path(ticket, 'out').read_bytes()
        fields, body = parse_fields(output)
        self.assertEqual(parse_fields(source)[1], body)
        self.assertFalse(any(key in {b'x-railtime-template', b'x-railtime-transit-ingress', b'x-railtime-local-admission'} for key, _ in fields))
        self.assertIn(b'X-RailTime-Transit-Result: v1; unchanged', output)
        self.assertEqual({'status': 'already-submitted'}, self.worker(send=lambda *args: self.fail('duplicate send')).process(source, ENVELOPE, CONNECTION.peer))

    def test_return_ticket_exact_payload_envelope_and_replay_are_checked(self):
        ticket = self.issue()
        _, owner = self.store.claim(ticket)
        output = b'From: fixture@example.test\r\n\r\nOriginal payload\r\n'
        self.store.prepare(ticket, output, owner)
        token = self.store.begin_return(ticket, owner)
        for actual, envelope in [(output+b'A', Envelope(PILOT, (PILOT,), 'RET123')),
                                 (output, Envelope(PILOT, ('foreign@example.test',), 'RET123'))]:
            with self.assertRaises(E3Error):
                self.store.admit_return(token, actual, envelope)
        self.store.admit_return(token, output, Envelope(PILOT, (PILOT,), 'RET123'))
        with self.assertRaises(E3Error):
            self.store.admit_return(token, output, Envelope(PILOT, (PILOT,), 'RET124'))

    def test_confirmed_rejection_retries_identical_prepared_output_but_ambiguity_requires_review(self):
        ticket = self.issue(); source = queued(mime(), ticket)
        observed = []
        def reject(output, *args):
            observed.append(output)
            raise ReinjectFailure(uncertain=False)
        with self.assertRaises(ReinjectFailure):
            self.worker(send=reject).process(source, ENVELOPE, CONNECTION.peer)
        def accepted(output, envelope, token):
            self.assertEqual(observed[0], output)
            return self.sender(output, envelope, token)
        self.assertEqual('submitted', self.worker(send=accepted).process(source, ENVELOPE, CONNECTION.peer)['status'])
        other = self.issue(); second = queued(mime(), other)
        def uncertain(*args):
            raise ReinjectFailure(uncertain=True)
        with self.assertRaises(ReinjectFailure):
            self.worker(send=uncertain).process(second, ENVELOPE, CONNECTION.peer)
        with self.assertRaisesRegex(Deferred, 'uncertain'):
            self.worker(send=lambda *a: self.fail('unsafe retry')).process(second, ENVELOPE, CONNECTION.peer)

    def test_expired_worker_cannot_prepare_over_a_new_lease_or_reset_submitted(self):
        ticket = self.issue()
        _, first = self.store.claim(ticket)
        self.clock[0] += 121
        _, second = self.store.claim(ticket)
        with self.assertRaisesRegex(Deferred, 'lease_changed'):
            self.store.prepare(ticket, b'old', first)
        output = b'From: test@example.test\r\n\r\nNew\r\n'
        self.store.prepare(ticket, output, second)
        token = self.store.begin_return(ticket, second)
        with self.assertRaises(Deferred):
            self.store.prepare(ticket, b'old', first)
        self.store.admit_return(token, output, Envelope(PILOT, (PILOT,), 'RET123'))
        self.store.complete(ticket, 'RET123', second)
        with self.assertRaises(Deferred):
            self.store.prepare(ticket, b'old', first)
        self.store.retry(ticket, first, uncertain=False, code='stale_worker')
        self.assertEqual(('submitted', ''), self.store.claim(ticket))

    def test_cleanup_removes_only_expired_submitted_own_capture_and_keeps_ambiguous_jobs(self):
        done = self.issue(); source = queued(mime(), done)
        self.worker().process(source, ENVELOPE, CONNECTION.peer)
        pending = self.issue()
        self.clock[0] += 7*86400+1
        result = self.store.purge_completed()
        self.assertEqual({'removed': 1, 'expiredUnresolved': 1, 'requiresManualQueueReview': True}, result)
        for suffix in ['json', 'eml', 'out']:
            self.assertFalse(self.store._path(done, suffix).exists())
        self.assertTrue(self.store._path(pending, 'eml').exists())

    def test_spool_quota_refuses_new_admission_before_a_ticket_header(self):
        limited = TicketStore(Path(self.temp.name)/'limited', b'k'*32, max_jobs=1)
        admission = Admission(self.policy, limited, SECRET, lambda request: self.snapshot)
        self.assertIsNotNone(admission.eod(mime(), CONNECTION, ENVELOPE))
        with self.assertRaisesRegex(Deferred, 'quota'):
            admission.eod(mime(), CONNECTION, ENVELOPE)

    def session(self, *, returning=False, peer='40.92.0.10'):
        session = MilterSession(self.admission, return_store=self.store if returning else None)
        negotiation = session.handle(b'O', struct.pack('!III', 6, ADD_HEADERS|CHANGE_HEADERS, HDR_LEADSPC))
        self.assertTrue(negotiation[0].startswith(b'O'))
        session.handle(b'D', b'C{client_addr}\0'+peer.encode()+b'\0')
        session.handle(b'C', b'host\x004'+struct.pack('!H', 25)+peer.encode()+b'\0')
        session.handle(b'D', b'H{tls_version}\0TLSv1.3\0{cipher_bits}\0'+b'256\0')
        session.handle(b'H', b'fixture.invalid\0')
        return session

    def feed(self, session, message, queue='ABC123'):
        self.assertEqual([CONTINUE], session.handle(b'M', b'<'+PILOT.encode()+b'>\0'))
        self.assertEqual([CONTINUE], session.handle(b'R', b'<'+PILOT.encode()+b'>\0'))
        fields, body = parse_fields(message)
        for _, raw in fields:
            name, value = raw[:-2].split(b':', 1)
            self.assertEqual([CONTINUE], session.handle(b'L', name+b'\0'+value+b'\0'))
        self.assertEqual([CONTINUE], session.handle(b'N'))
        self.assertEqual([CONTINUE], session.handle(b'B', body))
        session.handle(b'D', b'Ei\0'+queue.encode()+b'\0')
        return session.handle(b'E')

    def test_milter_adds_one_local_ticket_before250_and_keeps_tls_across_mail_abort(self):
        session = self.session()
        first = self.feed(session, mime())
        self.assertTrue(first[0].startswith(b'hX-RailTime-Local-Admission\0 '))
        session.handle(b'A')
        self.assertTrue(self.feed(session, mime(), 'ABC124')[0].startswith(b'h'))
        session.handle(b'K')
        self.assertEqual('', session.peer)
        self.assertEqual({}, session.macros)

    def test_milter_missing_queue_id_or_leading_whitespace_capability_is_not_accepted(self):
        session = MilterSession(self.admission)
        self.assertEqual([TEMPFAIL], session.handle(b'O', struct.pack('!III', 6, ADD_HEADERS|CHANGE_HEADERS, 0)))
        session = self.session()
        self.assertEqual([REJECT], self.feed(session, mime(), ''))

    def test_return_milter_requires_private_ticket_and_removes_it_before_acceptance(self):
        ticket = self.issue(); _, owner = self.store.claim(ticket)
        output = b'From: synthetic@example.test\r\nX-RailTime-Transit-Result: v1; unchanged\r\n\r\nPayload\r\n'
        self.store.prepare(ticket, output, owner)
        token = self.store.begin_return(ticket, owner)
        message = b'X-RailTime-Transit-Context: e3r1; ticket='+token.encode()+b'\r\n'+output
        session = self.session(returning=True, peer='127.0.0.1')
        result = self.feed(session, message, 'RET123')
        self.assertEqual(b'm'+struct.pack('!I', 1)+b'X-RailTime-Transit-Context\0\0', result[0])
        self.assertEqual(CONTINUE, result[1])
        replay = self.session(returning=True, peer='127.0.0.1')
        self.assertEqual([REJECT], self.feed(replay, message, 'RET124'))

    def test_cleanup_resumes_durable_tombstone_after_partial_filesystem_deletion(self):
        ticket = self.issue(); self.worker().process(queued(mime(), ticket), ENVELOPE, CONNECTION.peer)
        self.clock[0] += 7*86400+1
        original = self.store._purge_artifacts
        def interrupted(ticket):
            path = self.store._path(ticket, 'eml')
            os.chmod(path, 0o600); path.unlink()
            raise OSError('synthetic interruption')
        with patch.object(self.store, '_purge_artifacts', interrupted), self.assertRaises(OSError):
            self.store.purge_completed()
        self.assertFalse(self.store._path(ticket, 'eml').exists())
        self.assertEqual(1, self.store.purge_completed()['removed'])
        self.assertFalse(self.store._path(ticket, 'json').exists())

    def test_failed_preaccept_ticket_creation_removes_only_its_own_private_partial_files(self):
        import railtime_e3
        original = railtime_e3._write_private
        def fail_metadata(path, data):
            if path.suffix == '.json':
                raise OSError('synthetic full disk')
            return original(path, data)
        with patch.object(railtime_e3, '_write_private', fail_metadata), self.assertRaises(Deferred):
            self.issue()
        self.assertEqual([], list(self.store.root.glob('*.eml')))
        self.assertEqual([], list(self.store.root.glob('*.json')))

    def test_ingress_window_expiry_still_allows_the_previously_authorized_queue_to_drain(self):
        receipt = {'schema': 1, 'scope': 'IT-only', 'approvedConfigSha256': 'a'*64,
                   'approvalId': '11111111-1111-4111-8111-111111111111', 'created': 1000,
                   'admissionUntil': 1000+86400, 'drainUntil': 1000+8*86400}
        self.assertEqual(receipt['admissionUntil'], runtime_window(receipt, 'a'*64, 1001, drain=False))
        with self.assertRaises(Deferred):
            runtime_window(receipt, 'a'*64, 1000+86401, drain=False)
        self.assertEqual(receipt['drainUntil'], runtime_window(receipt, 'a'*64, 1000+6*86400, drain=True))
        with self.assertRaises(Deferred):
            runtime_window(receipt, 'b'*64, 1001, drain=True)
        with self.assertRaises(Deferred):
            runtime_window(receipt, 'a'*64, receipt['drainUntil'], drain=True)

    def test_raw_folding_spaces_and_tabs_are_part_of_the_ticket_binding(self):
        original = add_header(mime(), b'Subject:\t preserved\r\n\t folded value')
        session = self.session()
        responses = self.feed(session, original)
        ticket = responses[0].split(b'ticket=')[1].rstrip(b'\0').decode()
        self.assertEqual(ticket, self.store.bind(queued(original, ticket), ENVELOPE, CONNECTION.peer, self.policy.local_host)[0])
        changed = queued(original, ticket).replace(b'Subject:\t ', b'Subject: ')
        with self.assertRaises(Deferred):
            self.store.bind(changed, ENVELOPE, CONNECTION.peer, self.policy.local_host)

    def test_queue_capture_recovers_a_crash_after_file_fsync_before_its_hash_commit(self):
        ticket = self.issue(); source = queued(mime(), ticket)
        _, owner = self.store.claim(ticket)
        original = self.store.connect
        @contextmanager
        def fail_commit():
            with original() as connection:
                class FailingConnection:
                    def execute(self, sql, *args):
                        if sql.startswith('UPDATE jobs SET queue_hash='):
                            raise OSError('synthetic crash before hash commit')
                        return connection.execute(sql, *args)
                yield FailingConnection()
        with patch.object(self.store, 'connect', fail_commit), self.assertRaises(OSError):
            self.store.capture_queue(ticket, source, owner)
        self.assertEqual(source, self.store._path(ticket, 'queued').read_bytes())
        with self.store.connect() as db:
            self.assertIsNone(db.execute('SELECT queue_hash FROM jobs WHERE ticket=?', (ticket,)).fetchone()[0])
        self.clock[0] += 121
        _, replacement = self.store.claim(ticket)
        self.store.bind(source, ENVELOPE, CONNECTION.peer, self.policy.local_host)
        self.store.capture_queue(ticket, source, replacement)
        with self.store.connect() as db:
            self.assertEqual(digest(source), db.execute('SELECT queue_hash FROM jobs WHERE ticket=?', (ticket,)).fetchone()[0])

    def test_tnef_and_protected_first_capture_returns_original_payload_without_responsive_success(self):
        root = Path(__file__).resolve().parents[3]
        name, sha = next(iter(PINNED_MEDIA.items()))
        source = APP_ORIGIN+'/m/'+name
        content = (root/'public/m'/name).read_bytes()
        self.snapshot = {'schema': 1, 'ok': True, 'projection': {'mode': 'send', 'header': HEADER,
                         'templateHash': '5d612a3a4a5b48f63660774599fb7bc9405e3856517832f1cccd50bf9a064204', 'media': [{'url': source}]},
                         'media': [{'source': source, 'mime': 'image/png', 'sha256': sha, 'dataBase64': base64.b64encode(content).decode()}]}
        for media, expected in [(b'application/ms-tnef', 'tnef_requires_ingress_proof'), (b'application/pkcs7-mime', 'protected_message')]:
            message = mime().replace(b'text/html; charset=utf-8', media)
            ticket = self.issue(message)
            self.worker(transform=lambda *args: self.fail('Protected/TNEF must not transform')).process(queued(message, ticket), ENVELOPE, CONNECTION.peer)
            output = self.store._path(ticket, 'out').read_bytes()
            self.assertEqual(parse_fields(message)[1], parse_fields(output)[1])
            self.assertIn(b'X-RailTime-Transit-Result: v1; unchanged', output)
            self.assertNotIn(SECRET, output)
            with self.store.connect() as db:
                self.assertEqual(expected, db.execute('SELECT result_code FROM jobs WHERE ticket=?', (ticket,)).fetchone()[0])

    def test_a_signature_covering_the_secret_queues_instead_of_corruption_or_leak(self):
        message = add_header(mime(), b'DKIM-Signature: v=1; h=from:x-railtime-transit-ingress; b=synthetic')
        ticket = self.issue(message)
        with self.assertRaisesRegex(Deferred, 'return_headers_not_safe'):
            self.worker(send=lambda *args: self.fail('No unsafe outgoing message')).process(queued(message, ticket), ENVELOPE, CONNECTION.peer)
        self.assertTrue(self.store._path(ticket, 'eml').exists())

    def test_smtp_adapter_uses_only_fixed_local_endpoint_original_rcpts_and_requires_matching_queue_ack(self):
        calls = []
        class FakeSmtp:
            def __init__(self, host, port, timeout): calls.append(('connect', host, port))
            def __enter__(self): return self
            def __exit__(self, *args): return False
            def ehlo(self, name): calls.append(('ehlo', name)); return 250, b'ok'
            def has_extn(self, name): return True
            def mail(self, sender, options): calls.append(('mail', sender)); return 250, b'ok'
            def rcpt(self, recipient): calls.append(('rcpt', recipient)); return 250, b'ok'
            def data(self, message): calls.append(('data', digest(message))); return 250, b'2.0.0 Ok: queued as RET123'
        with patch('railtime_e3_cli.smtplib.SMTP', FakeSmtp):
            self.assertEqual('RET123', smtp_reinject(b'From: synthetic@example.test\r\n\r\nUnchanged\r\n', ENVELOPE, 'a'*64))
        self.assertIn(('connect', '127.0.0.1', 10027), calls)
        self.assertEqual([('rcpt', PILOT)], [call for call in calls if call[0] == 'rcpt'])
        with patch('railtime_e3_cli.smtplib.SMTP', side_effect=AssertionError('No send for implicit CRLF mutation')):
            with self.assertRaises(ReinjectFailure):
                smtp_reinject(b'From: fixture@example.test\r\n\r\nNo terminal CRLF', ENVELOPE, 'a'*64)

    def test_postfix_review_generator_preserves_plesk_and_refuses_modifiers_or_maps_it_cannot_merge(self):
        spec = importlib.util.spec_from_file_location('e3_review_generator', Path(__file__).with_name('render-postfix-review.py'))
        module = importlib.util.module_from_spec(spec); spec.loader.exec_module(module)
        config = json.loads(Path(__file__).with_name('runtime-config.example.json').read_text())
        config['networkObservedAtUtc'] = '2026-11-04T00:00:00Z'
        baseline = {'schema': 1, 'observedAtUtc': '2026-11-04T00:00:00Z', 'mail_version': '3.8.6',
                    'queue_directory': '/var/spool/postfix', 'maximal_queue_lifetime_seconds': 5*86400,
                    'pilotAliasAndBccMappingAbsent': True, 'smtpd_milters': ', inet:127.0.0.1:12768',
                    'smtpd_milter_maps': '', 'milter_header_checks': '',
                    'smtpd_relay_restrictions': 'permit_mynetworks permit_sasl_authenticated defer_unauth_destination',
                    'milter_connect_macros': 'j {daemon_name} {daemon_addr} v _', 'milter_helo_macros': '{client_name}',
                    'milter_mail_macros': '{auth_authen}', 'milter_end_of_data_macros': '{other_existing}'}
        output = module.render(config, baseline, 1793750401)
        self.assertIn('inet:127.0.0.1:12768, { unix:', output['milter-map.review'])
        self.assertIn('default_action=tempfail', output['milter-map.review'])
        main = output['main.cf.merge.review']
        self.assertIn('milter_mail_macros = {auth_authen}, {tls_version}', main)
        self.assertIn('milter_connect_macros = j, {daemon_name}, {daemon_addr}, v, _, {client_addr}', main)
        self.assertIn('milter_end_of_data_macros = {other_existing}, i', main)
        self.assertIn('check_recipient_access hash:', main)
        for changed in [{**baseline, 'smtpd_milter_maps': 'hash:existing'},
                        {**baseline, 'pilotAliasAndBccMappingAbsent': False},
                        {**baseline, 'smtpd_relay_restrictions': 'warn_if_reject defer_unauth_destination'},
                        {**baseline, 'smtpd_relay_restrictions': 'check_sender_access hash:/path/defer_unauth_destination'}]:
            with self.assertRaises(ValueError): module.render(config, changed, 1793750401)
        master = Path(__file__).with_name('postfix-master.cf.review').read_text()
        self.assertIn('no_header_body_checks,no_address_mappings', master)
        self.assertIn('-o smtpd_milters=unix:railtime-e3/return.sock', master)

    def test_actual_b1_cid_php_callback_preserves_author_quote_plaintext_and_attachment(self):
        root = Path(__file__).resolve().parents[3]
        php = Path('C:/xampp/php/php.exe') if os.name == 'nt' else Path('/usr/bin/php')
        if not php.is_file():
            self.skipTest('Local PHP binary unavailable; no network replacement')
        generate = b'''<?php
require 'vendor/autoload.php';
$app=new Illuminate\\Foundation\\Application(getcwd());
$app->instance('config',new Illuminate\\Config\\Repository(['app'=>['url'=>'https://app.rail-time.de']]));
$fixture = new Tests\\Feature\\OutlookUniversalPresentationTest('syntheticDescriptorOnly');
$descriptor=(new ReflectionMethod($fixture,'descriptor'))->invoke($fixture);
$renderer=new App\\Support\\OutlookAddin\\OutlookUniversalPresentation('https://app.rail-time.de');
$author='<p>Author &amp; literal {{FIRMENNAME}}.\\nMixed line.</p>';
$send=$renderer->render($descriptor,new Illuminate\\Support\\HtmlString('Published source author.'),['mode'=>'send']);
$editor=$renderer->render($descriptor,new Illuminate\\Support\\HtmlString($author),['mode'=>'editor']);
echo json_encode(['send'=>$send,'editor'=>$editor['html'],'author'=>$author],JSON_THROW_ON_ERROR);
'''
        prepared = subprocess.run([str(php)], cwd=root, input=generate, stdout=subprocess.PIPE,
                                  stderr=subprocess.PIPE, timeout=20, check=True)
        values = json.loads(prepared.stdout)
        projection = values['send']
        self.policy = PilotPolicy(self.policy.tenant_id, 42, projection['header'], self.policy.microsoft_networks)
        media = []
        for entry in projection['media']:
            data = (root/'public/m'/entry['url'].rsplit('/', 1)[-1]).read_bytes()
            media.append({'source': entry['url'], 'mime': 'image/png', 'sha256': entry['sha256'], 'dataBase64': base64.b64encode(data).decode()})
        self.snapshot = {'schema': 1, 'ok': True, 'projection': projection, 'media': media}
        self.admission = Admission(self.policy, self.store, SECRET, lambda request: self.snapshot)
        quote = '<blockquote><p>Historical quote.</p>'+values['editor']+'</blockquote>'
        html = '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>'+values['editor']+quote+'</body></html>'
        plain = b'Content-Type: text/plain; charset=utf-8\r\n\r\nOriginal plaintext.'
        attachment = b'Content-Type: application/octet-stream\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename="fixture.bin"\r\n\r\nQUJDREVGRw=='
        html_part = b'Content-Type: text/html; charset=utf-8\r\nContent-Transfer-Encoding: base64\r\n\r\n'+base64.b64encode(html.encode())
        message = (b'From: <'+PILOT.encode()+b'>\r\nX-RailTime-Transit-Ingress: '+SECRET+b'\r\nX-RailTime-Template: '+projection['header'].encode()
                   +b'\r\nContent-Type: multipart/mixed; boundary="fixture"\r\n\r\n--fixture\r\n'
                   +b'\r\n--fixture\r\n'.join([plain, html_part, attachment])+b'\r\n--fixture--\r\n')
        ticket = self.issue(message)
        def transform(incoming, headers, snapshot, cids):
            result = local_json([str(php), str(root/'scripts/mail/e3/transform-b1.php')],
                                {'schema': 1, 'html': incoming, 'headers': headers, 'projection': snapshot, 'cidUrls': cids, 'envelopeSender': PILOT})
            return HtmlResult(result['html'], result['changed'], result['reason'])
        self.worker(transform=transform).process(queued(message, ticket), ENVELOPE, CONNECTION.peer)
        output = self.store._path(ticket, 'out').read_bytes()
        self.assertIn(plain, output); self.assertIn(attachment, output)
        parsed = BytesParser(policy=email_policy.default).parsebytes(output)
        output_html = next(p.get_content() for p in parsed.walk() if p.get_content_type() == 'text/html')
        self.assertIn(values['author'], output_html)
        self.assertIn(quote, output_html)
        self.assertEqual(9, sum(p['Content-ID'] is not None for p in parsed.walk()))
        self.assertIn(b'X-RailTime-Transit-Result: v1; transformed', output)
        self.assertNotIn(SECRET, output)


if __name__ == '__main__':
    unittest.main()
