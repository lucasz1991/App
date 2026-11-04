# E3 Plesk admission and queue contract

Status: **INACTIVE / NOT INSTALLABLE**, 04.11.2026. This document and
`postfix-review.conf` are a concrete configuration review, not an installer.
The admission daemon, local ticket store/consumer and production queue caller
are missing. Existing E2 offline files do not implement these components.
No mail-flow change, service activation or mail send is authorized by these files.

## Fixed pilot scope

- Public endpoint: existing `plesk.follow-flow.de:25`, one public IPv4
  `82.165.55.230`. A second service cannot bind that same IP and port.
- Sender: authenticated Exchange rule scope `InOrganization`, exact envelope
  `it-technik@rail-time.de`, and the single `X-RailTime-Template` contract.
- Initial envelope recipient allowlist: **only** `it-technik@rail-time.de`.
  Check the complete envelope, including Bcc; do not reconstruct it from To/Cc.
- Production template remains v2. U2g is rejected evidence, not a new source.
- Return only through the actual tenant MX, currently proposed as
  `railtime-de01b.mail.protection.outlook.com`; this name needs tenant confirmation.
- E1: leave the editor copy in Sent Items. No Graph write, replacement or deletion.

## Confirmed server baseline and limits

Root's read-only SSH preflight on 04.11.2026 reported Postfix 3.8.6, one public
IPv4, Plesk's existing Milter at port 12768, no `content_filter`, public SMTP TLS
mode `may`, and `message_size_limit=10240000` bytes. Queue values: scan interval
300 seconds, minimum retry 300 seconds, maximum retry backoff 4000 seconds and
maximum queue lifetime five days. These are observed values, not configuration
changes made here. Preserve their original snapshot and recheck before release.

The E2 MIME budget is a separate limit; do not promise that every message below
the Postfix ceiling is transformable. A maximum accepted size must cover its
documented pass-through path or be rejected before SMTP acceptance.

## Required admission daemon

The new daemon must be a small before-queue Milter, separate from the
after-queue MIME transformer. It must preserve the existing Plesk Milter and
be tested in the effective Milter order. Do not replace `smtpd_milters` or install
global Microsoft-only TLS/relay restrictions from the obsolete prototype.

Admission is complete only after all of these checks:

1. Obtain peer IP and TLS state from real Postfix Milter macros, not from a
   payload `Received`, `From` or `X-*` header. Postfix exposes `client_addr`,
   `tls_version`, `cipher`, optional client-certificate fields and queue ID `i`.
   Maintain a current, verified Microsoft source-network allowlist. This only
   proves infrastructure; it is not a tenant identity.
2. Require TLS for every candidate transit transaction, even though ordinary
   Plesk SMTP retains its existing global policy.
3. Require the exact pilot envelope and the complete permitted RCPT set.
   Missing/null sender, another sender, another recipient or mixed recipients
   do not satisfy this pilot. An invalid transaction must not be accepted as
   trusted internal mail through the return connector.
4. Verify a 256-bit private admission credential inserted solely by the scoped
   rule in this tenant. Generate/provision it privately; this package contains
   no credential. Do not derive it from a tenant GUID or public template hash.
   Its wire-header name must match the final Exchange script. A matching visible
   header without the connection, envelope and rule binding is insufficient.
5. Require one unfolded ASCII credential header and exactly one syntactically
   valid template header. Reject duplicates, conflicting values, CR/LF tricks and
   preexisting local admission/result headers. Do not assume Exchange's
   `SetHeader` operation removes all injected duplicates.
6. Persist a local admission ticket atomically, with queue ID, peer/TLS context,
   exact envelope, template version, source identity, input binding and state.
   Store it outside mail content under a dedicated OS identity. A random lookup
   ticket header added by this Milter can select the after-queue pipe; the pipe
   must verify the actual local ticket before setting `trusted_ingress=True`.
