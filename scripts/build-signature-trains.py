"""Build the static and animated RailTime signature train assets.

The SVG authoring files remain the source of truth. Chrome is used only as a
development-time SVG rasterizer; Pillow performs all animation, smoke and GIF
work. No browser or Python dependency is added to the application runtime.

Usage from the application root:

    python scripts/build-signature-trains.py
    python scripts/build-signature-trains.py --check
"""

from __future__ import annotations

import argparse
import math
import random
import shutil
import subprocess
import tempfile
from dataclasses import dataclass
from pathlib import Path

from PIL import Image, ImageChops, ImageDraw, ImageFilter


APP = Path(__file__).resolve().parent.parent
SOURCE_DIR = APP / "resources/mail-templates/source"
ASSET_DIR = APP / "resources/mail-templates/assets"

PNG_SIZE = (1440, 150)
GIF_SIZE = (720, 75)
SVG_VIEW_SIZE = (2053, 151)
# 1200 Pixel halten das natuerliche SVG-Seitenverhaeltnis, geben den
# angehaengten Waggons genug Laenge und lassen die Lok im 720-x-75-GIF etwa
# 53 Pixel hoch erscheinen. Die fruehere 945-x-150-Zwangsstreckung hatte
# Raeder, Kessel und Fuehrerhaus horizontal sichtbar gestaucht.
TRAIN_WIDTH = 1400
MOTION_FRAMES = 55
MOTION_FRAME_MS = 100
MOTION_DURATION_MS = MOTION_FRAMES * MOTION_FRAME_MS
MOTION_REFERENCE_MS = 3150
START_DELAY_MS = 300
SMOKE_TAIL_FRAME_DURATIONS = (200, 200, 200, 200, 200, 200, 200, 100, 100)
SMOKE_TAIL_FRAMES = len(SMOKE_TAIL_FRAME_DURATIONS)
SMOKE_TAIL_DURATION_MS = sum(SMOKE_TAIL_FRAME_DURATIONS)
# 3,7 Sekunden teilen die komplette 7,4-Sekunden-Einfahrt exakt. Das Idle-GIF
# liegt von Beginn an unter dem einmaligen Haupt-GIF und erreicht dadurch beim
# Freigeben wieder exakt seine Loop-Naht statt eine zufaellige Rauchphase.
IDLE_FRAMES = 37
IDLE_FRAME_MS = 100
IDLE_DURATION_MS = IDLE_FRAMES * IDLE_FRAME_MS
OUTLOOK_IDLE_CYCLES = 1
OUTLOOK_DURATION_MS = (
    START_DELAY_MS
    + MOTION_DURATION_MS
    + SMOKE_TAIL_DURATION_MS
    + IDLE_DURATION_MS * OUTLOOK_IDLE_CYCLES
)
MAX_GIF_BYTES = 200 * 1024
VECTOR_ASSET = ASSET_DIR / "zug-dampf.svg"
VECTOR_SMOKE_FREE_ASSET = ASSET_DIR / "zug-dampf-ohne-rauch.svg"
ENGINE_GROUP_X = 1648
ENGINE_PIVOT = (120, 151)
ENGINE_SCALE = (1.11, 1.08)
DRIVER_WHEEL_CENTERS = ((166, 133), (202, 133), (238, 133), (274, 133), (310, 133))
DRIVER_WHEEL_RADIUS = 17
# Die Assets bleiben etwas kraeftiger als ihre Darstellung in der Signatur.
# Dort legt CSS zusaetzlich einen 15-prozentigen Flaechen-Wash darueber. Das
# ergibt effektiv rund 48 Prozent Deckkraft, ohne den Text mit abzublenden.
# Der Rauch behaelt seine eigene Deckkraft und bleibt dadurch trotz dezenterem
# Zug klar erkennbar.
TRAIN_OPACITY = 0.56

THEMES = {
    "light": {
        "background": (247, 246, 243, 255),
        "stroke": "#66717c",
        "wheel": "#f7f6f3",
        "smoke": (70, 82, 94),
    },
    "dark": {
        "background": (12, 16, 23, 255),
        "stroke": "#aab4bf",
        "wheel": "#0c1017",
        "smoke": (205, 214, 223),
    },
}


@dataclass
class SmokeParticle:
    x: float
    y: float
    vx: float
    vy: float
    radius: float
    growth: float
    age: float
    lifetime: float
    opacity: float
    phase: float
    turbulence: float


def chrome_path() -> Path:
    candidates = [
        Path(r"C:\Program Files\Google\Chrome\Application\chrome.exe"),
        Path(r"C:\Program Files (x86)\Google\Chrome\Application\chrome.exe"),
    ]

    discovered = shutil.which("chrome") or shutil.which("google-chrome")
    if discovered:
        candidates.insert(0, Path(discovered))

    for candidate in candidates:
        if candidate.is_file():
            return candidate

    raise RuntimeError("Google Chrome wurde fuer den SVG-Export nicht gefunden.")


def themed_svg(
    source: Path,
    theme: str,
    hide_smoke: bool,
    hide_running_gear: bool = False,
) -> str:
    palette = THEMES[theme]
    svg = source.read_text(encoding="utf-8")
    svg = svg.replace("#737d89", str(palette["stroke"]))
    svg = svg.replace("#f5f6f4", str(palette["wheel"]))
    svg = svg.replace('preserveAspectRatio="xMidYMid meet"', 'preserveAspectRatio="xMidYMax meet"')
    svg = svg.replace("<svg ", f'<svg width="{TRAIN_WIDTH}" height="{PNG_SIZE[1]}" ')

    if hide_smoke:
        if 'id="steam-plume"' not in svg:
            raise RuntimeError(f"{source.name}: Rauchgruppe steam-plume fehlt.")
        svg = svg.replace("</style>", "#steam-plume{display:none!important}</style>")

    if hide_running_gear:
        if 'id="running-gear"' not in svg:
            raise RuntimeError(f"{source.name}: Laufwerksgruppe running-gear fehlt.")
        svg = svg.replace("</style>", "#running-gear{display:none!important}</style>")

    return svg


def screenshot_svg(svg: str, background: str, output: Path) -> None:
    output.parent.mkdir(parents=True, exist_ok=True)
    html = output.with_suffix(".html")
    html.write_text(
        "<!doctype html><html><head><meta charset=\"utf-8\"><style>"
        f"html,body{{width:{TRAIN_WIDTH}px;height:{PNG_SIZE[1]}px;margin:0;overflow:hidden;"
        f"background:{background};}}svg{{display:block;width:{TRAIN_WIDTH}px;height:{PNG_SIZE[1]}px;}}"
        "</style></head><body>" + svg + "</body></html>",
        encoding="utf-8",
    )

    command = [
        str(chrome_path()),
        "--headless=new",
        "--disable-gpu",
        "--hide-scrollbars",
        "--force-device-scale-factor=1",
        f"--window-size={TRAIN_WIDTH},{PNG_SIZE[1]}",
        f"--screenshot={output}",
        html.resolve().as_uri(),
    ]
    completed = subprocess.run(command, capture_output=True, text=True, timeout=30)
    if completed.returncode != 0 or not output.is_file():
        raise RuntimeError(f"Chrome-SVG-Export fehlgeschlagen: {completed.stderr.strip()}")


