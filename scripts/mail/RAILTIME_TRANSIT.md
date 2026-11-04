# U2e E2: inactive offline transformation

This is a tested library and file runner, **not an installed SMTP service**.
There is no route, listener, Postfix configuration, Exchange connector, queue,
mailbox operation, Graph permission or send operation in this change. E3 still
requires the user's separate approval after its remaining contracts are proved.

## HTML boundary

`OutlookUniversalTransitTransformer::transform()` is an instance method taking
HTML, headers, a **trusted admitted B1 `mode=send` projection**, caller-proved
ingress, and the authenticated envelope sender. It accepts only the IT pilot
`it-technik@rail-time.de`. Exactly one `X-RailTime-Template` must match the
projection's version. Neither a message header nor HTML geometry proves identity,
publication or tenant provenance; the eventual caller must resolve those.

The existing candidate reader selects the one current four-row 1040 px block
by structure, even when Word removed its IDs. Quoted blocks are excluded. The
transformer replaces that block with B1's send projection while transplanting
the **entire original author-cell inner bytes**, including already written
greeting and farewell. It neither recreates that wording nor serializes the
incoming DOM. The body prefix and complete suffix, including quotes and closing
tags, retain their original bytes. Original HEAD bytes retain order; missing B1
metas and one style block are inserted before `</head>`. Conflicting metas or an
ambiguous/missing HEAD leave the original unchanged.

The two pinned B1 media rules are scoped to a collision-free class on the new
own root table. Quoted `.rt-*` classes remain outside it. A scope match makes a
repeat call unchanged. The U2 template, editor projection, Outlook bundles,
contacts, links and existing insertion/readback behavior are not modified.

## MIME boundary

`railtime_transit.transform_message()` accepts explicit caller trust and the
authenticated IT envelope. It never derives trust from From/Received/IP/headers.
The parser uses original byte offsets and Python's header parser, never a full
email serialization. Only **one active HTML body's encoded payload** may change;
all original envelope-independent message headers, other parts, attachments,
preambles, epilogues and MIME delimiters stay byte-identical. `multipart/related`
honors its root (`start`, otherwise first child) and any declared type; HTML
resources are not treated as the body. Encapsulated attached messages stay opaque.

Supported initially: SMTP CRLF headers; mixed/alternative/related multiparts;
UTF-8 or ASCII HTML; base64/quoted-printable and conservative 7bit/8bit bodies.
The **decoded HTML** preserves author/quote bytes. Base64 or quoted-printable
may be re-encoded inside that HTML part, so its wire-encoded quote substring is
not claimed byte-identical. Tests assert decoded equality separately from the
raw unchanged remainder of the MIME message.

The complete original is returned for unsupported/ambiguous/malformed input,
missing/invalid/duplicate headers, another sender, failed callback, size/depth
limits, signed/encrypted content, TNEF and unproved transit-loop state. Existing
DKIM/ARC also require a separately proved re-signing contract before alteration.
There is no TNEF extractor or writer. The received Word HTML/MAPI exports 23:33
and 03:00 do **not** constitute original SMTP/MIME input proof.

This library's unchanged return is not a delivery guarantee. A crashed/unavailable
service or SMTP hop can queue mail; no immediate fallback delivery is promised.
The eventual adapter needs a durable accept/reinject/acknowledge contract and a
trusted completion state for both changed and unchanged messages to avoid loops.

## Offline runner

`railtime_transit_offline.py` explicitly simulates trusted fixture admission.
Its PHP subprocess loads Composer plus a minimal Laravel application, without
the kernel, environment, DB, queue or network bootstrap. A local projection file
must contain an admitted B1 send result; mail-derived JSON is never a valid
production source. Keep private projections and transformed mail out of Git.

Example from the repository root (local files only):

```powershell
python scripts/mail/railtime_transit_offline.py --mode mime --input sample.eml --output result.eml --projection admitted-send.json --php C:/xampp/php/php.exe --offline-trusted-fixture
```

For `--mode html`, also provide `--template u2e-<24-lowercase-hex-version>`.
Output uses exclusive file creation and refuses any existing destination. The
input is never overwritten. Stdout contains only status, lengths and hashes;
private input and subprocess errors are not logged. No SMTP command is called.

Focused checks:

```powershell
$env:PYTHONDONTWRITEBYTECODE='1'
python -m unittest discover -s scripts/mail -p test_railtime_transit.py -v
C:/xampp/php/php.exe vendor/bin/phpunit tests/Unit/OutlookUniversalTransitCandidateTest.php tests/Unit/OutlookUniversalTransitTransformerTest.php
```

## Remaining release boundaries

E2 is not a complete live transport service until the actual connector's MIME/
TNEF format, tenant/TLS/envelope proof, version resolution, durable queue, loop
completion and reinjection have been implemented and tested. No global format
or remote-domain setting is changed to manufacture a positive test.

E1 currently keeps the original Editor copy in Sent Items. Graph MIME create
documents draft creation. Microsoft's newer `Mail-Advanced.*` contract describes
non-draft body updates, a possible separate feasibility path; tenant permission,
item status and preservation of U2e HEAD/CID/quote content remain unproved. This
change performs neither an import nor an update or permission grant.

Sources: [Python header parsing](https://docs.python.org/3.12/library/email.parser.html),
[MIME multipart boundaries, RFC 2046](https://www.rfc-editor.org/rfc/rfc2046.html),
[Related root selection, RFC 2387](https://www.rfc-editor.org/rfc/rfc2387.html),
[Microsoft non-draft updates](https://devblogs.microsoft.com/microsoft365dev/graph-api-updates-to-sensitive-email-properties/),
[Microsoft MIME draft creation](https://learn.microsoft.com/en-us/graph/api/user-post-messages?view=graph-rest-1.0).
