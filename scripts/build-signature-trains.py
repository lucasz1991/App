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

from PIL import Image, ImageDraw, ImageFilter


APP = Path(__file__).resolve().parent.parent
SOURCE_DIR = APP / "resources/mail-templates/source"
ASSET_DIR = APP / "resources/mail-templates/assets"

PNG_SIZE = (1440, 150)
GIF_SIZE = (720, 75)
TRAIN_WIDTH = 945
MOTION_FRAMES = 55
MOTION_FRAME_MS = 100
MOTION_DURATION_MS = MOTION_FRAMES * MOTION_FRAME_MS
MOTION_REFERENCE_MS = 3150
START_DELAY_MS = 300
SMOKE_TAIL_FRAMES = 8
SMOKE_TAIL_FRAME_MS = 200
SMOKE_TAIL_DURATION_MS = SMOKE_TAIL_FRAMES * SMOKE_TAIL_FRAME_MS
IDLE_FRAMES = 28
IDLE_FRAME_MS = 120
IDLE_DURATION_MS = IDLE_FRAMES * IDLE_FRAME_MS
MAX_GIF_BYTES = 200 * 1024
# Die Assets bleiben etwas kraeftiger als ihre Darstellung in der Signatur.
# Dort legt CSS zusaetzlich einen 15-prozentigen Flaechen-Wash darueber. Das
# ergibt effektiv rund 54 Prozent Deckkraft, ohne den Text mit abzublenden.
TRAIN_OPACITY = 0.64

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


def themed_svg(source: Path, theme: str, hide_smoke: bool) -> str:
    palette = THEMES[theme]
    svg = source.read_text(encoding="utf-8")
    svg = svg.replace("#737d89", str(palette["stroke"]))
    svg = svg.replace("#f5f6f4", str(palette["wheel"]))
    svg = svg.replace('preserveAspectRatio="xMidYMid meet"', 'preserveAspectRatio="none"')
    svg = svg.replace("<svg ", f'<svg width="{TRAIN_WIDTH}" height="{PNG_SIZE[1]}" ')

    if hide_smoke:
        if 'id="steam-plume"' not in svg:
            raise RuntimeError(f"{source.name}: Rauchgruppe steam-plume fehlt.")
        svg = svg.replace("</style>", "#steam-plume{display:none!important}</style>")

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