def recover_transparency(black_path: Path, white_path: Path) -> Image.Image:
    black = Image.open(black_path).convert("RGB").crop((0, 0, TRAIN_WIDTH, PNG_SIZE[1]))
    white = Image.open(white_path).convert("RGB").crop((0, 0, TRAIN_WIDTH, PNG_SIZE[1]))
    rgba = Image.new("RGBA", black.size)
    black_pixels = black.load()
    white_pixels = white.load()
    output_pixels = rgba.load()

    for y in range(black.height):
        for x in range(black.width):
            dark = black_pixels[x, y]
            light = white_pixels[x, y]
            background = sorted(light[channel] - dark[channel] for channel in range(3))[1]
            alpha = max(0, min(255, 255 - background))
            if alpha <= 1:
                output_pixels[x, y] = (0, 0, 0, 0)
                continue

            color = tuple(max(0, min(255, round(dark[channel] * 255 / alpha))) for channel in range(3))
            output_pixels[x, y] = (*color, alpha)

    return rgba


def raster_train(
    source: Path,
    theme: str,
    hide_smoke: bool = True,
    hide_running_gear: bool = False,
) -> Image.Image:
    with tempfile.TemporaryDirectory(prefix="railtime-signature-train-") as temp_dir:
        temp = Path(temp_dir)
        svg = themed_svg(source, theme, hide_smoke, hide_running_gear)
        black = temp / "black.png"
        white = temp / "white.png"
        screenshot_svg(svg, "#000000", black)
        screenshot_svg(svg, "#ffffff", white)
        train = recover_transparency(black, white)

    canvas = Image.new("RGBA", PNG_SIZE, (0, 0, 0, 0))
    train.putalpha(train.getchannel("A").point(lambda alpha: round(alpha * TRAIN_OPACITY)))
    canvas.alpha_composite(train, (0, 0))
    return canvas


def source_point_to_gif(x: float, y: float) -> tuple[float, float]:
    render_scale = TRAIN_WIDTH / SVG_VIEW_SIZE[0]
    top_padding = PNG_SIZE[1] - SVG_VIEW_SIZE[1] * render_scale
    gif_scale = GIF_SIZE[0] / PNG_SIZE[0]
    return (
        x * render_scale * gif_scale,
        (top_padding + y * render_scale) * gif_scale,
    )


def engine_point_to_gif(x: float, y: float) -> tuple[float, float]:
    transformed_x = ENGINE_GROUP_X + ENGINE_PIVOT[0] + (
        x - ENGINE_PIVOT[0]
    ) * ENGINE_SCALE[0]
    transformed_y = ENGINE_PIVOT[1] + (
        y - ENGINE_PIVOT[1]
    ) * ENGINE_SCALE[1]
    return source_point_to_gif(transformed_x, transformed_y)


def engine_radius_to_gif(radius: float) -> tuple[float, float]:
    center = engine_point_to_gif(0, 0)
    horizontal = engine_point_to_gif(radius, 0)
    vertical = engine_point_to_gif(0, radius)
    return horizontal[0] - center[0], vertical[1] - center[1]


def hex_rgb(color: str) -> tuple[int, int, int]:
    normalized = color.removeprefix("#")
    return tuple(int(normalized[index:index + 2], 16) for index in (0, 2, 4))


