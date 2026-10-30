# Immutable Outlook train and caption media

Run the offline builder with Python 3.12, Pillow 12.2.0 and CairoSVG 2.9.0:

```powershell
python scripts/mail/build_branded_outlook_train.py
python scripts/mail/build_branded_outlook_train.py --check
python scripts/mail/build_static_linked_test_assets.py
python scripts/mail/build_static_linked_test_assets.py --check
```

The new `zug-dampf-outlook-brand-v1-*` GIF/PNG family retains the approved
695 × 151 canvas, all 36 frame boundaries and the complete 13-second timeline.
The original train and smoke are read from the pinned intrinsic-v1 media.
Only the existing disconnected RT glyph masks receive colors from the original
`public/rt-brand/rt-logo.svg` groups. Their raster boundaries and alpha stay
unchanged. Antialias edge colors use the nearest point of the same SVG glyph;
this does not claim to reproduce the historical SVG rasterizer's antialiasing.

Every generated GIF is decoded again. Pixels outside each registered badge,
all alpha values, frame durations, loop and disposal must match the approved
input. The builder rejects different pre-existing outputs and verifies the
unchanged inputs again after writing. Both asset directories receive the new
names; old assets are never replaced. `--check` reconstructs and verifies the
expected bytes without creating files or directories.

`outlook-hotline-label-inktop-v1.png` removes exactly 25 fully transparent top
rows from the original 552 × 102 caption, resulting in 552 × 77. No red pixel,
angle, font or size is changed. Actual display sizing belongs to the HTML
presentation layer rather than to these immutable image bytes.

The static-linked builder retains the historical 33 source entries and 25 PNG
targets, then adds nine sources and five targets. Each new GIF maps to its
corresponding branded PNG; every `/m/<first10SHA256>.png` is an exact PNG copy.
The runtime catalog and offline manifest are checked together by
`OutlookBrandedTrainAssetsTest`. These media checks do not establish Outlook
send, receive, reply, forward or device acceptance.
