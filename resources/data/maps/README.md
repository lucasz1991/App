# Local Germany preview data

This data powers the timeline event's small Germany overview. Its marker is an
approximate locality or reviewed city-centre fallback, **not** an address,
station, worksite or navigation target.
There is no runtime network lookup. The helper reads only the shift location and
its already-loaded order's location, city and country; it never reads customer
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