def running_gear_frame(theme: str, wheel_angle: float) -> Image.Image:
    """Render rotating drivers and physically linked rods at GIF resolution."""
    scale = 4
    layer = Image.new(
        "RGBA",
        (GIF_SIZE[0] * scale, GIF_SIZE[1] * scale),
        (0, 0, 0, 0),
    )
    draw = ImageDraw.Draw(layer, "RGBA")
    stroke = hex_rgb(str(THEMES[theme]["stroke"]))
    wheel_fill = hex_rgb(str(THEMES[theme]["wheel"]))

    def alpha(opacity: float) -> int:
        return round(255 * TRAIN_OPACITY * opacity)

    driver_rx, driver_ry = engine_radius_to_gif(DRIVER_WHEEL_RADIUS)
    ring_rx, ring_ry = engine_radius_to_gif(14.3)
    hub_rx, hub_ry = engine_radius_to_gif(3.4)
    crank_rx, crank_ry = engine_radius_to_gif(4.0)
    driver_centers = [engine_point_to_gif(x, y) for x, y in DRIVER_WHEEL_CENTERS]
    crank_phase = wheel_angle - math.pi / 2
    crank_pins: list[tuple[float, float]] = []

    for center_x, center_y in driver_centers:
        cx = center_x * scale
        cy = center_y * scale
        draw.ellipse(
            (
                cx - driver_rx * scale,
                cy - driver_ry * scale,
                cx + driver_rx * scale,
                cy + driver_ry * scale,
            ),
            fill=(*wheel_fill, alpha(.36)),
            outline=(*stroke, alpha(.96)),
            width=max(1, round(1.7 * scale)),
        )
        draw.ellipse(
            (
                cx - ring_rx * scale,
                cy - ring_ry * scale,
                cx + ring_rx * scale,
                cy + ring_ry * scale,
            ),
            outline=(*stroke, alpha(.84)),
            width=max(1, round(.85 * scale)),
        )

        for spoke in range(8):
            spoke_angle = wheel_angle + spoke * math.pi / 4
            draw.line(
                (
                    cx,
                    cy,
                    cx + math.cos(spoke_angle) * ring_rx * scale,
                    cy + math.sin(spoke_angle) * ring_ry * scale,
                ),
                fill=(*stroke, alpha(.76)),
                width=max(1, round(.62 * scale)),
            )

        counter_angle = wheel_angle + math.pi / 2
        counter_x = cx + math.cos(counter_angle) * driver_rx * .42 * scale
        counter_y = cy + math.sin(counter_angle) * driver_ry * .42 * scale
        draw.ellipse(
            (
                counter_x - hub_rx * .95 * scale,
                counter_y - hub_ry * .72 * scale,
                counter_x + hub_rx * .95 * scale,
                counter_y + hub_ry * .72 * scale,
            ),
            fill=(*stroke, alpha(.42)),
        )
        draw.ellipse(
            (
                cx - hub_rx * scale,
                cy - hub_ry * scale,
                cx + hub_rx * scale,
                cy + hub_ry * scale,
            ),
            fill=(*stroke, alpha(.46)),
            outline=(*stroke, alpha(.9)),
            width=max(1, round(.65 * scale)),
        )

        pin = (
            center_x + math.cos(crank_phase) * crank_rx,
            center_y + math.sin(crank_phase) * crank_ry,
        )
        crank_pins.append(pin)

    # Alle Kurbelzapfen besitzen dieselbe Winkelstellung. Die Kuppelstange
    # bleibt daher horizontal und vollzieht eine kleine Kreisbahn, waehrend
    # Kolben- und Radiusstange ihre Winkel zum Zylinder realistisch aendern.
    scaled_pins = [(x * scale, y * scale) for x, y in crank_pins]
    draw.line(
        scaled_pins,
        fill=(*stroke, alpha(.94)),
        width=max(1, round(2.8 * scale)),
        joint="curve",
    )
    draw.line(
        scaled_pins,
        fill=(*wheel_fill, alpha(.4)),
        width=max(1, round(1.15 * scale)),
        joint="curve",
    )

    piston_anchor = engine_point_to_gif(319, 116)
    radius_anchor = engine_point_to_gif(289, 112)
    for anchor, pin, width in (
        (piston_anchor, crank_pins[2], 2.25),
        (radius_anchor, crank_pins[3], 1.25),
    ):
        points = (
            anchor[0] * scale,
            anchor[1] * scale,
            pin[0] * scale,
            pin[1] * scale,
        )
        draw.line(
            points,
            fill=(*stroke, alpha(.9)),
            width=max(1, round(width * scale)),
        )
        draw.line(
            points,
            fill=(*wheel_fill, alpha(.34)),
            width=max(1, round(max(.55, width * .42) * scale)),
        )

    pin_radius = max(1, round(1.55 * scale))
    for pin_x, pin_y in scaled_pins:
        draw.ellipse(
            (pin_x - pin_radius, pin_y - pin_radius, pin_x + pin_radius, pin_y + pin_radius),
            fill=(*stroke, alpha(.92)),
        )

    leading_center = engine_point_to_gif(346, 141)
    leading_rx, leading_ry = engine_radius_to_gif(9.5)
    leading_ring_rx, leading_ring_ry = engine_radius_to_gif(7.2)
    leading_angle = wheel_angle * DRIVER_WHEEL_RADIUS / 9.5
    leading_cx = leading_center[0] * scale
    leading_cy = leading_center[1] * scale
    draw.ellipse(
        (
            leading_cx - leading_rx * scale,
            leading_cy - leading_ry * scale,
            leading_cx + leading_rx * scale,
            leading_cy + leading_ry * scale,
        ),
        fill=(*wheel_fill, alpha(.34)),
        outline=(*stroke, alpha(.94)),
        width=max(1, round(1.25 * scale)),
    )
    for spoke in range(8):
        spoke_angle = leading_angle + spoke * math.pi / 4
        draw.line(
            (
                leading_cx,
                leading_cy,
                leading_cx + math.cos(spoke_angle) * leading_ring_rx * scale,
                leading_cy + math.sin(spoke_angle) * leading_ring_ry * scale,
            ),
            fill=(*stroke, alpha(.74)),
            width=max(1, round(.52 * scale)),
        )
    draw.ellipse(
        (
            leading_cx - 1.7 * scale,
            leading_cy - 1.7 * scale,
            leading_cx + 1.7 * scale,
            leading_cy + 1.7 * scale,
        ),
        fill=(*stroke, alpha(.88)),
    )

    return layer.resize(GIF_SIZE, Image.Resampling.LANCZOS)


CHIMNEY_GIF = engine_point_to_gif(336, 43)


def static_smoke(size: tuple[int, int], theme: str) -> Image.Image:
    rng = random.Random(5210 if theme == "light" else 5211)
    particles: list[SmokeParticle] = []

    # Auch das statische Mail-Fallback zeigt eine stehende Lok: der Rauch
    # steigt deshalb zuerst fast senkrecht auf, weitet sich turbulent und
    # bekommt erst oben einen kleinen seitlichen Versatz.
    for puff_index in range(6):
        progress = puff_index / 5
        rise = progress * 23 + progress**2 * 3
        drift = progress * 2 + progress**2 * 7
        for fragment in range(4):
            phase = rng.uniform(0, math.tau)
            particles.append(SmokeParticle(
                x=CHIMNEY_GIF[0] - drift + math.sin(phase) * (0.5 + progress * 2.0),
                y=CHIMNEY_GIF[1] - rise + math.cos(phase) * (0.4 + progress * 1.2),
                vx=-2.4,
                vy=-12,
                radius=2.3 + progress * 8.2 + rng.uniform(-0.5, 0.7),
                growth=8,
                age=progress * 0.9,
                lifetime=1.25,
                opacity=146 - progress * 22,
                phase=phase,
                turbulence=rng.uniform(0.76, 1.28),
            ))

    return smoke_frame(particles, theme).resize(size, Image.Resampling.LANCZOS)


def movement_progress(progress: float) -> float:
    """Constant approach followed by a velocity-continuous cubic brake."""
    brake_start = 0.55
    distance_at_brake = 3 * brake_start / (1 - brake_start + 3 * brake_start)
    progress = max(0.0, min(1.0, progress))

    if progress <= brake_start:
        return distance_at_brake * (progress / brake_start)

    brake = (progress - brake_start) / (1 - brake_start)
    return distance_at_brake + (1 - distance_at_brake) * (1 - (1 - brake) ** 3)


def emit_smoke(
    particles: list[SmokeParticle],
    rng: random.Random,
    chimney_x: float,
    chimney_y: float,
    speed: float,
    screen_velocity: float,
    accumulator: float,
    dt: float,
) -> float:
    if speed <= 0.0015 or chimney_x < -12:
        return accumulator

    # Dampfmaschinen stossen in klaren Schlaegen aus. Mit sinkender
    # Geschwindigkeit liegen die Schlaege weiter auseinander; dadurch wird
    # beim Bremsen nicht einfach eine gleichmaessige Perlenschnur erzeugt.
    # Auch waehrend der letzten Bremsmeter bleiben vereinzelte, zunehmend
    # senkrechte Ausstoesse bestehen. So faellt der Fahrdampf nicht erst auf
    # null, bevor der Standdampf sichtbar wird.
    interval = 0.20 + (1 - min(1.0, speed)) * 0.36
    accumulator += dt
    while accumulator >= interval:
        accumulator -= interval
        count = 2 if speed > 0.16 else 1
        for _ in range(count):
            particles.append(SmokeParticle(
                x=chimney_x + rng.uniform(-2.4, 2.0),
                y=chimney_y + rng.uniform(-2.2, 1.8),
                # Frischer Rauch behaelt zunaechst einen Teil des Vorwaerts-
                # impulses der fahrenden Lok. Luftwiderstand baut ihn danach
                # rasch zur fast stehenden Luftmasse ab. Ohne diesen Impuls
                # entstanden trotz langsamer Einfahrt weit getrennte Kugeln.
                vx=screen_velocity * rng.uniform(.68, .82) + rng.uniform(-2.2, 1.6),
                vy=rng.uniform(-13.8, -10.8),
                radius=rng.uniform(1.9, 3.15),
                growth=rng.uniform(3.2, 4.8),
                age=0.0,
                lifetime=rng.uniform(2.15, 2.75),
                opacity=rng.uniform(112, 148) * (0.84 + speed * 0.18),
                phase=rng.uniform(0, math.tau),
                turbulence=rng.uniform(0.74, 1.38),
            ))

    return accumulator


