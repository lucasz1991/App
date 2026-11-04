"""Synthetic MIME only; pinned local public PNG fixtures; no send or network."""
import base64
from email import policy
from email.parser import BytesParser
from pathlib import Path
import json
import shutil
import subprocess
import unittest

from railtime_transit import HtmlResult, PILOT, _headers, _walk
from railtime_transit_cid import AdmittedMedia, APP_ORIGIN, PINNED_MEDIA, transform_message_with_cid


HEADER = b"X-RailTime-Template: u2e-" + b"1" * 24 + b"\r\n"
AUTHOR = b'<p>Original author &amp; spelling\n<img src="https://app.rail-time.de/m/b983af0049.png"></p>'
QUOTE = b'<blockquote>Original quote = &amp;\r\n<img src="cid:old@quote.test"></blockquote>'
HTML = b'<html><head></head><body>' + AUTHOR + b'OLD-SIGNATURE' + QUOTE + b'</body></html>'


def part(data=HTML, media=b"text/html; charset=utf-8", extra=b""):
    return (b"Content-Type: " + media + b"\r\nContent-Transfer-Encoding: base64\r\n"
            + extra + b"\r\n" + base64.b64encode(data))


def multipart(parts, kind=b"mixed", boundary=b"outer", params=b""):
    return (b"Content-Type: multipart/" + kind + b'; boundary="' + boundary + b'"' + params
            + b"\r\n\r\nPreamble\r\n--" + boundary + b"\r\n"
            + (b"\r\n--" + boundary + b"\r\n").join(parts)
            + b"\r\n--" + boundary + b"--\r\nEpilogue\r\n")


class CidTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        public = Path(__file__).resolve().parents[2] / "public/m"
        cls.media = [AdmittedMedia(APP_ORIGIN + "/m/" + name, (public / name).read_bytes())
                     for name in list(PINNED_MEDIA)[:2]]

    def apply(self, message, callback=None, media=None, **options):
        def transform(html, headers, cids):
            signature = '<div class="owned">' + ''.join('<img src="' + value + '">' for value in cids.values()) + '</div>'
            return HtmlResult(html.replace("OLD-SIGNATURE", signature, 1), True, "transformed")
        return transform_message_with_cid(message, callback or transform,
                                          self.media if media is None else media,
                                          trusted_ingress=options.get("trusted", True),
                                          envelope_sender=options.get("sender", PILOT))

    def assert_original(self, message, result):
        self.assertFalse(result.changed, result.reason)
        self.assertIs(message, result.message)
        self.assertEqual((), result.added_cids)
        self.assertEqual((), result.reused_cids)
        self.assertIsNone(result.replaced_span)

    def check_html_and_resources(self, result):
        state = {"parts": 0, "html": [], "boundaries": set()}
        _walk(result.message, _headers(result.message, 0, len(result.message)), state)
        self.assertEqual(1, len(state["html"]))
        mail = BytesParser(policy=policy.default).parsebytes(result.message)
        html = next(p for p in mail.walk() if p.get_content_type() == "text/html").get_payload(decode=True)
        self.assertIn(AUTHOR, html)
        self.assertIn(QUOTE, html)
        self.assertEqual(1, html.count(AUTHOR))
        self.assertEqual(1, html.count(QUOTE))
        resources = {str(p["Content-ID"])[1:-1]: p.get_payload(decode=True)
                     for p in mail.walk() if p.get_content_type() == "image/png"}
        for entry in self.media:
            self.assertIn(entry.data, resources.values())
        for cid in result.added_cids + result.reused_cids:
            self.assertIn(("cid:" + cid).encode(), html)
            self.assertIn(cid, resources)
        return mail, html

    def test_mixed_alternative_html_wraps_only_leaf_and_preserves_other_raw_parts(self):
        plain = part(b"Plain text = opaque", b"text/plain; charset=utf-8")
        attachment = part(b"\x00\xffunchanged attachment", b"application/octet-stream",
                          b'Content-Disposition: attachment; filename="a.bin"\r\n')
        alternative = multipart([plain, part()], b"alternative", b"alternate")
        message = HEADER + b"Message-ID: <same@thread.test>\r\nReferences: <earlier@thread.test>\r\n" + multipart([alternative, attachment])
        result = self.apply(message)
        self.assertTrue(result.changed, result.reason)
        self.assertEqual(2, len(result.added_cids))
        self.assertIn(plain, result.message)
        self.assertIn(attachment, result.message)
        start, end = result.replaced_span
        self.assertEqual(message[:start], result.message[:start])
        self.assertTrue(result.message.endswith(message[end:]))
        mail, _ = self.check_html_and_resources(result)
        self.assertEqual("<same@thread.test>", mail["Message-ID"])
        self.assertEqual("<earlier@thread.test>", mail["References"])

    def test_single_html_keeps_message_headers_raw_and_adds_related_container(self):
        headers = HEADER + b"From: IT <it-technik@rail-time.de>\r\nSubject: Folded\r\n value\r\nMessage-ID: <same@thread.test>\r\nMIME-Version: 1.0\r\n"
        message = headers + part()
        result = self.apply(message)
        self.assertTrue(result.changed, result.reason)
        self.assertTrue(result.message.startswith(headers))
        mail, _ = self.check_html_and_resources(result)
        self.assertEqual("multipart/related", mail.get_content_type())
        self.assertEqual("text/html", mail.get_payload()[0].get_content_type())

    def test_reuses_existing_related_cids_with_exact_bytes_and_no_resource_addition(self):
        images = [part(entry.data, b"image/png", (f'Content-ID: <image{i}@existing.test>\r\nContent-Disposition: inline\r\n').encode())
                  for i, entry in enumerate(self.media)]
        message = HEADER + multipart([part()] + images, b"related")
        result = self.apply(message)
        self.assertTrue(result.changed, result.reason)
        self.assertEqual((), result.added_cids)
        self.assertEqual(("image0@existing.test", "image1@existing.test"), result.reused_cids)
        for image in images:
            self.assertIn(image, result.message)
        self.check_html_and_resources(result)

    def test_some_existing_resource_is_reused_and_other_added(self):
        image = part(self.media[0].data, b"image/png", b"Content-ID: <reuse@existing.test>\r\n")
        message = HEADER + multipart([part(), image], b"related")
        result = self.apply(message)
        self.assertTrue(result.changed, result.reason)
        self.assertEqual(1, len(result.added_cids))
        self.assertEqual(("reuse@existing.test",), result.reused_cids)
        self.assertIn(image, result.message)
        self.check_html_and_resources(result)

    def test_does_not_reuse_unrelated_or_attachment_resource(self):
        image = part(self.media[0].data, b"image/png", b'Content-ID: <wrong@existing.test>\r\nContent-Disposition: attachment; filename="logo.png"\r\n')
        message = HEADER + multipart([part(), image], b"mixed")
        result = self.apply(message)
        self.assertTrue(result.changed, result.reason)
        self.assertEqual(2, len(result.added_cids))
        self.assertEqual((), result.reused_cids)
        self.assertIn(image, result.message)

    def test_conflicting_or_duplicate_cid_returns_complete_original(self):
        digest = PINNED_MEDIA[self.media[0].source.rsplit("/", 1)[-1]]
        derived = ("railtime-transit-u2e-" + digest + "@inline.rail-time.de").encode()
        image = part(b"wrong bytes", b"image/png", b"Content-ID: <" + derived + b">\r\n")
        message = HEADER + multipart([part(), image], b"related")
        self.assert_original(message, self.apply(message))
        image = part(self.media[0].data, b"image/png", b"Content-ID: <same@existing.test>\r\n")
        message = HEADER + multipart([part(), image, image], b"related")
        self.assert_original(message, self.apply(message))

    def test_existing_related_type_and_start_are_preserved_when_adding_resources(self):
        html = part(extra=b"Content-ID: <root@existing.test>\r\n")
        params = b'; start="<root@existing.test>"; type="text/html"'
        message = HEADER + multipart([html], b"related", params=params)
        result = self.apply(message)
        self.assertTrue(result.changed, result.reason)
        self.assertIn(params, result.message)
        self.assertEqual(2, len(result.added_cids))
        self.check_html_and_resources(result)
        images = [part(entry.data, b"image/png", (f'Content-ID: <image{i}@existing.test>\r\n').encode()) for i, entry in enumerate(self.media)]
        message = HEADER + multipart([html] + images, b"related", params=params)
        self.assertTrue(self.apply(message).changed)

    def test_html_identity_without_related_container_is_not_reparented(self):
        message = HEADER + part(extra=b"Content-ID: <root@existing.test>\r\n")
        self.assert_original(message, self.apply(message))

    def test_tnef_signed_untrusted_and_bad_template_are_unchanged(self):
        for message in [HEADER + part(media=b"application/ms-tnef"),
                        HEADER + multipart([part()], b"signed"),
                        HEADER + b"DKIM-Signature: intact\r\n" + part(),
                        HEADER + b"X-RailTime-Transit: pending\r\n" + part(),
                        HEADER + HEADER + part(), part()]:
            self.assert_original(message, self.apply(message))
        message = HEADER + part()
        self.assert_original(message, self.apply(message, trusted=False))
        self.assert_original(message, self.apply(message, sender="other@example.test"))

    def test_media_is_pinned_not_fetched_and_mismatch_is_complete_original(self):
        message = HEADER + part()
        for media in [[], [AdmittedMedia(self.media[0].source, b"changed")],
                      [AdmittedMedia("https://foreign.test/m/b983af0049.png", self.media[0].data)],
                      [self.media[0], self.media[0]]]:
            self.assert_original(message, self.apply(message, media=media))

    def test_callback_failure_no_change_and_missing_cid_leave_complete_original(self):
        message = HEADER + part()
        def failing(*args):
            raise RuntimeError("private contents must never be exposed")
        for callback in [failing, lambda html, *_: HtmlResult(html, False, "quoted_only"),
                         lambda html, *_: HtmlResult(html.replace("OLD", "NEW"), True, "transformed")]:
            self.assert_original(message, self.apply(message, callback=callback))

    def test_unsupported_charset_and_ambiguous_html_are_unchanged(self):
        for message in [HEADER + part(media=b"text/html; charset=windows-1252"),
                        HEADER + multipart([part(), part()])]:
            self.assert_original(message, self.apply(message))

    def test_actual_b1_php_bridge_and_mime_adapter_end_to_end_without_kernel_or_transport(self):
        php = shutil.which("php") or "C:/xampp/php/php.exe"
        if not Path(php).is_file():
            self.skipTest("Local PHP CLI not installed")
        root = Path(__file__).resolve().parents[2]
        code = r'''
require 'vendor/autoload.php';
$app = new Illuminate\Foundation\Application(getcwd());
$app->instance('config', new Illuminate\Config\Repository(['app'=>['url'=>'https://app.rail-time.de']]));
$descriptor = ['sourceHash'=>hash('sha256','synthetic integration'), 'kind'=>'employee',
'identity'=>['name'=>'Alex Beispiel','role'=>'IT-TECHNIK'],
'direct'=>[['type'=>'email','value'=>'alex@example.invalid','label'=>'alex@example.invalid']],
'company'=>['name'=>'Muster GmbH','website'=>'https://example.invalid','websiteLabel'=>'example.invalid',
'street'=>'Musterweg 1','postalCity'=>'10115 Berlin','registration'=>'HRB 123','confidentiality'=>'Synthetic'],
'dispatch'=>['email'=>'dispo@example.invalid','whatsapp'=>'https://wa.me/491601881848',
'phone'=>'+491601881839','phoneLabel'=>'+49 160 1881839']];
$author = '<p>AUTHOR-BYTES &amp; <img src="https://app.rail-time.de/m/b983af0049.png"></p>';
$quote = '<blockquote>QUOTE-BYTES <img src="cid:old@quote.test"></blockquote>';
$renderer = new App\Support\OutlookAddin\OutlookUniversalPresentation;
$projection = $renderer->render($descriptor, new Illuminate\Support\HtmlString(''), ['mode'=>'send']);
$editor = $renderer->render($descriptor, new Illuminate\Support\HtmlString($author), ['mode'=>'editor']);
echo json_encode(['projection'=>$projection,'html'=>'<html><head><meta charset="utf-8"></head><body>'.$editor['html'].$quote.'</body></html>',
'author'=>$author,'quote'=>$quote], JSON_THROW_ON_ERROR);
'''
        created = subprocess.run([php, "-r", code], cwd=root, capture_output=True, timeout=15, check=True)
        fixture = json.loads(created.stdout)
        projection = fixture["projection"]
        media = [AdmittedMedia(entry["url"], (root / "public/m" / entry["url"].rsplit("/", 1)[-1]).read_bytes())
                 for entry in projection["media"]]
        message = (b"X-RailTime-Template: " + projection["header"].encode() + b"\r\nMessage-ID: <synthetic@thread.test>\r\n"
                   + multipart([part(b"Plain remains opaque", b"text/plain; charset=utf-8"),
                                part(fixture["html"].encode())], b"alternative"))

        def callback(html, headers, urls):
            request = {"html": html, "headers": headers, "projection": projection, "cidUrls": urls,
                       "offlineTrustedFixture": True, "envelopeSender": PILOT}
            completed = subprocess.run([php, str(root / "scripts/mail/railtime_transit_cid_html.php")],
                                       input=json.dumps(request).encode(), capture_output=True, timeout=15, check=True)
            result = json.loads(completed.stdout)
            return HtmlResult(result["html"], result["changed"], result["reason"])

        result = transform_message_with_cid(message, callback, media, trusted_ingress=True, envelope_sender=PILOT)
        self.assertTrue(result.changed, result.reason)
        self.assertEqual(len(media), len(result.added_cids))
        parsed = BytesParser(policy=policy.default).parsebytes(result.message)
        html = next(p for p in parsed.walk() if p.get_content_type() == "text/html").get_payload(decode=True)
        self.assertEqual(1, html.count(fixture["author"].encode()))
        self.assertEqual(1, html.count(fixture["quote"].encode()))
        self.assertIn(b'name="viewport"', html)
        self.assertEqual(3, html.count(b"@media"))
        self.assertIn(part(b"Plain remains opaque", b"text/plain; charset=utf-8"), result.message)
        pngs = [p for p in parsed.walk() if p.get_content_type() == "image/png"]
        self.assertEqual(len(media), len(pngs))
        for item in media:
            self.assertIn(item.data, [p.get_payload(decode=True) for p in pngs])


if __name__ == "__main__":
    unittest.main()
