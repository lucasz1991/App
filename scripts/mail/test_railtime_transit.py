"""Synthetic MIME proofs only, no SMTP delivery or private mail fixtures."""
import base64
from email import policy
from email.parser import BytesParser
import quopri
import unittest

from railtime_transit import HtmlResult, PILOT, transform_message

HEADER = b"X-RailTime-Template: u2e-" + b"1" * 24 + b"\r\n"
HTML = b'<html><head></head><body>OLD<blockquote>Quote = &amp;\nopaque</blockquote></body></html>'


def part(body=HTML, cte=b"base64", media=b"text/html; charset=utf-8", extra=b""):
    encoded = base64.b64encode(body) if cte == b"base64" else quopri.encodestring(body) if cte == b"quoted-printable" else body
    return b"Content-Type: " + media + b"\r\nContent-Transfer-Encoding: " + cte + b"\r\n" + extra + b"\r\n" + encoded


def multipart(parts, subtype=b"mixed", boundary=b"test-boundary"):
    return (b"Content-Type: multipart/" + subtype + b'; boundary="' + boundary + b'"\r\n\r\nPreamble\r\n--'
            + boundary + b"\r\n" + (b"\r\n--" + boundary + b"\r\n").join(parts)
            + b"\r\n--" + boundary + b"--\r\nEpilogue\r\n")


