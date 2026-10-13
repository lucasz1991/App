"""Generate a sharded public GeoNames display-only candidate index; never query a provider."""
import argparse
import collections
import hashlib
import json
import pathlib
import re

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument("source", type=pathlib.Path, help="Previously downloaded public GeoNames DE.txt")
parser.add_argument("--maps", type=pathlib.Path, default=pathlib.Path(__file__).resolve().parents[1] / "resources/data/maps")
args = parser.parse_args()
target = args.maps / "germany-location-candidates"
target.mkdir(exist_ok=True)


def normalize(value):
    value = value.lower().translate(str.maketrans({"ä": "ae", "ö": "oe", "ü": "ue", "ß": "ss"}))
    value = re.sub(r"[^a-z0-9]+", " ", value).strip()
    for pattern, replacement in [(r"\b(?:oberpf|opf)\b", "oberpfalz"), (r"\bunterfr\b", "unterfranken"), (r"\boberfr\b", "oberfranken"), (r"\bweinstr\b", "weinstrasse"), (r"\bi d\b", "in der"), (r"\ba d\b", "an der"), (r"\bu(?= teck\b)", "unter")]:
        value = re.sub(pattern, replacement, value)
    return value


def names(row):
    values = [row[1], row[2]]
    values.extend(value for value in row[3].split(",") if re.fullmatch(r"[A-Za-zÀ-ÖØ-öø-ÿĀ-ž .()/'’\-]{3,120}", value) and not (value.isupper() and len(value) <= 5))
    return list(dict.fromkeys(value for value in values if normalize(value)))


rows = [line.split("\t") for line in args.source.read_text(encoding="utf-8").splitlines()]
modern = {"PPL", "PPLA", "PPLA2", "PPLA3", "PPLA4", "PPLA5", "PPLC", "PPLCD", "PPLF", "PPLG", "PPLL", "PPLR", "PPLS", "PPLX", "STLMT"}
municipalities = {}
for row in rows:
    if row[8] != "DE" or not row[13] or row[6:8] != ["A", "ADM4"]:
        continue
    municipalities[row[13]] = {"name": row[1], "names": set(normalize(value) for value in row[1:3])}
for row in rows:
    if row[8] == "DE" and row[13] and row[7] in {"PPLC", "PPLA", "PPLA2", "PPLA3", "PPLA4", "PPLA5"}:
        municipality = municipalities.setdefault(row[13], {"name": row[1], "names": set()})
        municipality["names"].update(normalize(value) for value in row[1:3])

index = collections.defaultdict(dict)
point_count = 0


def add(name, point, quality):
    key = normalize(name)
    if not key or len(key) > 160 or re.fullmatch(r"[0-9 ]+", key):
        return
    previous = index[key].get(point[0])
    if previous is None or previous[-1] > quality:
        index[key][point[0]] = point + [quality]


for row in rows:
    kind = row[7]
    if row[8] != "DE" or not ((row[6] == "P" and kind in modern) or (row[6] == "S" and kind in {"RSTN", "RSTP"}) or (row[6] == "R" and kind == "RYD")):
        continue
    point_count += 1
    point = [int(row[0]), row[1], float(row[4]), float(row[5]), kind, int(row[14] or 0), row[13]]
    municipality = municipalities.get(row[13])
    for position, name in enumerate(names(row)):
        quality = 0 if position < 2 else 2
        add(name, point, quality)
        # Grammar-preserving regional qualifiers: Neumarkt in der Oberpfalz ->
        # Neumarkt (Oberpfalz), Kirchheim unter Teck -> Kirchheim (Teck).
        qualified = re.sub(r"\b(?:in der|an der|am|bei|unter)\b", " ", normalize(name))
        if qualified != normalize(name):
            add(qualified, point, max(1, quality))
        if kind in {"RSTN", "RSTP", "RYD"}:
            short = re.sub(r"^(?:bahnhof|haltepunkt|station|gueterbahnhof|hauptgueterbahnhof) ", "", normalize(name))
            add(short, point, max(1, quality))
            if municipality:
                for prefix in municipality["names"]:
                    if short.startswith(prefix + " "):
                        add(short[len(prefix) + 1:], point, max(3, quality))
        if municipality and position < 2:
            for prefix in municipality["names"]:
                if prefix != normalize(name) and not normalize(name).startswith(prefix + " "):
                    add(prefix + " " + normalize(name), point, 1)

shards = [dict() for _ in range(64)]
for key, values in sorted(index.items()):
    shard = int(hashlib.sha256(key.encode("utf-8")).hexdigest()[:2], 16) % 64
    shards[shard][key] = [values[ident] for ident in sorted(values)]
files = {}
for shard, values in enumerate(shards):
    name = f"{shard:02x}.json"
    payload = (json.dumps(values, ensure_ascii=False, separators=(",", ":")) + "\n").encode("utf-8")
    (target / name).write_bytes(payload)
    files[name] = {"bytes": len(payload), "sha256": hashlib.sha256(payload).hexdigest()}
manifest = {"version": 1, "source_sha256": hashlib.sha256(args.source.read_bytes()).hexdigest(), "point_count": point_count, "name_count": len(index), "shards": files,
            "municipalities": {code: {"name": value["name"], "names": sorted(value["names"])} for code, value in sorted(municipalities.items())}}
(target / "manifest.json").write_text(json.dumps(manifest, ensure_ascii=False, separators=(",", ":")) + "\n", encoding="utf-8")
print(json.dumps({"points": point_count, "names": len(index), "shards": 64, "bytes": sum(item["bytes"] for item in files.values()) + (target / "manifest.json").stat().st_size, "source_sha256": manifest["source_sha256"]}))
