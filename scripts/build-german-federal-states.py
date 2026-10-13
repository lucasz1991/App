"""Extract the existing Dashboard map's state paths; never fetch new geometry."""

import hashlib
import json
from pathlib import Path
import re
import xml.etree.ElementTree as ET


ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / "public/rt-brand/illustrations/v2/germany-rail-network.svg"
TARGET = ROOT / "resources/data/maps/germany-federal-states.json"

NAMES = {
    "DE-BW": "Baden-Württemberg", "DE-BY": "Bayern", "DE-BE": "Berlin",
    "DE-BB": "Brandenburg", "DE-HB": "Bremen", "DE-HH": "Hamburg",
    "DE-HE": "Hessen", "DE-MV": "Mecklenburg-Vorpommern",
    "DE-NI": "Niedersachsen", "DE-NW": "Nordrhein-Westfalen",
    "DE-RP": "Rheinland-Pfalz", "DE-SL": "Saarland", "DE-SN": "Sachsen",
    "DE-ST": "Sachsen-Anhalt", "DE-SH": "Schleswig-Holstein", "DE-TH": "Thüringen",
}

raw = SOURCE.read_bytes()
svg = ET.fromstring(raw)
assert svg.attrib["viewBox"] == "0 0 1154 1363"
states = {}
for group in svg.iter("{http://www.w3.org/2000/svg}g"):
    code = group.attrib.get("data-state")
    if code is None:
        continue
    assert code in NAMES and code not in states and "transform" not in group.attrib
    paths = list(group.findall("{http://www.w3.org/2000/svg}path"))
    assert len(paths) == 1 and paths[0].attrib.get("fill-rule") == "evenodd"
    path = paths[0].attrib["d"]
    assert re.fullmatch(r"[MLZ0-9.\s-]+", path), "Only literal polygon segments supported"
    rings = []
    for ring in path.split("Z"):
        if not ring.strip():
            continue
        assert ring.lstrip().startswith("M")
        numbers = [float(n) for n in re.findall(r"-?\d+(?:\.\d+)?", ring)]
        assert len(numbers) >= 6 and len(numbers) % 2 == 0
        rings.append(list(zip(numbers[::2], numbers[1::2])))
    xs = [point[0] for ring in rings for point in ring]
    ys = [point[1] for ring in rings for point in ring]
    states[code] = {"name": NAMES[code], "path": path, "rings": rings,
                    "bounds": [min(xs), min(ys), max(xs), max(ys)]}

assert states.keys() == NAMES.keys()
result = {
    "source": "public/rt-brand/illustrations/v2/germany-rail-network.svg",
    "source_sha256": hashlib.sha256(raw).hexdigest(),
    "attribution": "Geometrie: deutschlandGeoJSON; Datengrundlage © GeoBasis-DE / BKG (dl-de/by-2-0); Bremerhaven © OpenStreetMap-Mitwirkende (ODbL).",
    "view_box": "0 0 1154 1363",
    "render_order": list(states),
    "states": {code: states[code] for code in NAMES},
}
TARGET.write_text(json.dumps(result, ensure_ascii=False, separators=(",", ":")) + "\n", encoding="utf-8")
print(f"Generated {TARGET.name}: {len(states)} states, {sum(len(s['rings']) for s in states.values())} rings")
