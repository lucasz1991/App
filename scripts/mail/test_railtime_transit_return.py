"""Local return/loop tests; no mail, network, mailbox or server configuration."""
import unittest
from railtime_transit_return import ReturnNotSafe, finalize_return

BASE = (b'Message-ID: <keep@example.test>\r\nReferences: <thread@example.test>\r\n'
        b'Subject: folded\r\n\tvalue kept\r\nContent-Type: application/ms-tnef\r\n'
        b'Content-Transfer-Encoding: base64\r\n')
BODY = b'AAEC/v8=\r\nopaque body\x00with bytes\xff'
CONTROL = (b'X-RailTime-Template: u2e-' + b'a'*24 + b'\r\n'
           b'X-RailTime-Transit-Ingress: ' + b'b'*64 + b'\r\n'
           b'X-RailTime-Transit: pending\r\nX-RailTime-Transit-Result: forged\r\n')


class ReturnTests(unittest.TestCase):
    def finish(self, data, changed=False):
        return finalize_return(data, authenticated_ingress=True, transformed=changed)

    def test_unchanged_tnef_closes_route_and_strips_secret(self):
        result = self.finish(BASE + CONTROL + b'\r\n' + BODY)
        self.assertEqual(result.removed_fields, 4)
        self.assertEqual(result.message.split(b'\r\n\r\n',1)[1], BODY)
        self.assertTrue(result.message.startswith(BASE))
        self.assertNotIn(b'X-RailTime-Template:', result.message)
        self.assertNotIn(b'b'*64, result.message)
        self.assertIn(b'X-RailTime-Transit-Result: v1; unchanged\r\n', result.message)

    def test_changed_and_unchanged_use_same_completion(self):
        for changed in (False, True):
            r = self.finish(BASE+CONTROL+b'\r\n'+BODY,changed)
            self.assertNotIn(b'X-RailTime-Template:',r.message)
            self.assertEqual(r.message.split(b'\r\n\r\n',1)[1],BODY)
            self.assertEqual(r.transformed,changed)

    def test_case_duplicates_and_folded_controls_removed_together(self):
        fields = (b'x-railtime-template: duplicate\r\n'
                  b'X-RailTime-Transit-Ingress: folded\r\n\tsecret\r\n')
        r=self.finish(BASE+CONTROL+fields+b'\r\n'+BODY)
        self.assertEqual(r.removed_fields,6)
        self.assertNotIn(b'\tsecret',r.message)
        self.assertIn(b'Subject: folded\r\n\tvalue kept',r.message)

    def test_invalid_or_untrusted_never_returns_original_as_success(self):
        for data in (b'bad',b'NoColon\r\n\r\nbody',b'Subject: bad\n\nbody',b'\tno-parent\r\n\r\nbody'):
            with self.assertRaises(ReturnNotSafe):self.finish(data)
        with self.assertRaises(ReturnNotSafe):
            finalize_return(BASE+b'\r\n'+BODY,authenticated_ingress=False,transformed=False)

    def test_dkim_covering_controls_queues_instead_of_breaking_signature(self):
        for key in (b'DKIM-Signature',b'ARC-Message-Signature'):
            signed=key+b': v=1; h=From:Subject:X-RailTime-Template; b=test\r\n'
            with self.assertRaisesRegex(ReturnNotSafe,'return_control_header_signed'):
                self.finish(signed+BASE+CONTROL+b'\r\n'+BODY)

    def test_unrelated_dkim_is_retained_without_reserialization(self):
        sig=b'DKIM-Signature: v=1; h=From:Subject; b=opaque\r\n'
        r=self.finish(sig+BASE+CONTROL+b'\r\n'+BODY)
        self.assertTrue(r.message.startswith(sig+BASE))

    def test_completion_does_not_treat_a_forged_done_as_authority(self):
        data=BASE+CONTROL+b'\r\n'+BODY
        with self.assertRaises(ReturnNotSafe):
            finalize_return(data,authenticated_ingress=False,transformed=False)

    def test_nested_control_header_and_attached_mail_remain_opaque(self):
        nested=CONTROL+b'\r\n'+BODY
        result=self.finish(BASE+CONTROL+b'\r\n'+nested)
        self.assertEqual(result.message.split(b'\r\n\r\n',1)[1],nested)

    def test_local_admission_ticket_never_leaves_the_gateway(self):
        ticket=b'X-RailTime-Local-Admission: e3p1; ticket='+b'c'*64+b'\r\n'
        result=self.finish(BASE+CONTROL+ticket+b'\r\n'+BODY)
        self.assertNotIn(b'X-RailTime-Local-Admission:',result.message)
        self.assertNotIn(b'c'*64,result.message)
        self.assertEqual(result.removed_fields,5)


if __name__=='__main__':unittest.main()