def advance_smoke(particles: list[SmokeParticle], dt: float) -> None:
    for particle in particles:
        particle.age += dt
        particle.x += (particle.vx + math.sin(particle.phase) * 1.8 * particle.turbulence) * dt
        particle.y += (particle.vy + math.cos(particle.phase * .83) * 1.1) * dt
        # Exponentiell aehnlicher Widerstand gegen die Umgebungsluft. Ein
        # leichter linker Luftzug bleibt, waehrend die starke anfaengliche
        # Vorwaertskomponente binnen etwa einer Sekunde ausklingt.
        particle.vx += (-2.0 - particle.vx) * min(1.0, dt * 1.45)
        particle.vy -= 0.65 * dt
        particle.radius += particle.growth * dt
        particle.phase += dt * 2.4 * particle.turbulence

    particles[:] = [particle for particle in particles if particle.age < particle.lifetime]


def smoke_frame(particles: list[SmokeParticle], theme: str) -> Image.Image:
    scale = 2
    render_size = (GIF_SIZE[0] * scale, GIF_SIZE[1] * scale)
    layer = Image.new("RGBA", render_size, (0, 0, 0, 0))
    color = THEMES[theme]["smoke"]

    for particle in particles:
        life = particle.age / particle.lifetime
        fade = max(0.0, 1 - life)
        opacity = round(particle.opacity * fade ** 1.18)
        radius = particle.radius
        ribbon_length = radius * (2.25 + life * 1.35)
        extent = math.ceil((ribbon_length + radius * 2.4) * scale)
        center_x = particle.x * scale
        center_y = particle.y * scale
        left = math.floor(center_x - extent)
        top = math.floor(center_y - extent)
        diameter = extent * 2 + 1
        puff = Image.new("RGBA", (diameter, diameter), (0, 0, 0, 0))
        draw = ImageDraw.Draw(puff, "RGBA")
        cx = center_x - left
        cy = center_y - top

        # Rauch ist keine Ansammlung runder Sprites. Jede physikalische
        # Partikelposition wird deshalb als verjuengtes, gekruemmtes Band in
        # Stroemungsrichtung gerendert. Die horizontale Komponente wird fuer
        # die Kontur begrenzt: Der Vorwaertsimpuls verschiebt die Wolke, der
        # heisse Rauch selbst steigt trotzdem deutlich nach oben.
        flow_x = max(-7.0, min(7.0, particle.vx * .16))
        flow_y = min(-5.0, particle.vy)
        flow_length = math.hypot(flow_x, flow_y) or 1
        direction_x = flow_x / flow_length
        direction_y = flow_y / flow_length
        normal_x = -direction_y
        normal_y = direction_x

        centers: list[tuple[float, float]] = []
        half_widths: list[float] = []
        steps = 15
        for step in range(steps):
            progress = step / (steps - 1)
            along = (progress - .43) * ribbon_length * scale
            curl = (
                math.sin(particle.phase + progress * math.tau * 1.18) * .23
                + math.sin(particle.phase * .61 - progress * math.tau * 2.35) * .09
            ) * radius * scale * particle.turbulence
            centers.append((
                cx + direction_x * along + normal_x * curl,
                cy + direction_y * along + normal_y * curl,
            ))
            envelope = math.sin(math.pi * progress) ** .62
            contour_noise = 1 + .16 * math.sin(
                particle.phase * 1.31 + progress * math.tau * 3.2
            )
            half_widths.append(
                radius * scale * (.10 + envelope * (.58 + life * .32)) * contour_noise
            )

        left_edge = [
            (x + normal_x * width, y + normal_y * width)
            for (x, y), width in zip(centers, half_widths)
        ]
        right_edge = [
            (x - normal_x * width, y - normal_y * width)
            for (x, y), width in reversed(list(zip(centers, half_widths)))
        ]
        draw.polygon(left_edge + right_edge, fill=(*color, round(opacity * .31)))

        # Drei unterschiedlich phasenverschobene Wirbelfaeden sorgen fuer
        # erkennbare Stroemung innerhalb der transparenten Huelle. Teilstuecke
        # fehlen bewusst, sodass die Linien weich zerfasern statt technisch
        # durchgezogen zu wirken.
        for strand in range(3):
            strand_points: list[tuple[float, float]] = []
            for step, (x, y) in enumerate(centers):
                progress = step / (steps - 1)
                offset = math.sin(
                    particle.phase * (1.0 + strand * .17)
                    + progress * math.tau * (1.35 + strand * .23)
                    + strand * 2.05
                ) * half_widths[step] * (.34 + strand * .08)
                strand_points.append((x + normal_x * offset, y + normal_y * offset))

            strand_width = max(1, round(radius * scale * (.18 + (2 - strand) * .035)))
            strand_alpha = round(opacity * (.46 - strand * .07) * (1 - life * .22))
            for segment in range(0, steps - 1, 3):
                end = min(steps, segment + 3)
                draw.line(
                    strand_points[segment:end],
                    fill=(*color, strand_alpha),
                    width=strand_width,
                    joint="curve",
                )
        layer.alpha_composite(puff, dest=(left, top))

    return layer.filter(ImageFilter.GaussianBlur(.85 * scale)).resize(
        GIF_SIZE,
        Image.Resampling.LANCZOS,
    )


