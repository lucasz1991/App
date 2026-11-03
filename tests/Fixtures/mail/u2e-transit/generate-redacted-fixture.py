"""Derive public structural evidence; never copy business text or real URLs."""
import argparse
import hashlib
import html
import json
import pathlib
import re
from collections import Counter
from html.parser import HTMLParser

SOURCE_SHA = "ad5a44eb84a5eb40d421c7d1ebb7fac2f4f088f9ef14a72d8e4c21e97c498b49"
HEADERS_SHA = "6cd3ebb72515e09d7a51ee2393b06706553989e8990cb5b02be205344f62fe1f"
VERSION = "111111111111111111111111"
VOID = {"img", "br", "hr", "meta", "link", "input", "col", "area", "base", "embed", "param", "source", "wbr"}
TAGS = {"html", "body", "div", "p", "span", "a", "table", "tbody", "tr", "td", "th", "img", "br", "hr", "b", "strong", "i", "em", "font", "o:p", "blockquote"}


class Redactor(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.output = ["<!doctype html>\n"]
        self.skip = 0
        self.counts = Counter()
        self.ids = Counter()
        self.images = self.links = 0

    def handle_starttag(self, tag, attributes):
        attrs = dict(attributes)
        if self.skip or tag in {"head", "style", "script"}:
            if tag not in VOID:
                self.skip += 1
            return
        self.counts[tag] += 1
        if re.fullmatch(r"(?:x_)?rt-sig-u2-[0-9a-f]{24}(?:-cell)?", attrs.get("id", "")):
            self.ids[tag] += 1
        if tag not in TAGS:
            return
        safe = {}
        for key, value in attrs.items():
            if value is None:
                continue
            if key in {"width", "height"} and re.fullmatch(r"\d+(?:\.\d+)?(?:px|pt|%)?", value, re.I):
                safe[key] = value
            elif key in {"rowspan", "colspan", "border", "cellspacing", "cellpadding"} and re.fullmatch(r"\d{1,2}", value):
                safe[key] = value
            elif key == "align" and value.lower() in {"left", "center", "right"}:
                safe[key] = value
            elif key == "valign" and value.lower() in {"top", "middle", "bottom"}:
                safe[key] = value
            elif key == "bgcolor" and re.fullmatch(r"#[0-9a-f]{3}(?:[0-9a-f]{3})?", value, re.I):
                safe[key] = value
            elif key == "id" and re.fullmatch(r"(?:x_)?rt-sig-u2-[0-9a-f]{24}(?:-cell)?", value):
                safe[key] = re.sub(r"[0-9a-f]{24}", VERSION, value)
            elif key == "class":
                tokens = [v for v in value.split() if re.fullmatch(r"(?:rt-[a-z0-9_-]+|Mso[A-Za-z0-9]+|WordSection\d+)", v)]
                if tokens:
                    safe[key] = " ".join(tokens)
            elif key == "style":
                declarations = []
                for part in value.split(";"):
                    name, colon, content = part.partition(":")
                    if colon and name.strip().lower() in {"width", "height", "margin", "margin-left", "margin-right"} and re.fullmatch(r"[0-9. %a-z-]+", content.strip(), re.I):
                        declarations.append(name.strip() + ":" + content.strip())
                if declarations:
                    safe[key] = ";".join(declarations) + ";"
        if tag == "img":
            self.images += 1
            safe["src"] = f"https://assets.example.test/u2e/image-{self.images:02}.png"
            safe["alt"] = "redacted"
        if tag == "a" and "href" in attrs:
            self.links += 1
            safe["href"] = f"https://example.test/redacted-link-{self.links:02}"
        encoded = "".join(f' {key}="{html.escape(value, quote=True)}"' for key, value in safe.items())
        self.output.append("<" + tag + encoded + ">")

    def handle_endtag(self, tag):
        if self.skip:
            self.skip -= 1
        elif tag in TAGS and tag not in VOID:
            self.output.append("</" + tag + ">")

    def handle_data(self, data):
        if not self.skip:
            self.output.append("\n" if data.isspace() and "\n" in data else " " if not data.strip() else "REDACTED")


def generate(source_path, headers_path, output):
    raw, headers_raw = source_path.read_bytes(), headers_path.read_bytes()
    assert hashlib.sha256(raw).hexdigest() == SOURCE_SHA, "Unreviewed source artifact"
    assert hashlib.sha256(headers_raw).hexdigest() == HEADERS_SHA, "Unreviewed header artifact"
    headers = headers_raw.decode("utf-8-sig")
    values = re.findall(r"(?im)^\s*x-railtime-template\s*:\s*(u2e-[0-9a-f]{24})\s*$", headers)
    assert len(values) == 1, "Missing or ambiguous source header"
    redactor = Redactor()
    redactor.feed(raw.decode("utf-8-sig"))
    fixture = "".join(redactor.output).strip() + "\n"
    assert fixture.isascii() and redactor.counts["table"] == 10 and redactor.images == 13
    output.mkdir(parents=True, exist_ok=True)
    (output / "received-classic-2333.redacted.html").write_text(fixture, encoding="ascii", newline="\n")
    safe_headers = f"X-RailTime-Template: u2e-{VERSION}\nContent-Type: text/html; charset=utf-8\n"
    (output / "received-classic-2333.headers.txt").write_text(safe_headers, encoding="ascii", newline="\n")
    manifest = {"schema": 1, "derivation": "structural-redaction-v1", "sourceHtmlSha256": SOURCE_SHA,
                "sourceHeadersSha256": HEADERS_SHA, "sourceHeaderValueSha256": hashlib.sha256(values[0].encode()).hexdigest(),
                "sourceHtmlBytes": len(raw), "sourceTableCount": 10, "sourceImageCount": 13,
                "sourceShellIdCount": redactor.ids["table"], "sourceCellIdCount": redactor.ids["td"],
                "fixtureHtmlSha256": hashlib.sha256(fixture.encode()).hexdigest(),
                "fixtureHeadersSha256": hashlib.sha256(safe_headers.encode()).hexdigest(),
                "allTextReplaced": True, "allUrlsReplaced": True, "headCommentsAndUnknownAttributesRemoved": True,
                "pixelOrNativeClientProof": False}
    (output / "manifest.json").write_text(json.dumps(manifest, indent=2) + "\n", encoding="ascii", newline="\n")
    print(json.dumps({"generated": True, "tableCount": 10, "imageCount": 13, "fixtureHash": manifest["fixtureHtmlSha256"]}))


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("html", type=pathlib.Path)
    parser.add_argument("headers", type=pathlib.Path)
    parser.add_argument("output", type=pathlib.Path)
    args = parser.parse_args()
    generate(args.html, args.headers, args.output)