7. Reject/temporarily fail invalid transit transactions before the final SMTP
   250. Fail closed when admission is unavailable. Normal Plesk local-mail
   handling must retain its existing behavior; the exact per-client/service
   merge is an implementation gate, not a blanket replacement in this review.

The credential is an explicit possession-based tenant admission mechanism. It
does not assert that Microsoft IPs, Microsoft client certificates or the
`X-MS-Exchange-CrossTenant-Id` payload cryptographically identify one tenant.
Administrators with rule access can read this credential. Restrict that access,
redact it from diagnostics, rotate it independently from template versions and
document revocation. The credential must not reach recipients, Sent Items,
ordinary logs, Git or public review exports.

**Important:** a `header_checks ... FILTER` selector cannot prove the absence of
an invalid/missing credential. A Microsoft-IP relay permit plus that selector
would permit other Microsoft tenants to relay. No such relay permission is
included while the before-queue gate is missing.

## Handoff to the content filter

The intended `milter_header_checks` selector processes only the header generated
by the admission Milter. Incoming copies of that header are rejected. A locally
persisted ticket binds it to this queue item and cannot be reused on another
message. The final ticket implementation must explicitly define how its input
hash covers trace/header additions made by cleanup and the existing Plesk
Milter; a hash from a different processing boundary is not an input proof.

Use supported Postfix pipe macros for `queue_id`, `client_address`, `sender` and
the actual recipients. Do not infer those facts from MIME. The caller runs as a
dedicated non-login account; arguments go directly to the executable, with `--`
before recipients, never through a shell. `eol=\r\n` is necessary because pipe
defaults to LF. Avoid flags that add Return-Path, Delivered-To, From_ or another
blank line. Do not use `sendmail -t`.

The production queue caller must privately capture and hash its first original
MIME input before E2 changes. Keep the exact capture boundary explicit: an
after-queue capture may include Postfix/Plesk trace headers and is not an
untouched TCP/SMTP transcript. Private captures and envelopes are not development
coordination artifacts. Reports contain hashes, part types, sizes and redacted
results only. Capture each client separately for the TNEF decision.

The caller then resolves the exact allowed B1 `send` projection for the admitted
person/template revision, invokes E2, checks CID requirements, and completes
control headers. MIME control-header completion is separate from preserving the
original payload: an unchanged payload can still need secret/route-header removal
and a trusted terminal state. Do not call the entire outgoing wire message
byte-identical if those headers changed. Preserve original authorship/quote bytes
at the documented decoded HTML boundary and keep other MIME parts opaque.

## Terminal return and loop prevention

Both transformed and unchanged messages finish through the same fixed return
route. Remove every admission credential, incoming local ticket, template route
header and `pending` header before output. Add the documented final result state.
No copyable result header alone authorizes routing, transformation or a loop
exception. A safe protocol also removes the template trigger, so a pass-through
does not remain eligible for the outbound selection. If safe header completion
cannot be guaranteed, defer rather than forwarding the secret-bearing original.

The private reinjection listener must not run the admission or HTML filter again.
It stores a `railtime-return` SMTP route to the fixed tenant MX. That return
transport has mandatory peer-verified TLS and the client certificate expected by
the actual Exchange return connector. Merely overriding `relayhost` on smtpd is
not this queue route. The listener must be reachable only through an authenticated
local handoff/OS-identity restriction; `127.0.0.1` alone does not isolate users on
a shared server. It must never be exposed through a public bind or NAT rule.

Microsoft's current add-on-service guidance requires a customer-specific
certificate name verified as an accepted domain for certificate-based return
attribution. A valid public `plesk.follow-flow.de` certificate alone does not
prove that condition. Record the actual tenant, certificate/key path, permitted
accepted-domain relation, connector type/settings and return MX before calling
the E3 package executable or ready for user approval. Connector settings must
be checked against both the scenario and current cmdlet parameter contracts.

## Queue and retry outcomes