def idle_smoke_frame(cycle: float, theme: str) -> Image.Image:
    """Render a seamless, wispy stationary plume without circular puffs."""
    scale = 4
    layer = Image.new(
        "RGBA",
        (GIF_SIZE[0] * scale, GIF_SIZE[1] * scale),
        (0, 0, 0, 0),
    )
    color = THEMES[theme]["smoke"]
    animation_phase = cycle * math.tau

    # Drei lange Stroemungsbaender teilen denselben thermischen Auftrieb,
    # reagieren aber leicht unterschiedlich auf die ruhige Umgebungsluft.
    # Die Dichtewellen laufen innerhalb der Baender nach oben; dadurch bewegt
    # sich der Dampf, ohne als Folge einzelner Kugeln zu erscheinen.
    for strand in range(3):
        strand_phase = animation_phase + strand * 2.07
        points: list[tuple[float, float]] = []
        half_widths: list[float] = []
        samples = 31

        for sample in range(samples):
            rise = sample / (samples - 1)
            thermal_drift = rise**1.55 * 7.1
            primary_curl = math.sin(
                strand_phase + rise * math.tau * (1.06 + strand * .08)
            ) * (.22 + rise * 1.68)
            fine_curl = math.sin(
                -animation_phase * .58 + strand * 1.31 + rise * math.tau * 2.45
            ) * rise * .42
            strand_offset = (strand - 1) * (.28 + rise * .92)
            x = CHIMNEY_GIF[0] - thermal_drift + primary_curl + fine_curl + strand_offset
            y = CHIMNEY_GIF[1] - rise * 28.5 + math.cos(
                strand_phase * .71 + rise * math.tau * 1.63
            ) * rise * .22
            points.append((x * scale, y * scale))

            taper = math.sin(math.pi * rise) ** .38
            half_widths.append(
                (.22 + rise * 2.45) * (.34 + .66 * taper) * scale
            )

        # Sechs ueberlappende Bandabschnitte erhalten wandernde Dichte statt
        # harter Ein-/Ausblendpunkte. Jede Kontur folgt dem lokalen Normalen-
        # vektor der Kurve und wird nach oben breiter und transparenter.
        for chunk in range(6):
            start = chunk * 5
            end = min(samples, start + 7)
            section = points[start:end]
            section_widths = half_widths[start:end]
            left_edge: list[tuple[float, float]] = []
            right_edge: list[tuple[float, float]] = []

            for local_index, ((x, y), width) in enumerate(zip(section, section_widths)):
                previous = section[max(0, local_index - 1)]
                following = section[min(len(section) - 1, local_index + 1)]
                tangent_x = following[0] - previous[0]
                tangent_y = following[1] - previous[1]
                tangent_length = math.hypot(tangent_x, tangent_y) or 1
                normal_x = -tangent_y / tangent_length
                normal_y = tangent_x / tangent_length
                left_edge.append((x + normal_x * width, y + normal_y * width))
                right_edge.append((x - normal_x * width, y - normal_y * width))

            middle_rise = ((start + end - 1) / 2) / (samples - 1)
            density = .66 + .34 * (
                .5 + .5 * math.sin(
                    animation_phase - middle_rise * math.tau * 1.72 + strand * 1.37
                )
            )
            age_fade = max(.18, 1 - middle_rise**1.58)
            ribbon_alpha = round((54 - strand * 5) * density * (.58 + age_fade * .42))
            ImageDraw.Draw(layer, "RGBA").polygon(
                left_edge + list(reversed(right_edge)),
                fill=(*color, ribbon_alpha),
            )

            core_alpha = round((92 - strand * 8) * density * (.48 + age_fade * .52))
            core_width = max(1, round((.42 + middle_rise * .72) * scale))
            ImageDraw.Draw(layer, "RGBA").line(
                section,
                fill=(*color, core_alpha),
                width=core_width,
                joint="curve",
            )

    return layer.filter(ImageFilter.GaussianBlur(1.1 * scale)).resize(
        GIF_SIZE,
        Image.Resampling.LANCZOS,
    )


def scaled_alpha(image: Image.Image, factor: float) -> Image.Image:
    scaled = image.copy()
    factor = max(0.0, min(1.0, factor))
    scaled.putalpha(
        scaled.getchannel("A").point(lambda alpha: round(alpha * factor))
    )
    return scaled


def composite_frame(
    train: Image.Image,
    theme: str,
    offset_x: int,
    smoke: Image.Image | None,
    running_gear: Image.Image | None = None,
) -> Image.Image:
    frame = Image.new("RGBA", GIF_SIZE, THEMES[theme]["background"])
    moving = Image.new("RGBA", GIF_SIZE, (0, 0, 0, 0))
    moving.alpha_composite(train, (offset_x, 0))
    if running_gear is not None:
        moving.alpha_composite(running_gear, (offset_x, 0))
    frame.alpha_composite(moving)
    if smoke is not None:
        frame.alpha_composite(smoke)
    return frame.convert("RGB")


