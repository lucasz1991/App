# Local Germany preview data

This data powers the timeline event's small Germany overview. Its marker is an
approximate locality, city-centre, railway or postcode estimate, **not** a
verified address, worksite or navigation target.
There is no runtime network lookup. The helper reads only the shift location and
its already-loaded order's location, city, postal code and country; it never reads customer
addresses or private notes. Only immutable public data is cached.

## GeoNames attribution and transformation

- Source: [GeoNames Germany extract](https://download.geonames.org/export/dump/DE.zip), downloaded 2026-10-06.
- Copyright attribution: GeoNames and its contributors; [CC BY 4.0](https://creativecommons.org/licenses/by/4.0/).
- [Upstream format and license](https://download.geonames.org/export/dump/readme.txt), [feature-code definitions](https://www.geonames.org/export/codes.html).
- The `DE.zip` SHA-256 is `bc05cc8c6191d74bcde39edb0b44e586ebebe698a84aaf5f5c13482f74e5a25e`.

`germany-places.json` is a derived exact-name index. It keeps country `DE`, feature
class `P` and modern codes `PPL`, `PPLA`, `PPLA2`, `PPLA3`, `PPLA4`, `PPLA5`,
`PPLC`, `PPLCD`, `PPLF`, `PPLG`, `PPLL`, `PPLR`, `PPLS`, `PPLX`, `STLMT`. Historical,
abandoned and destroyed places are excluded. Potential markers are limited to
places with at least 500 inhabitants or codes `PPLA`, `PPLA2`, `PPLA3`, `PPLA4`,
`PPLC`. The complete modern-place source, including tiny villages and sections,
still participates in collision detection for those retained keys. Names absent
from the retained subset are not stored solely to expose a collision state.

Keys come only from the original `name` and `asciiname` columns, lowercased with
whitespace trimmed/collapsed. Values are `[geonameid, canonical name, latitude,
longitude]`, using the source WGS84 coordinates unchanged. `null` means more than
one locality shares that key. Alternate names never produce searchable keys or
markers: they can only block a key if it also names another locality. This base
index performs no fuzzy matching or popularity-based disambiguation. The small,
separate reviewed fallback layer below intentionally handles common city names
and bounded operational location qualifiers without altering this source index.

The snapshot checks 82,227 source places and retains 11,920 eligible places as
12,892 exact keys: 9,831 unique keys and 3,061 ambiguous keys. A further 160
otherwise unique primary/ASCII keys were blocked by another place's alternate
name (included in the 3,061). The index is 604,074 bytes. Berlin has a namesake village;
München has two namesake villages, while the large city's canonical source name
is Munich. `München`, `Munchen` and `Muenchen` have no retained exact key and are
unknown; `Berlin` is ambiguous **in this base index**. The user-approved reviewed
city layer now resolves those names to the large city's approximate centre.
`Treuchtlingen` still resolves exactly; `Treuchlingen` is the one deliberately
reviewed spelling alias. Other unknown or ambiguous names remain without a marker.

## Reviewed city-centre fallback (2026-10-06)

`germany-city-centres.json` contains eight reviewed GeoNames city records from
the same cached, hashed `DE.zip` extract. Coordinates are copied unchanged from
the populated-place record, not inferred from an address or a neighbouring city.
The UI calls these **Stadtmitte · ungefähr** to distinguish them from an exact
worksite. This is the approximate gazetteer city point, not a surveyed centre.

| Display city | GeoNames ID | WGS84 latitude, longitude |
| --- | --- | --- |
| München | 2867714 | 48.13743, 11.57549 |
| Berlin | 2950159 | 52.52437, 13.41053 |
| Neumünster | 2864475 | 54.07399, 9.98456 |
| Nürnberg | 2861650 | 49.45421, 11.07752 |
| Schwandorf | 2835297 | 49.32534, 12.10980 |
| Hamburg | 2911298 | 53.55073, 9.99302 |
| Leipzig | 2879139 | 51.33962, 12.37129 |
| Köln | 2886242 | 50.93333, 6.95000 |

German/English/ASCII city aliases come from the corresponding source record's
name, ASCII name or alternate names. Display names use the German city name.
The explicit `Nürnberghafen`/ASCII spellings are a reviewed operational spelling
of “Nürnberg Hafen”, not claimed to be source aliases. They point to Nürnberg's
centre, never a supposed port position. The sole reviewed typo `Treuchlingen`
uses the original `Treuchtlingen` locality point (2821254), keeping the label
**Ortslage · ungefähr**. There is no general edit-distance correction.

The reviewed district qualifiers share the city record's GeoNames administrative
codes: München–Milbertshofen (2871160), Freimann (2925065), Laim (2881846);
Hamburg–Eidelstedt (7274677), Hasselbrook station (2909401); Leipzig–Plagwitz
(2853474); Köln–Eifeltor station (2886236), Worringen station (2806106).
These qualifiers require their explicit city prefix. In particular, ambiguous
standalone district names do not acquire a city from this list.

Resolution order is: explicit country/input checks and existing shift-overrides;
reviewed whole city alias; whole exact locality/one reviewed spelling alias;
longest bounded city/locality prefix plus an allowlisted station, compass or
reviewed district qualifier. Supported separators are spaces, hyphens/dashes
and commas; suffixes such as Bahnhof/Hbf/Gbf/Rbf/Hafen/Nord and the explicit
“Hafen (Rail One)” are recognized. This is not unrestricted substring matching.
`München Milbertshofen`, `München Nord`, `München Freimann`, `München Laim`
all visibly point to München's approximate centre, not to the named sublocation.
`München / Hamburg`, foreign suffixes, unrelated street addresses, unknown
districts and unreviewed ambiguous names remain unlocated. No geographically
nearest point can be chosen when the input supplies no reliable geographic anchor.

All existing safeguards remain: explicit foreign order country blocks matching,
an overridden shift location cannot borrow the order's city/country, malformed
supplied values cannot enable fallback, and there is no customer/private-address
lookup, database query or runtime network call. Only immutable public map data
is cached. The original assignment/location text is never rewritten.

## Map-only railway, district and postcode fallbacks (2026-10-09)

`germany-location-aliases.json` extends display resolution without changing the
original place index or the eight reviewed centres. Its source is the same
2026-10-06 GeoNames Germany extract and hash documented above. It contains
13,224 keys (13,193 unique points and 31 blocked collisions), 994,394 bytes.

- Modern railway features `RSTN` and `RSTP` contribute exact primary, ASCII and
  source alternate names. Short railway codes and numeric IDs are excluded.
  `Hbf`, `Hbf.` and `Hauptbahnhof` are deterministic spelling equivalents only
  where the source actually supplies that station qualifier. Historic/abandoned
  station features are excluded; populated-place collisions block railway aliases.
- District features `PPLX` contribute their primary/ASCII names with an explicit
  already-known city prefix. A city must have an unambiguous source point or be
  one of the eight reviewed centres. It shares all supplied administrative
  municipality codes with the district/station, and is within 35 km of its source
  coordinate. A missing fourth-level code can inherit a city only when the
  third-level code represents an independent city (`admin4 = admin3 + 000`).
  Sharing an arbitrary rural county never makes a neighbouring town the anchor.
  Multiple anchors remain blocked; there is no population or nearest-city ranking.
- An explicit administrative city anchor yields its original approximate city
  or locality point. An otherwise unique railway name uses the public source
  station point, labelled **Bahnhofslage · ungefähr**, never an exact worksite.
  Equivalent records for the same known municipality share the city point.
- The reviewed `Frankfurt (Main)` spelling uses the locality point 2925533
  (Frankfurt am Main, 50.11552, 8.68417), whose source alternate includes
  `Frankfurt/Main`. Parenthetical spacing is normalized for that name and the
  exact `Frankfurt (Oder)` source point 2925535. `Frankfurt (Main) Hbf` therefore
  stays distinct from `Frankfurt (Oder) Hbf`; unqualified `Frankfurt` stays unlocated.

Existing known city/locality resolution wins. A bare ambiguous place cannot
acquire a marker from a district or station alias. A source-backed qualified
railway name may resolve even when its city's bare name is ambiguous. Normalized
parenthetical spacing and dashes retain collision detection. Source-backed names
may carry the same bounded station qualifiers described above; routes, unknown
districts, arbitrary suffixes and foreign qualifiers cannot create a marker.

`germany-postcodes.json` derives from the official [GeoNames Germany postal
extract](https://download.geonames.org/export/zip/DE.zip), downloaded 2026-10-09.
Source SHA-256: `a95090d8352f80c91a5e80967c043c077ac072b2ab329d58f747f8238a75b43c`.
[Postal format and licence](https://download.geonames.org/export/zip/readme.txt):
GeoNames and contributors, CC BY 4.0. The snapshot contains 10,814 five-digit
German codes and 23,298 distinct name/coordinate candidates (949,721 bytes).
Coordinates are upstream estimates, not verified address coordinates.

Each code retains all distinct `(place name, latitude, longitude)` rows, sorted
deterministically. A supplied city must match the source postal place or an
already-resolved canonical city exactly. A known city keeps its existing city
point. Otherwise exactly one matching candidate yields **PLZ-Ortslage · ungefähr**;
multiple candidates remain ambiguous. A unique postcode alone can anchor a vague
worksite label, but cannot overwrite a recognizable conflicting location or city.
`86641 Rain` and `DE-86641 Rain` use the same safe matching. No city is inferred
from a customer's or employee's private address, inquiry contact, original text,
order association, or notes. Inquiry maps read only their explicit `location_name`.
Shift overrides cannot borrow an unrelated order's postcode/city/country.

These extensions are for map display only. `forRegionalAssessment()` retains
the original resolver, and the regional service deliberately uses it for both
worksite assessment and saved-area validation. New railway/district/postcode
markers do not change regional ranking, configuration acceptance or No-Go blocks.

Regenerate the alias data from modern GeoNames rows with the exact filters above,
merge each spelling by its resulting point ID, and keep multiple distinct point
IDs as `null`. For postal data, retain all distinct German five-digit source
candidates. Record the source hashes/counts and run `TimelineLocationPreviewTest`
and `StaffRegionalPreferenceServiceTest` after any refresh. The reproducible local
generation helper is recorded with this change's `.lmzdev` report.

## Natural Earth outline and projection

- Source: [Natural Earth 1:110m admin 0 countries](https://raw.githubusercontent.com/nvkelso/natural-earth-vector/master/geojson/ne_110m_admin_0_countries.geojson), downloaded 2026-10-06; feature `ADMIN=Germany`.
- License: [public domain](https://www.naturalearthdata.com/about/terms-of-use/).
- Source SHA-256: `6866c877d39cba9c357620878839b336d569f8c662d3cfab4cb1dbe2d39c977f`.

`germany-outline.json` retains Germany's source polygon and its bounds.
Both outline and points use the **same** equirectangular projection: longitude
scaled by `cos(51°)`, latitude inverted for SVG, uniformly fitted and centered
within `0 0 160 176` with 12 units padding. Points are rounded to two SVG decimal
places only at display time. Natural Earth's generalized boundary omits small
features and is unsuitable for exact border, island or cadastral decisions.

To refresh, repeat these documented filters on a new Germany extract, group each
primary/ASCII key by distinct geoname IDs, keep only keys from eligible places,
apply all-source primary/ASCII and alternate-name collisions only as blockers,
and retain every collision affecting a retained key as `null`. Refresh the outline bounds
with its geometry, keep the shared projection, record new hashes/counts/date,
and run `TimelineLocationPreviewTest`. Review the separate city's explicit IDs,
names, district codes and approximate-label contract when updating its small
fallback file; never replace unreviewed ambiguity with a generic best guess.
