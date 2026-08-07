"""Erzeugt die Kontakt-Icons fuer die E-Mail-Vorlagen neu.

Rendert fuenf Font-Awesome-6-Glyphen als Markenrot auf transparentem Grund
und gibt sie als Base64-PNG aus. Das Ergebnis gehoert in die Konstante
App\\Support\\EmailTemplateBuilder::CONTACT_ICON_PNG.

Aufruf aus dem App-Verzeichnis:

    python scripts/build-contact-icons.py
"""

from __future__ import annotations

import base64
import io
from pathlib import Path

from PIL import Image, ImageDraw, ImageFont

APP = Path(__file__).resolve().parent.parent
FONT = APP / "public/adminresources/fontawesome6/webfonts/fa-solid-900.ttf"

BRAND_RED = (228, 0, 43, 255)
SIZE = 44          # doppelte Anzeigegroesse (22 px) fuer scharfe Darstellung
SUPERSAMPLE = 4

GLYPHS = {
    "location": "\uf3c5", # location-dot
    "phone": "",    # phone
    "mobile": "",   # mobile-screen-button
    "email": "",    # envelope
    "web": "",      # globe
}


def render(char: str) -> bytes:
    big = SIZE * SUPERSAMPLE
    image = Image.new("RGBA", (big, big), (0, 0, 0, 0))
    draw = ImageDraw.Draw(image)

    # Groesste Schriftgroesse waehlen, die vollstaendig ins Quadrat passt.
    chosen = None
    for pixels in range(big, 10, -4):
        font = ImageFont.truetype(str(FONT), pixels)
        left, top, right, bottom = draw.textbbox((0, 0), char, font=font)
        if (right - left) <= big * 0.92 and (bottom - top) <= big * 0.92:
            chosen = (font, left, top, right, bottom)
            break

    if chosen is None:
        raise RuntimeError(f"Keine passende Groesse fuer {char!r} gefunden.")

    font, left, top, right, bottom = chosen
    draw.text(
        ((big - (right - left)) / 2 - left, (big - (bottom - top)) / 2 - top),
        char,
        font=font,
        fill=BRAND_RED,
    )

    buffer = io.BytesIO()
    image.resize((SIZE, SIZE), Image.LANCZOS).save(buffer, "PNG", optimize=True)

    return buffer.getvalue()


def main() -> None:
    for name, char in GLYPHS.items():
        data = render(char)
        print(f"'{name}' => '{base64.b64encode(data).decode('ascii')}',")


if __name__ == "__main__":
    main()
