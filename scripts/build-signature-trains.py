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
TRAIN_OPACITY = 0.70

THEMES = {
    "light": {
        "background": (247, 246, 243, 255),
        "stroke": "#66717c",
        "wheel": "#f7f6f3",
        "smoke": (91, 103, 114),
    },
    "dark": {
        "background": (12, 16, 23, 255),
        "stroke": "#aab4bf",
        "wheel": "#0c1017",
        "smoke": (184, 194, 205),
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

    for index in range(26):
        progress = index / 25
        particles.append(SmokeParticle(
            x=459 - 8 - progress * 176 + rng.uniform(-4.5, 4.5),
            y=27 - progress * 14 + math.sin(progress * math.tau * 1.4) * 3 + rng.uniform(-2.5, 2.5),
            vx=-8,
            vy=-7,
            radius=3.8 + progress * 9.2 + rng.uniform(-1.2, 1.4),
            growth=6,
            age=progress * 0.8,
            lifetime=1.6,
            opacity=118 - progress * 52,
            phase=rng.uniform(0, math.tau),
            turbulence=rng.uniform(0.72, 1.35),
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
    accumulator: float,
    dt: float,
) -> float:
    if speed <= 0.035 or chimney_x < -12:
        return accumulator

    # Dampfmaschinen stossen in klaren Schlaegen aus. Mit sinkender
    # Geschwindigkeit liegen die Schlaege weiter auseinander; dadurch wird
    # beim Bremsen nicht einfach eine gleichmaessige Perlenschnur erzeugt.
    interval = 0.22 + (1 - min(1.0, speed)) * 0.62
    accumulator += dt
    while accumulator >= interval:
        accumulator -= interval
        count = 5 if speed > 0.52 else (3 if speed > 0.18 else 2)
        for _ in range(count):
            particles.append(SmokeParticle(
                x=chimney_x + rng.uniform(-3.4, 2.2),
                y=27 + rng.uniform(-3.2, 2.5),
                vx=rng.uniform(-7.5, -3.5) - speed * 2.0,
                vy=rng.uniform(-13.5, -7.5),
                radius=rng.uniform(4.2, 7.2),
                growth=rng.uniform(5.5, 8.8),
                age=0.0,
                lifetime=rng.uniform(1.65, 2.25),
                opacity=rng.uniform(72, 112) * (0.72 + speed * 0.38),
                phase=rng.uniform(0, math.tau),
                turbulence=rng.uniform(0.7, 1.4),
            ))

    return accumulator


def advance_smoke(particles: list[SmokeParticle], dt: float) -> None:
    for particle in particles:
        particle.age += dt
        particle.x += (particle.vx + math.sin(particle.phase) * 1.8 * particle.turbulence) * dt
        particle.y += (particle.vy + math.cos(particle.phase * .83) * 1.1) * dt
        particle.vx -= 1.0 * dt
        particle.vy -= 0.45 * dt
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
        opacity = round(particle.opacity * fade ** 1.55)
        radius = particle.radius
        extent = math.ceil(radius * (2.25 + particle.turbulence) * scale)
        center_x = particle.x * scale
        center_y = particle.y * scale
        left = math.floor(center_x - extent)
        top = math.floor(center_y - extent)
        diameter = extent * 2 + 1
        puff = Image.new("RGBA", (diameter, diameter), (0, 0, 0, 0))
        draw = ImageDraw.Draw(puff, "RGBA")
        cx = center_x - left
        cy = center_y - top

        # Eine weiche aeussere Dampfhuelle und kleinere turbulente Loben
        # verhindern geometrische Kreisformen. Der dichtere Kern bleibt am
        # frischen Ausstoss sichtbar und zerfasert mit zunehmendem Alter.
        outer_radius = radius * (1.34 + life * .2) * scale
        draw.ellipse(
            (cx - outer_radius * 1.18, cy - outer_radius * .72,
             cx + outer_radius * 1.18, cy + outer_radius * .72),
            fill=(*color, round(opacity * .24)),
        )

        # Erst die helleren Randloben, danach der dichte Kern. ImageDraw
        # ersetzt Pixel statt Alpha zu addieren; die umgekehrte Reihenfolge
        # wuerde deshalb unnatuerliche helle Ringe in die Wolken stanzen.
        for lobe in range(9):
            angle = particle.phase + lobe * (math.tau / 9)
            spread = radius * (0.42 + 0.13 * math.sin(particle.phase * 1.7 + lobe)) * scale
            lobe_radius = radius * (0.42 + 0.14 * math.cos(lobe * 1.9 + particle.phase)) * scale
            x = cx + math.cos(angle) * spread * particle.turbulence
            y = cy + math.sin(angle) * spread * 0.68
            draw.ellipse(
                (x - lobe_radius, y - lobe_radius * .78,
                 x + lobe_radius, y + lobe_radius * .78),
                fill=(*color, round(opacity * .48)),
            )

        core_radius = radius * (1 - life * .3) * scale
        draw.ellipse(
            (cx - core_radius, cy - core_radius * .72,
             cx + core_radius, cy + core_radius * .72),
            fill=(*color, round(opacity * .58)),
        )
        layer.alpha_composite(puff, dest=(left, top))

    return layer.filter(ImageFilter.GaussianBlur(2.4 * scale)).resize(
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

        if steam:
            accumulator = emit_smoke(particles, rng, chimney_x, speed, accumulator, dt)
            advance_smoke(particles, dt)

        frames.append(composite_frame(train_small, theme, offset, smoke_frame(particles, theme) if steam else None))
        durations.append(MOTION_FRAME_MS)
        last_distance = distance

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

        for puff_index in range(7):
            age = (cycle + puff_index / 7) % 1
            for fragment in range(2):
                phase = puff_index * 1.83 + fragment * 2.27
                particles.append(SmokeParticle(
                    x=459 - age * (52 + fragment * 7) + math.sin(age * math.tau + phase) * 3.2,
                    y=27 - age * (19 + fragment * 3) + math.cos(age * math.tau * 1.4 + phase) * 1.8,
                    vx=-7,
                    vy=-6,
                    radius=2.6 + age * (8.4 + fragment * 1.4),
                    growth=5,
                    age=age,
                    lifetime=1.0,
                    opacity=54 + fragment * 7,
                    phase=phase + cycle * math.tau,
                    turbulence=0.76 + fragment * .2,
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
    palette = palette_strip.quantize(colors=6, method=Image.Quantize.MEDIANCUT, dither=Image.Dither.NONE)
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
        quantized.append(transparent)
        durations = [*durations, 0]
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
            if gif.convert("RGBA").getchannel("A").getextrema()[1] != 0:
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
