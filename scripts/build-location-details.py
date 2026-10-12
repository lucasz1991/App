"""Build immutable, municipality-anchored display detail points from GeoNames DE.txt."""
import argparse
import collections
import hashlib
import json
import math
import pathlib
import re
import zipfile

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument("source", type=pathlib.Path, help="Unpacked public GeoNames DE.txt")
parser.add_argument("--foreign-cities", required=True, type=pathlib.Path, help="Public GeoNames cities15000.zip; blocker names only")
parser.add_argument("--maps", type=pathlib.Path, default=pathlib.Path(__file__).resolve().parents[1] / "resources/data/maps")
args = parser.parse_args()


def norm(value):
    return re.sub(r"[^\w]+", " ", value.lower(), flags=re.UNICODE).strip()


def distance(a, b):
    lat1, lat2 = map(math.radians, [float(a[4]), float(b[4])])
    h = math.sin((lat2 - lat1) / 2) ** 2 + math.cos(lat1) * math.cos(lat2) * math.sin(math.radians(float(b[5]) - float(a[5])) / 2) ** 2
    return 6371.0088 * 2 * math.asin(math.sqrt(min(1, h)))


rows = [line.split("\t") for line in args.source.read_text(encoding="utf-8").splitlines()]
places = json.loads((args.maps / "germany-places.json").read_text(encoding="utf-8"))
centres = json.loads((args.maps / "germany-city-centres.json").read_text(encoding="utf-8"))
known = {point[0]: {"point": point, "names": []} for point in places.values() if point}
for name, point in places.items():
    if point:
        known[point[0]]["names"].append(name)
reviewed = {city["point"][0]: city for city in centres["cities"]}
known.update(reviewed)
anchors, independent = collections.defaultdict(list), collections.defaultdict(list)
city_rows = {}
for row in rows:
    ident = int(row[0])
    if row[8] != "DE" or row[6] != "P" or ident not in known or not row[12]:
        continue
    if row[7] not in {"PPLC", "PPLA", "PPLA2", "PPLA3"} and ident not in reviewed:
        continue
    city_rows[ident] = row
    if row[13]:
        anchors[tuple(row[10:14])].append(ident)
    if row[13] == row[12] + "000":
        independent[tuple(row[10:13])].append(ident)

points, aliases, qualified, cities = {}, collections.defaultdict(lambda: collections.defaultdict(set)), collections.defaultdict(set), collections.defaultdict(set)
city_mentions = collections.defaultdict(set)
generic = {"mitte", "zentrum", "nord", "süd", "sued", "ost", "west", "hafen", "depot", "terminal", "bahnhof", "hauptbahnhof"}
for city_id in city_rows:
    for name in known[city_id]["names"]:
        cities[norm(name)].add(city_id)
        city_mentions[norm(name)].add(city_id)
for row in rows:
    # A known other municipality may block a detail interpretation even when
    # its own bare name is ambiguous and therefore cannot locate anything.
    if row[8] == "DE" and row[6] == "P" and row[7] in {"PPLC", "PPLA", "PPLA2", "PPLA3"}:
        for name in {norm(row[1]), norm(row[2])}:
            city_mentions[name].add(int(row[0]))
for row in rows:
    if row[8] != "DE" or not ((row[6] == "P" and row[7] == "PPLX") or (row[6] == "S" and row[7] in {"RSTN", "RSTP"})):
        continue
    anchor_ids = anchors.get(tuple(row[10:14]), []) if row[13] else independent.get(tuple(row[10:13]), [])
    if len(anchor_ids) != 1 or distance(row, city_rows[anchor_ids[0]]) > 35:
        continue
    city_id = anchor_ids[0]
    city = known[city_id]
    city_names = {norm(value) for value in city["names"]}
    ident = int(row[0])
    names = {norm(row[1]), norm(row[2])}
    # Railway alternate names have already been reviewed for the coarse map.
    # Keep readable names, never IBNR IDs or short station codes.
    if row[6] == "S":
        names.update(norm(value) for value in row[3].split(",") if re.fullmatch(r"[A-Za-zÄÖÜäöüß .()/-]{5,80}", value))
    local_names = set()
    for name in names:
        name = re.sub(r"^(?:bahnhof|haltepunkt|station) ", "", name)
        for prefix in city["names"]:
            prefix = norm(prefix)
            if name.startswith(prefix + " "):
                name = name[len(prefix) + 1:]
                break
        if name and name not in city_names:
            local_names.add(name)
    if not local_names:
        continue
    display = re.sub(r"^(?:Bahnhof|Haltepunkt|Station) ", "", row[1])
    if not any(norm(display).startswith(norm(name) + " ") for name in city["names"]):
        display = city["point"][1] + "-" + display
    points[ident] = [ident, display, float(row[4]), float(row[5]), "station" if row[6] == "S" else "district", city_id]
    for name in names | local_names:
        if len(name) >= 5 and name not in generic | city_names and not re.fullmatch(r"[a-z]{1,4}|[0-9 ]+", name):
            aliases[city_id][name].add(ident)
    for name in local_names:
        for prefix in city["names"]:
            full = norm(prefix) + " " + name
            if len(full.split()) <= 8:
                aliases[city_id][full].add(ident)
                qualified[full].add(ident)


def unique(values):
    return {name: next(iter(ids)) if len(ids) == 1 else None for name, ids in sorted(values.items())}


german_names = {norm(value) for row in rows if row[8] == "DE" and row[6] in {"P", "S"} for value in [row[1], row[2], *row[3].split(",")] if value}
foreign_names = set()
with zipfile.ZipFile(args.foreign_cities) as archive:
    for line in archive.read("cities15000.txt").decode("utf-8").splitlines():
        row = line.split("\t")
        if row[8] == "DE" or not row[17].startswith("Europe/") or (row[7] != "PPLC" and int(row[14] or 0) < 100000):
            continue
        for value in [row[1], row[2], *row[3].split(",")]:
            # Capital/large-city names only block cross-city prose. These never
            # create coordinates; names shared with ANY German feature do not
            # become foreign blockers. No airport codes, IDs or substring match.
            if re.fullmatch(r"[A-Za-zÄÖÜäöüß .()/-]{4,80}", value) and not value.isupper():
                name = norm(value)
                if name not in german_names and name not in generic:
                    foreign_names.add(name)

data = {"source_sha256": hashlib.sha256(args.source.read_bytes()).hexdigest(), "cities": unique(cities), "city_mentions": unique(city_mentions), "points": points,
        "aliases": {city: unique(values) for city, values in sorted(aliases.items())}, "qualified": unique(qualified),
        "foreign_source_sha256": hashlib.sha256(args.foreign_cities.read_bytes()).hexdigest(), "foreign_city_mentions": {name: True for name in sorted(foreign_names)}}
target = args.maps / "germany-location-details.json"
target.write_text(json.dumps(data, ensure_ascii=False, separators=(",", ":")) + "\n", encoding="utf-8")
print(json.dumps({"points": len(points), "cities": len(aliases), "qualified_names": len(qualified), "foreign_blockers": len(foreign_names), "bytes": target.stat().st_size, "source_sha256": data["source_sha256"], "foreign_source_sha256": data["foreign_source_sha256"]}))