| Situation | Required outcome |
| --- | --- |
| Unsupported MIME, TNEF, protected message, ambiguous candidate or content-level transformer error | Original payload with safe control-header completion; fixed authenticated return path. No TNEF decoding introduced. |
| Cannot save input/ticket, missing local context, pipeline crash or unconfirmed reinjection | Exit 75 (`EX_TEMPFAIL`); retain/retry the existing queue item. Never claim direct bypass. |
| Queue caller accepted output into durable reinjection queue | Exit 0; record original and return queue IDs plus a redacted outcome. |
| Return SMTP/TLS unavailable | Return queue retries using its effective settings; no direct foreign-MX delivery. |
| Plesk unreachable | Exchange's connector retry/NDR policy applies; inspect its current values and trace separately. |
| Admission daemon unavailable | Before-queue temporary failure for the affected transit path, rather than accepting unproven trust. |

Do not promise general exactly-once delivery: a crash after downstream acceptance
but before recording its acknowledgement has the normal SMTP ambiguity window.
Preserve Message-ID and durable journal states; test duplicate-handling explicitly.
Never use an old `done` header to skip admission or replay an arbitrary item.

Rollback first stops new rule-based arrivals. Preserve both the unfiltered and
return queues and the working return connector until a verified drain. Do not
delete queued mail or remove the return path while it is needed. A stopped pipe
or server does not automatically send the original straight to the recipient.

## Read-only preflight commands

The following are queries, not installation or activation commands. Their output
can contain mail addresses or certificate paths; redact reports appropriately.
Never print private key or secret-file contents.

```sh
postconf mail_version
postconf -n
postconf -M
postconf -P
postconf -m
postconf content_filter smtpd_milters smtpd_milter_maps milter_header_checks
postconf smtpd_tls_security_level smtpd_relay_restrictions smtpd_recipient_restrictions
postconf message_size_limit queue_run_delay minimal_backoff_time maximal_backoff_time maximal_queue_lifetime
postconf relayhost relay_domains transport_maps sender_dependent_relayhost_maps
postqueue -j
ss -ltnp
openssl x509 -in /etc/postfix/postfix.pem -noout -subject -issuer -dates -ext subjectAltName
```

`openssl s_client` can separately inspect public STARTTLS and peer identity,
without submitting `MAIL FROM`, `RCPT TO` or message DATA. It creates a network
connection, not an SMTP receipt or connector acceptance proof. Read-only Exchange
queries for the exact tenant, connectors, accepted domains and rules belong in
the companion Exchange contract. No `postconf -e`, `postfix reload`, `postmap`,
`postsuper`, firewall command, installer, connector activation or test send is
included here.

## Required release evidence

Admission and queue callers must first exist and pass tests for missing/wrong
credentials, duplicate/folded headers, untrusted peer, missing TLS, foreign sender,
foreign/mixed RCPT, forged terminal state, ticket replay, spool/reinjection failure,
raw CRLF capture, protected/TNEF pass-through and all secret-removal paths. Prove
the existing Plesk Milter remains effective. Prove the return connector's actual
tenant attribution. Then present the concrete configuration diff and risks for
separate user E3 approval. Real Classic/new-Outlook ingress and device acceptance
remain subsequent explicitly bounded tests, not implied by local results.

## Primary references

- [Postfix after-queue filtering](https://www.postfix.org/FILTER_README.html)
- [Milter context and failure behavior](https://www.postfix.org/MILTER_README.html)
- [Milter-added header routing](https://www.postfix.org/postconf.5.html#milter_header_checks)
- [Pipe arguments, CRLF and exit status](https://www.postfix.org/pipe.8.html)
- [Verified TLS return channels](https://www.postfix.org/TLS_README.html)
- [Postfix queue controls](https://www.postfix.org/qmgr.8.html)
- [Microsoft's add-on-service connector scenario](https://learn.microsoft.com/en-us/exchange/mail-flow-best-practices/use-connectors-to-configure-mail-flow/integrate-office-365-with-an-email-add-on-service)

No runtime installation, server configuration or mail send was performed for
these two review files.