class MimeTests(unittest.TestCase):
    def apply(self, data, callback=None, **options):
        def replace(html, headers):
            self.assertEqual("u2e-" + "1" * 24, headers["X-RailTime-Template"])
            return HtmlResult(html.replace("OLD", "NEW ä", 1), True, "transformed")
        return transform_message(data, callback or replace, trusted_ingress=options.get("trusted", True), envelope_sender=options.get("sender", PILOT))

    def unchanged(self, data, reason, **options):
        result = self.apply(data, **options)
        self.assertFalse(result.changed)
        self.assertEqual(reason, result.reason)
        self.assertIs(data, result.message)
        self.assertIsNone(result.replaced_span)

    def test_single_base64_body_keeps_headers_and_decoded_quote(self):
        data = HEADER + b"Message-ID: <original@example.test>\r\nReferences: <thread@example.test>\r\n" + part()
        result = self.apply(data)
        self.assertTrue(result.changed, result.reason)
        start, end = result.replaced_span
        self.assertEqual(data[:start], result.message[:start])
        self.assertEqual(len(data), end)
        decoded = BytesParser(policy=policy.default).parsebytes(result.message).get_payload(decode=True)
        self.assertEqual(HTML.replace(b"OLD", "NEW ä".encode()), decoded)

    def test_nested_mime_preserves_other_payloads_delimiters_headers_and_suffix_bytewise(self):
        plain = part(b"Keep plain text = 100%", b"7bit", b"text/plain; charset=us-ascii")
        image = part(bytes(range(256)), extra=b'Content-Disposition: inline; filename="icon.png"\r\nContent-ID: <icon>\r\n', media=b"image/png")
        attachment = part(b"\x00\x01opaque\xff", extra=b'Content-Disposition: attachment; filename="a.bin"\r\n', media=b"application/octet-stream")
        data = HEADER + multipart([multipart([plain, part()], b"alternative", b"nested"), image, attachment])
        result = self.apply(data)
        self.assertTrue(result.changed, result.reason)
        start, end = result.replaced_span
        self.assertEqual(data[:start], result.message[:start])
        self.assertTrue(result.message.endswith(data[end:]))
        self.assertIn(plain, result.message)
        self.assertIn(image, result.message)
        self.assertIn(attachment, result.message)

    def test_quoted_printable_keeps_mixed_decoded_line_endings_and_unicode(self):
        original = HTML.replace(b"opaque", "ä\r\n\tquoted".encode())
        # Test fixture uses legal QP and never folds its pre-existing LF into CRLF.
        encoded = b"".join(f"={byte:02X}".encode() for byte in original)
        data = HEADER + b"Content-Type: text/html; charset=utf-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n" + encoded
        result = self.apply(data)
        self.assertTrue(result.changed, result.reason)
        received = BytesParser(policy=policy.default).parsebytes(result.message).get_payload(decode=True)
        self.assertEqual(original.replace(b"OLD", "NEW ä".encode()), received)
        payload = result.message[result.replaced_span[0]:]
        self.assertTrue(all(len(line) <= 76 for line in payload.split(b"\r\n")))

    def test_trust_and_pilot_gates_precede_parsing_and_callback(self):
        self.unchanged(b"not MIME", "untrusted_ingress", trusted=False)
        self.unchanged(HEADER + part(), "outside_pilot", sender="other@example.test")
        self.unchanged(HEADER + part(), "outside_pilot", sender=" it-technik@rail-time.de")

    def test_identity_header_is_single_unfolded_exact_version(self):
        self.unchanged(part(), "header_missing")
        self.unchanged(HEADER + HEADER + part(), "header_ambiguous")
        self.unchanged(HEADER.replace(b"u2e-", b"U2E-") + part(), "header_invalid")
        self.unchanged(HEADER.replace(b"u2e-", b"\r\n u2e-") + part(), "header_invalid")
        encoded_word = b"X-RailTime-Template: =?utf-8?q?u2e-" + b"1" * 24 + b"?=\r\n"
        self.unchanged(encoded_word + part(), "header_invalid")

    def test_tnef_signed_encrypted_and_signed_transport_are_untouched(self):
        for media in (b"application/ms-tnef", b"application/vnd.ms-tnef", b"application/pkcs7-mime", b"application/pgp-encrypted"):
            with self.subTest(media=media):
                reason = "tnef_requires_ingress_proof" if b"tnef" in media else "protected_message"
                self.unchanged(HEADER + part(media=media), reason)
        for media in (b"signed", b"encrypted"):
            self.unchanged(HEADER + multipart([part(), part(media=b"application/octet-stream")], media), "protected_message")
        self.unchanged(HEADER + multipart([part(), part(media=b"application/ms-tnef")]), "tnef_requires_ingress_proof")
        for key in (b"DKIM-Signature", b"ARC-Seal", b"ARC-Message-Signature"):
            self.unchanged(HEADER + key + b": signature\r\n" + part(), "signed_transport_requires_resigning_contract")

    def test_missing_ambiguous_attached_html_and_forwarded_messages(self):
        self.unchanged(HEADER + part(media=b"text/plain"), "html_absent")
        self.unchanged(HEADER + multipart([part(), part()]), "html_ambiguous")
        self.unchanged(HEADER + part(extra=b'Content-Disposition: attachment; filename="old.html"\r\n'), "html_absent")
        self.unchanged(HEADER + part(body=HEADER + part(), media=b"message/rfc822"), "html_absent")
        data = HEADER + multipart([part(), part(body=b"UNCHANGED", extra=b'Content-Disposition: attachment; filename="old.html"\r\n')])
        self.assertTrue(self.apply(data).changed)

    def test_malformed_boundary_headers_and_encoding_return_original(self):
        self.unchanged(HEADER + multipart([part()]).replace(b"--test-boundary--", b"broken"), "mime_boundary_invalid")
        self.unchanged(HEADER + multipart([multipart([part()], boundary=b"test-boundary")]), "mime_boundary_invalid")
        self.unchanged(HEADER + b"Content-Type: text/html\r\n" + part(), "mime_headers_ambiguous")
        self.unchanged(HEADER + part().replace(b"PGh0", b"!bad"), "transfer_encoding_invalid")
        self.unchanged(HEADER + part(b"=Z1", b"quoted-printable").replace(b"=3DZ1", b"=Z1"), "transfer_encoding_invalid")
        self.unchanged(HEADER + part(media=b"text/html; charset=windows-1252"), "charset_unsupported")
        self.unchanged(HEADER + part().replace(b"base64", b"unknown"), "transfer_encoding_unsupported")

    def test_related_html_resource_is_never_the_root_body(self):
        plain = part(b"Actual body", b"7bit", b"text/plain", b"Content-ID: <main>\r\n")
        resource = part(extra=b"Content-ID: <resource>\r\n")
        data = HEADER + multipart([plain, resource], b"related")
        self.unchanged(data, "html_absent")
        declared = data.replace(b'boundary="test-boundary"', b'boundary="test-boundary"; start="<main>"; type="text/plain"')
        self.unchanged(declared, "html_absent")
        selected = data.replace(b'boundary="test-boundary"', b'boundary="test-boundary"; start="<resource>"; type="text/html"')
        self.assertTrue(self.apply(selected).changed)
        self.unchanged(selected.replace(b'start="<resource>"', b'start="<missing>"'), "related_root_invalid")
        self.unchanged(selected.replace(b'type="text/html"', b'type="image/png"'), "related_type_mismatch")
        self.unchanged(selected.replace(b"Content-ID: <main>", b"Content-ID: <resource>"), "related_root_ambiguous")

    def test_callback_failure_or_no_change_is_original_and_has_no_diagnostic_text(self):
        data = HEADER + part()
        def failing(*args):
            raise RuntimeError("private mail text")
        self.unchanged(data, "transformer_failed", callback=failing)
        self.unchanged(data, "quoted_only", callback=lambda html, headers: HtmlResult("should not be used", False, "quoted_only"))
        self.unchanged(data, "transformer_contract_invalid", callback=lambda *args: {"html": "bad"})
        self.unchanged(data, "transformer_unchanged", callback=lambda html, headers: HtmlResult(html, True, "bad"))
        self.unchanged(data, "transformer_unchanged", callback=lambda html, headers: HtmlResult(html, False, "private mail content!"))

    def test_limits_and_raw_output_guards(self):
        self.unchanged(b"x" * 10_000_001, "mime_budget")
        self.unchanged(HEADER + multipart([part()] * 101), "mime_budget")
        self.unchanged(HEADER + part(b"x" * 1_000_001), "html_budget")
        raw = HEADER + part(b"<html>OLD</html>", b"7bit", b"text/html; charset=us-ascii")
        self.unchanged(raw, "output_charset_invalid")
        raw_utf8 = raw.replace(b"us-ascii", b"utf-8")
        self.unchanged(raw_utf8, "output_encoding_invalid")
        data = HEADER + multipart([part(b"OLD", b"8bit")])
        self.unchanged(data, "output_boundary_collision", callback=lambda *args: HtmlResult("NEW\r\n--test-boundary\r\nattack", True, "transformed"))
        self.unchanged(HEADER + b"X-RailTime-Transit: pending\r\n" + part(), "transit_state_requires_ingress_proof")


if __name__ == "__main__":
    unittest.main()