def raster_train(source: Path, theme: str, hide_smoke: bool = True) -> Image.Image:
    with tempfile.TemporaryDirectory(prefix="railtime-signature-train-") as temp_dir:
        temp = Path(temp_dir)
        svg = themed_svg(source, theme, hide_smoke)
        black = temp / "black.png"
        white = temp / "white.png"
        screenshot_svg(svg, "#000000", black)
        screenshot_svg(svg, "#ffffff", white)
        train = recover_transparency(black, white)

    canvas = Image.new("RGBA", PNG_SIZE, (0, 0, 0, 0))
    train.putalpha(train.getchannel("A").point(lambda alpha: round(alpha * TRAIN_OPACITY)))
    canvas.alpha_composite(train, (0, 0))
    return canvas


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
                x=459 - drift + math.sin(phase) * (0.5 + progress * 2.0),
                y=27 - rise + math.cos(phase) * (0.4 + progress * 1.2),
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
    speed: float,
    screen_velocity: float,
    accumulator: float,
    dt: float,
) -> float:
    if speed <= 0.035 or chimney_x < -12:
        return accumulator

    # Dampfmaschinen stossen in klaren Schlaegen aus. Mit sinkender
    # Geschwindigkeit liegen die Schlaege weiter auseinander; dadurch wird
    # beim Bremsen nicht einfach eine gleichmaessige Perlenschnur erzeugt.
    interval = 0.28 + (1 - min(1.0, speed)) * 0.42
    accumulator += dt
    while accumulator >= interval:
        accumulator -= interval
        count = 3 if speed > 0.52 else (3 if speed > 0.18 else 2)
        for _ in range(count):
            particles.append(SmokeParticle(
                x=chimney_x + rng.uniform(-2.4, 2.0),
                y=27 + rng.uniform(-2.2, 1.8),
                # Frischer Rauch behaelt zunaechst einen Teil des Vorwaerts-
                # impulses der fahrenden Lok. Luftwiderstand baut ihn danach
                # rasch zur fast stehenden Luftmasse ab. Ohne diesen Impuls
                # entstanden trotz langsamer Einfahrt weit getrennte Kugeln.
                vx=screen_velocity * rng.uniform(.48, .62) + rng.uniform(-3.0, 2.0),
                vy=rng.uniform(-15.0, -10.5),
                radius=rng.uniform(2.8, 4.4),
                growth=rng.uniform(4.2, 6.2),
                age=0.0,
                lifetime=rng.uniform(1.65, 2.1),
                opacity=rng.uniform(125, 170) * (0.80 + speed * 0.24),
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


def composite_frame(train: Image.Image, theme: str, offset_x: int, smoke: Image.Image | None) -> Image.Image:
    frame = Image.new("RGBA", GIF_SIZE, THEMES[theme]["background"])
    moving = Image.new("RGBA", GIF_SIZE, (0, 0, 0, 0))
    moving.alpha_composite(train, (offset_x, 0))
    frame.alpha_composite(moving)
    if smoke is not None:
        frame.alpha_composite(smoke)
    return frame.convert("RGB")


def animated_frames(train: Image.Image, theme: str, steam: bool) -> tuple[list[Image.Image], list[int]]:
    train_small = train.resize(GIF_SIZE, Image.Resampling.LANCZOS)
    train_travel = round(TRAIN_WIDTH / 2)
    particles: list[SmokeParticle] = []
    rng = random.Random(20260807 if theme == "light" else 20260808)
    frames: list[Image.Image] = [composite_frame(train_small, theme, -train_travel, None)]
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
        chimney_x = offset + 459
        dt = MOTION_FRAME_MS / 1000
        screen_velocity = (offset - last_offset) / dt

        if steam:
            accumulator = emit_smoke(
                particles,
                rng,
                chimney_x,
                speed,
                screen_velocity,
                accumulator,
                dt,
            )
            advance_smoke(particles, dt)

        frames.append(composite_frame(train_small, theme, offset, smoke_frame(particles, theme) if steam else None))
        durations.append(MOTION_FRAME_MS)
        last_distance = distance
        last_offset = offset

    if steam:
        for index in range(SMOKE_TAIL_FRAMES):
            advance_smoke(particles, SMOKE_TAIL_FRAME_MS / 1000)
            if index == SMOKE_TAIL_FRAMES - 1:
                particles.clear()
            frames.append(composite_frame(train_small, theme, 0, smoke_frame(particles, theme)))
            durations.append(SMOKE_TAIL_FRAME_MS)

    return frames, durations


def idle_frames(train: Image.Image, theme: str) -> tuple[list[Image.Image], list[int]]:
    """Seamless, low-intensity chimney steam for the stationary train."""
    train_small = train.resize(GIF_SIZE, Image.Resampling.LANCZOS)
    frames: list[Image.Image] = []

    for frame_index in range(IDLE_FRAMES):
        cycle = frame_index / IDLE_FRAMES
        particles: list[SmokeParticle] = []

        # Fuenf sanfte Druckimpulse bilden keine starre Rauchsaule. Jeder
        # Impuls startet schmal am Schornstein, steigt steil nach oben und
        # zerfaellt dort in vier unterschiedlich grosse Wirbel. Das zyklische
        # Alter macht den Uebergang des Idle-GIFs nahtlos.
        for puff_index in range(5):
            age = (cycle + puff_index / 5) % 1
            rise = age * 22 + age**2 * 4
            drift = age * 1.8 + age**2 * 6.5
            for fragment in range(4):
                phase = puff_index * 1.61 + fragment * 1.47
                fragment_spread = (fragment - 1.5) * (0.35 + age * .72)
                particles.append(SmokeParticle(
                    x=459 - drift + fragment_spread + math.sin(age * math.tau + phase) * (0.35 + age * 1.35),
                    y=27 - rise + math.cos(age * math.tau * 1.25 + phase) * (0.3 + age * .8),
                    vx=-2.2,
                    vy=-12,
                    radius=2.1 + age * (7.8 + fragment * .35),
                    growth=8,
                    age=age,
                    lifetime=1.0,
                    opacity=102 + fragment * 7,
                    phase=phase + cycle * math.tau,
                    turbulence=0.76 + fragment * .13,
                ))

        frames.append(composite_frame(train_small, theme, 0, smoke_frame(particles, theme)))

    return frames, [IDLE_FRAME_MS] * IDLE_FRAMES


def save_gif(
    frames: list[Image.Image],
    durations: list[int],
    destination: Path,
    *,
    loop: bool = False,
    reveal_after: bool = False,
) -> None:
    # A deliberately small shared palette keeps both mail assets compact
    # and prevents local 256-colour tables from dominating every frame.
    palette_strip = Image.new("RGB", (GIF_SIZE[0], GIF_SIZE[1] * len(frames)))
    for index, frame in enumerate(frames):
        palette_strip.paste(frame, (0, index * GIF_SIZE[1]))
    # Sieben gemeinsame Farben reichen fuer mailtaugliche Dateigroessen,
    # bewahren aber mehrere Alpha-/Rauchabstufungen. Mit nur sechs Farben
    # wurden feine Wirbel zu optisch runden, einfarbigen Flecken reduziert.
    palette = palette_strip.quantize(colors=7, method=Image.Quantize.MEDIANCUT, dither=Image.Dither.NONE)
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

    train = raster_train(source, theme, hide_smoke=True)
    static = train.copy()
    static.alpha_composite(static_smoke(PNG_SIZE, theme))

    static.save(ASSET_DIR / f"zug-dampf-{theme}.png", optimize=True)
    frames, durations = animated_frames(train, theme, steam=True)
    save_gif(
        frames,
        durations,
        ASSET_DIR / f"zug-dampf-{theme}.gif",
        reveal_after=True,
    )
    standing, standing_durations = idle_frames(train, theme)
    save_gif(
        standing,
        standing_durations,
        ASSET_DIR / f"zug-dampf-idle-{theme}.gif",
        loop=True,
    )


def assert_assets() -> None:
    errors: list[str] = []
    for theme in THEMES:
        png_path = ASSET_DIR / f"zug-dampf-{theme}.png"
        gif_path = ASSET_DIR / f"zug-dampf-{theme}.gif"
        idle_path = ASSET_DIR / f"zug-dampf-idle-{theme}.gif"
        if not png_path.is_file() or not gif_path.is_file() or not idle_path.is_file():
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
            for frame in range(gif.n_frames):
                gif.seek(frame)
                durations.append(int(gif.info.get("duration", 0)))

                expected = START_DELAY_MS + MOTION_DURATION_MS + SMOKE_TAIL_DURATION_MS
            if sum(durations) != expected:
                errors.append(f"{gif_path.name}: Dauer {sum(durations)} statt {expected} ms")

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
            for frame in range(idle.n_frames):
                idle.seek(frame)
                idle_durations.append(int(idle.info.get("duration", 0)))

            if sum(idle_durations) != IDLE_DURATION_MS:
                errors.append(
                    f"{idle_path.name}: Dauer {sum(idle_durations)} statt {IDLE_DURATION_MS} ms"
                )

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
        for theme in THEMES:
            print(f"Baue dampf/{theme} ...")
            build_variant(theme)

    assert_assets()


if __name__ == "__main__":
    main()