def animated_frames(
    train: Image.Image,
    theme: str,
    steam: bool,
    idle_reference: list[Image.Image] | None = None,
    idle_smoke_reference: list[Image.Image] | None = None,
) -> tuple[list[Image.Image], list[int]]:
    train_small = train.resize(GIF_SIZE, Image.Resampling.LANCZOS)
    train_travel = round(TRAIN_WIDTH / 2)
    particles: list[SmokeParticle] = []
    rng = random.Random(20260807 if theme == "light" else 20260808)
    stationary_gear = running_gear_frame(theme, 0)
    frames: list[Image.Image] = [
        composite_frame(train_small, theme, -train_travel, None, stationary_gear)
    ]
    durations: list[int] = [START_DELAY_MS]
    accumulator = 0.0
    last_distance = 0.0
    last_offset = -train_travel

    for index in range(MOTION_FRAMES):
        # Der erste Bewegungsframe folgt erst nach dem separaten 300-ms-
        # Startbild. So ist die Verzoegerung exakt und wird vom GIF-Encoder
        # nicht mit einem identischen 70-ms-Frame zusammengefasst.
        progress = (index + 1) / MOTION_FRAMES
        distance = movement_progress(progress)
        speed = max(
            0.0,
            (distance - last_distance)
            * MOTION_FRAMES
            * (MOTION_REFERENCE_MS / MOTION_DURATION_MS),
        )
        offset = round(-train_travel * (1 - distance))
        chimney_x = offset + CHIMNEY_GIF[0]
        dt = MOTION_FRAME_MS / 1000
        screen_velocity = (offset - last_offset) / dt

        if steam:
            accumulator = emit_smoke(
                particles,
                rng,
                chimney_x,
                CHIMNEY_GIF[1],
                speed,
                screen_velocity,
                accumulator,
                dt,
            )
            advance_smoke(particles, dt)

        wheel_radius_x = engine_radius_to_gif(DRIVER_WHEEL_RADIUS)[0]
        travelled_pixels = train_travel * distance
        wheel_angle = travelled_pixels / wheel_radius_x
        travelling_smoke = smoke_frame(particles, theme) if steam else None
        displayed_smoke = travelling_smoke
        if steam and idle_smoke_reference and progress > .78:
            # Die Idle-Phase beginnt nicht erst nach dem Stillstand. In den
            # letzten Bremsmetern wird ihr phasengleicher, mit dem Schornstein
            # mitwandernder Kern bereits sanft in den Fahrdampf ueberfuehrt.
            # Der Tail setzt exakt bei derselben Mischstufe fort.
            mix_progress = min(1.0, (progress - .78) / .22)
            mix_progress = mix_progress * mix_progress * (3 - 2 * mix_progress)
            idle_mix = .72 * mix_progress
            absolute_ms = START_DELAY_MS + (index + 1) * MOTION_FRAME_MS
            idle_index = (absolute_ms // IDLE_FRAME_MS) % len(idle_smoke_reference)
            moving_idle = Image.new("RGBA", GIF_SIZE, (0, 0, 0, 0))
            moving_idle.alpha_composite(idle_smoke_reference[idle_index], (offset, 0))
            displayed_smoke = Image.new("RGBA", GIF_SIZE, (0, 0, 0, 0))
            displayed_smoke.alpha_composite(scaled_alpha(travelling_smoke, 1 - idle_mix))
            displayed_smoke.alpha_composite(scaled_alpha(moving_idle, idle_mix))

        frames.append(composite_frame(
            train_small,
            theme,
            offset,
            displayed_smoke,
            running_gear_frame(theme, wheel_angle),
        ))
        durations.append(MOTION_FRAME_MS)
        last_distance = distance
        last_offset = offset

    if steam:
        final_wheel_angle = train_travel / engine_radius_to_gif(DRIVER_WHEEL_RADIUS)[0]
        final_gear = running_gear_frame(theme, final_wheel_angle)
        tail_elapsed = 0
        for index, frame_ms in enumerate(SMOKE_TAIL_FRAME_DURATIONS):
            advance_smoke(particles, frame_ms / 1000)
            travelling_smoke = composite_frame(
                train_small,
                theme,
                0,
                smoke_frame(particles, theme),
                final_gear,
            )

            if idle_reference and idle_smoke_reference:
                # Das Idle-GIF laeuft als unterste Hintergrundebene bereits ab
                # t=0. Der Ausklang blendet deshalb nicht gegen "kein Rauch",
                # sondern fuehrt den natuerlich auslaufenden Fahrdampf und den
                # neu entstehenden Standdampf kurz gemeinsam. Erst am Ende ist
                # ausschliesslich die exakte Idle-Phase sichtbar.
                absolute_ms = START_DELAY_MS + MOTION_DURATION_MS + tail_elapsed
                idle_index = (absolute_ms // IDLE_FRAME_MS) % len(idle_reference)
                transition = index / max(1, SMOKE_TAIL_FRAMES - 1)
                transition = transition * transition * (3 - 2 * transition)
                transition = .72 + .28 * transition
                if index == SMOKE_TAIL_FRAMES - 1:
                    tail_frame = idle_reference[idle_index]
                else:
                    combined_smoke = Image.new("RGBA", GIF_SIZE, (0, 0, 0, 0))
                    combined_smoke.alpha_composite(
                        scaled_alpha(smoke_frame(particles, theme), (1 - transition) ** .92)
                    )
                    combined_smoke.alpha_composite(
                        scaled_alpha(idle_smoke_reference[idle_index], transition)
                    )
                    tail_frame = composite_frame(
                        train_small,
                        theme,
                        0,
                        combined_smoke,
                        final_gear,
                    )
            else:
                tail_frame = travelling_smoke

            frames.append(tail_frame)
            durations.append(frame_ms)
            tail_elapsed += frame_ms

    return frames, durations


def idle_frames(
    train: Image.Image,
    theme: str,
) -> tuple[list[Image.Image], list[int], list[Image.Image]]:
    """Seamless, low-intensity chimney steam for the stationary train."""
    train_small = train.resize(GIF_SIZE, Image.Resampling.LANCZOS)
    final_wheel_angle = (TRAIN_WIDTH / 2) / engine_radius_to_gif(DRIVER_WHEEL_RADIUS)[0]
    stationary_gear = running_gear_frame(theme, final_wheel_angle)
    frames: list[Image.Image] = []
    smoke_frames: list[Image.Image] = []

    for frame_index in range(IDLE_FRAMES):
        cycle = frame_index / IDLE_FRAMES
        idle_smoke = idle_smoke_frame(cycle, theme)
        smoke_frames.append(idle_smoke)
        frames.append(composite_frame(
            train_small,
            theme,
            0,
            idle_smoke,
            stationary_gear,
        ))

    return frames, [IDLE_FRAME_MS] * IDLE_FRAMES, smoke_frames


def gif_palette(frames: list[Image.Image], colors: int = 7) -> Image.Image:
    """Build one compact palette shared by entry and idle animations."""
    palette_strip = Image.new("RGB", (GIF_SIZE[0], GIF_SIZE[1] * len(frames)))
    for index, frame in enumerate(frames):
        palette_strip.paste(frame, (0, index * GIF_SIZE[1]))

    return palette_strip.quantize(
        colors=colors,
        method=Image.Quantize.MEDIANCUT,
        dither=Image.Dither.NONE,
    )


def save_gif(
    frames: list[Image.Image],
    durations: list[int],
    destination: Path,
    *,
    loop: bool = False,
    reveal_after: bool = False,
    shared_palette: Image.Image | None = None,
) -> None:
    # A deliberately small shared palette keeps both mail assets compact
    # and prevents local 256-colour tables from dominating every frame.
    # Sieben gemeinsame Farben reichen fuer mailtaugliche Dateigroessen,
    # bewahren aber mehrere Alpha-/Rauchabstufungen. Mit nur sechs Farben
    # wurden feine Wirbel zu optisch runden, einfarbigen Flecken reduziert.
    palette = shared_palette if shared_palette is not None else gif_palette(frames)
    quantized = [
        frame.quantize(palette=palette, dither=Image.Dither.NONE)
        for frame in frames
    ]

    save_options: dict[str, object] = {}
    if loop:
        save_options["loop"] = 0

    if reveal_after:
        # Index 0 bleibt exklusiv transparent. Alle sichtbaren Palettenwerte
        # werden um eins verschoben, damit Pillow die Transparenz weder beim
        # Optimieren entfernt noch mit der Signaturflaeche verwechselt.
        source_palette = palette.getpalette()
        shifted_palette = [0, 0, 0, 0, 0, 0] + source_palette[:762]
        shifted: list[Image.Image] = []
        for frame in quantized:
            visible = frame.point(lambda index: index + 2)
            visible.putpalette(shifted_palette)
            shifted.append(visible)
        quantized = shifted

        transparency_index = 1
        transparent = Image.new("P", GIF_SIZE, transparency_index)
        transparent.putpalette(shifted_palette)
        # Ein einzelner Hintergrundpixel verhindert, dass GIF-Encoder die
        # ansonsten vollstaendig transparente Abschlusskachel als redundant
        # verwerfen. Bei 720 x 75 ist dieser technische Anker unsichtbar; alle
        # uebrigen 53.999 Pixel geben den darunterliegenden Idle-Loop frei.
        transparent.putpixel((0, 0), 2)
        quantized.append(transparent)
        # Manche Pillow/GIF-Kombinationen verwerfen ein transparentes
        # Schlussbild mit 0 ms (bei der dunklen Palette trat das reproduzierbar
        # auf). Zehn Millisekunden werden vom letzten Rauchframe umgebucht:
        # Gesamtdauer und sichtbarer Ablauf bleiben gleich, die Idle-Ebene wird
        # aber in beiden Themen garantiert freigegeben.
        reveal_duration = 10
        durations = [*durations[:-1], durations[-1] - reveal_duration, reveal_duration]
        quantized[0].info["transparency"] = transparency_index
        save_options.update({
            "transparency": transparency_index,
            "background": transparency_index,
            "disposal": 2,
        })
    else:
        save_options["disposal"] = 1

    quantized[0].save(
        destination,
        format="GIF",
        save_all=True,
        append_images=quantized[1:],
        duration=durations,
        optimize=not reveal_after,
        **save_options,
    )


def build_variant(theme: str) -> None:
    source = SOURCE_DIR / "rt-dampflok.svg"
    if not source.is_file():
        raise RuntimeError(f"SVG-Quelle fehlt: {source}")

    static_train = raster_train(source, theme, hide_smoke=True)
    animated_train = raster_train(
        source,
        theme,
        hide_smoke=True,
        hide_running_gear=True,
    )
    static = static_train.copy()
    static.alpha_composite(static_smoke(PNG_SIZE, theme))

    static.save(ASSET_DIR / f"zug-dampf-{theme}.png", optimize=True)
    standing, standing_durations, standing_smoke = idle_frames(animated_train, theme)
    frames, durations = animated_frames(
        animated_train,
        theme,
        steam=True,
        idle_reference=standing,
        idle_smoke_reference=standing_smoke,
    )
    # Der dunkle Hintergrund benoetigt fuer dasselbe Motiv deutlich mehr
    # GIF-Daten. Sechs statt sieben gemeinsame Farbstufen halten ihn unter
    # 200 KiB; die geometrisch gerenderten Rauchbaender bleiben davon intakt.
    palette = gif_palette([*frames, *standing], colors=6 if theme == "dark" else 7)
    save_gif(
        frames,
        durations,
        ASSET_DIR / f"zug-dampf-{theme}.gif",
        reveal_after=True,
        shared_palette=palette,
    )
    save_gif(
        standing,
        standing_durations,
        ASSET_DIR / f"zug-dampf-idle-{theme}.gif",
        loop=True,
        shared_palette=palette,
    )
    # Outlook kann ein normales eingebettetes <img>-GIF wesentlich robuster
    # uebernehmen als zwei CSS-Hintergrundebenen. Diese Fassung enthaelt die
    # komplette Einfahrt und genau einen anschliessenden Idle-Zyklus, loopt
    # nicht und bleibt danach auf einem sichtbaren Standbild stehen.
    save_gif(
        [*frames, *(standing * OUTLOOK_IDLE_CYCLES)],
        [*durations, *(standing_durations * OUTLOOK_IDLE_CYCLES)],
        ASSET_DIR / f"zug-dampf-outlook-{theme}.gif",
        shared_palette=palette,
    )


def build_vector_assets() -> None:
    """Publish website-ready SVGs with and without the static smoke plume."""
    source = SOURCE_DIR / "rt-dampflok.svg"
    if not source.is_file():
        raise RuntimeError(f"SVG-Quelle fehlt: {source}")

    # Bewusst eine bytegleiche Kopie: Lokproportionen, angehaengte Waggons,
    # semantische Gruppen und der statische steam-plume bleiben damit fuer
    # Website-Animationen oder responsives Inline-SVG voll nutzbar.
    shutil.copyfile(source, VECTOR_ASSET)

    svg = source.read_text(encoding="utf-8")
    plume_start = svg.find('    <g id="steam-plume"')
    plume_end = svg.find("    </g>", plume_start)
    if plume_start < 0 or plume_end < 0:
        raise RuntimeError(f"{source.name}: Rauchgruppe steam-plume fehlt.")

    plume_end += len("    </g>")
    smoke_free_svg = svg[:plume_start] + svg[plume_end:]
    smoke_free_svg = smoke_free_svg.replace(
        " und separat animierbarer Rauchfahne.",
        " ohne dargestellte Rauchfahne.",
        1,
    )
    VECTOR_SMOKE_FREE_ASSET.write_text(smoke_free_svg, encoding="utf-8")


def frame_at_ms(
    frames: list[Image.Image],
    durations: list[int],
    position_ms: int,
) -> Image.Image:
    """Return the encoded frame visible at an absolute animation timestamp."""
    elapsed = 0
    for frame, duration in zip(frames, durations):
        if position_ms < elapsed + duration:
            return frame
        elapsed += duration

    return frames[-1]


def assert_assets() -> None:
    errors: list[str] = []
    source_svg = SOURCE_DIR / "rt-dampflok.svg"
    if not VECTOR_ASSET.is_file():
        errors.append(f"{VECTOR_ASSET.name}: Website-Vektorasset fehlt")
    elif not source_svg.is_file() or VECTOR_ASSET.read_bytes() != source_svg.read_bytes():
        errors.append(f"{VECTOR_ASSET.name}: nicht mit der autoritativen SVG-Quelle synchron")
    else:
        vector_svg = VECTOR_ASSET.read_text(encoding="utf-8")
        for contract in (
            'viewBox="0 0 2053 151"',
            'id="additional-container-wagon"',
            'id="steam-engine"',
            'id="steam-plume"',
            'id="running-gear"',
            'id="coupling-rod"',
            'id="main-rod"',
        ):
            if contract not in vector_svg:
                errors.append(f"{VECTOR_ASSET.name}: SVG-Vertrag {contract} fehlt")
        if vector_svg.count('data-drive-wheel=') != 5:
            errors.append(f"{VECTOR_ASSET.name}: nicht genau fuenf animierbare Treibraeder")

    if not VECTOR_SMOKE_FREE_ASSET.is_file():
        errors.append(f"{VECTOR_SMOKE_FREE_ASSET.name}: rauchfreies Website-Vektorasset fehlt")
    else:
        smoke_free_svg = VECTOR_SMOKE_FREE_ASSET.read_text(encoding="utf-8")
        for contract in (
            'viewBox="0 0 2053 151"',
            'id="additional-container-wagon"',
            'id="steam-engine"',
            'id="running-gear"',
            'id="coupling-rod"',
            'id="main-rod"',
        ):
            if contract not in smoke_free_svg:
                errors.append(f"{VECTOR_SMOKE_FREE_ASSET.name}: SVG-Vertrag {contract} fehlt")
        if 'id="steam-plume"' in smoke_free_svg or '<path class="smoke"' in smoke_free_svg:
            errors.append(f"{VECTOR_SMOKE_FREE_ASSET.name}: enthaelt weiterhin sichtbaren Rauch")
        if smoke_free_svg.count('data-drive-wheel=') != 5:
            errors.append(
                f"{VECTOR_SMOKE_FREE_ASSET.name}: nicht genau fuenf animierbare Treibraeder"
            )

    for theme in THEMES:
        gear_difference = ImageChops.difference(
            running_gear_frame(theme, 0),
            running_gear_frame(theme, math.pi / 3),
        )
        if gear_difference.getbbox() is None:
            errors.append(f"dampf-{theme}: Laufwerk reagiert nicht auf Radwinkel")

    for theme in THEMES:
        png_path = ASSET_DIR / f"zug-dampf-{theme}.png"
        gif_path = ASSET_DIR / f"zug-dampf-{theme}.gif"
        idle_path = ASSET_DIR / f"zug-dampf-idle-{theme}.gif"
        outlook_path = ASSET_DIR / f"zug-dampf-outlook-{theme}.gif"
        if (
            not png_path.is_file()
            or not gif_path.is_file()
            or not idle_path.is_file()
            or not outlook_path.is_file()
        ):
            errors.append(f"dampf-{theme}: Asset fehlt")
            continue

        with Image.open(png_path) as png:
            if png.size != PNG_SIZE:
                errors.append(f"{png_path.name}: PNG-Groesse {png.size}")

        with Image.open(gif_path) as gif:
            if gif.size != GIF_SIZE:
                errors.append(f"{gif_path.name}: GIF-Groesse {gif.size}")
            if gif.info.get("loop") is not None:
                errors.append(f"{gif_path.name}: enthaelt eine Loop-Erweiterung")

            durations = []
            main_frames = []
            for frame in range(gif.n_frames):
                gif.seek(frame)
                durations.append(int(gif.info.get("duration", 0)))
                main_frames.append(gif.convert("RGBA").copy())

            expected = START_DELAY_MS + MOTION_DURATION_MS + SMOKE_TAIL_DURATION_MS
            if sum(durations) != expected:
                errors.append(f"{gif_path.name}: Dauer {sum(durations)} statt {expected} ms")

            if expected % IDLE_DURATION_MS != 0:
                errors.append(
                    f"{gif_path.name}: Einfahrt {expected} ms endet nicht auf Idle-Loop-Naht "
                    f"({IDLE_DURATION_MS} ms)"
                )

            # GIF encoders may merge visually identical braking frames and
            # accumulate their durations. The timing contract is therefore
            # authoritative; enough physical frames must remain for fluidity.
            minimum_frames = 44
            if gif.n_frames < minimum_frames:
                errors.append(f"{gif_path.name}: nur {gif.n_frames} Frames")

            gif.seek(gif.n_frames - 1)
            final_alpha = gif.convert("RGBA").getchannel("A")
            alpha_histogram = final_alpha.histogram()
            visible_pixels = GIF_SIZE[0] * GIF_SIZE[1] - alpha_histogram[0]
            if visible_pixels > 1:
                errors.append(f"{gif_path.name}: Endbild gibt die Idle-Ebene nicht frei")

        with Image.open(idle_path) as idle:
            if idle.size != GIF_SIZE:
                errors.append(f"{idle_path.name}: GIF-Groesse {idle.size}")
            if idle.info.get("loop") != 0:
                errors.append(f"{idle_path.name}: Idle-Rauch loopt nicht endlos")

            idle_durations = []
            encoded_idle_frames = []
            for frame in range(idle.n_frames):
                idle.seek(frame)
                idle_durations.append(int(idle.info.get("duration", 0)))
                encoded_idle_frames.append(idle.convert("RGBA").copy())

            if sum(idle_durations) != IDLE_DURATION_MS:
                errors.append(
                    f"{idle_path.name}: Dauer {sum(idle_durations)} statt {IDLE_DURATION_MS} ms"
                )

        with Image.open(outlook_path) as outlook:
            if outlook.size != GIF_SIZE:
                errors.append(f"{outlook_path.name}: GIF-Groesse {outlook.size}")
            if outlook.info.get("loop") is not None:
                errors.append(f"{outlook_path.name}: darf keine Loop-Erweiterung enthalten")

            outlook_durations = []
            for frame in range(outlook.n_frames):
                outlook.seek(frame)
                outlook_durations.append(int(outlook.info.get("duration", 0)))

            if sum(outlook_durations) != OUTLOOK_DURATION_MS:
                errors.append(
                    f"{outlook_path.name}: Dauer {sum(outlook_durations)} "
                    f"statt {OUTLOOK_DURATION_MS} ms"
                )

            outlook.seek(outlook.n_frames - 1)
            final_alpha = outlook.convert("RGBA").getchannel("A")
            if final_alpha.getextrema()[1] == 0:
                errors.append(f"{outlook_path.name}: endet auf einem unsichtbaren Bild")

        if outlook_path.stat().st_size > MAX_GIF_BYTES:
            errors.append(
                f"{outlook_path.name}: {outlook_path.stat().st_size} Bytes > {MAX_GIF_BYTES}"
            )

        # Elf Millisekunden vor Ablauf ist noch der letzte sichtbare
        # Hauptframe aktiv; zehn Millisekunden vor Ablauf wird die Idle-Ebene
        # transparent freigegeben. Beide Bilder muessen pixelgleich sein,
        # sonst entstuende trotz korrekter Dauer wieder ein sichtbarer Sprung.
        transition_position = expected - 11
        main_transition = frame_at_ms(main_frames, durations, transition_position)
        idle_transition = frame_at_ms(
            encoded_idle_frames,
            idle_durations,
            transition_position % IDLE_DURATION_MS,
        )
        if ImageChops.difference(
            main_transition.convert("RGB"),
            idle_transition.convert("RGB"),
        ).getbbox() is not None:
            errors.append(f"{gif_path.name}: letzter Zugframe und Idle-Rauchphase springen")

        if gif_path.stat().st_size > MAX_GIF_BYTES:
            errors.append(f"{gif_path.name}: {gif_path.stat().st_size} Bytes > {MAX_GIF_BYTES}")
        if idle_path.stat().st_size > MAX_GIF_BYTES:
            errors.append(f"{idle_path.name}: {idle_path.stat().st_size} Bytes > {MAX_GIF_BYTES}")

    if errors:
        raise SystemExit("\n".join(errors))

    print(
        f"OK: {START_DELAY_MS} ms Startverzoegerung, {MOTION_DURATION_MS} ms Einfahrt, "
        f"{SMOKE_TAIL_DURATION_MS} ms Rauch-Ausklang, {IDLE_DURATION_MS} ms Idle-Loop, "
        "Einfahrt ohne Loop, alle GIFs unter 200 KiB."
    )


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--check", action="store_true", help="Vorhandene Assets nur pruefen")
    args = parser.parse_args()

    if not args.check:
        ASSET_DIR.mkdir(parents=True, exist_ok=True)
        print("Baue dampf/vector ...")
        build_vector_assets()
        for theme in THEMES:
            print(f"Baue dampf/{theme} ...")
            build_variant(theme)

    assert_assets()


if __name__ == "__main__":
    main()
