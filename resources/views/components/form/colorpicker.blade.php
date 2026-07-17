@props([
    'fallback' => '#000000',
    'swatches' => [],
])

@php
    $defaultSwatches = ['#0F172A', '#1D4ED8', '#14B8A6', '#22C55E', '#F59E0B', '#EF4444', '#FFFFFF'];
    $swatches = array_values(array_unique(array_filter(array_merge($defaultSwatches, is_array($swatches) ? array_map('strtoupper', $swatches) : []), fn ($color) => preg_match('/^#[0-9A-F]{6}([0-9A-F]{2})?$/', strtoupper($color)))));
@endphp

<div
    {{ $attributes->except(['class'])->merge(['class' => 'space-y-3']) }}
    x-data="{
        fallback: @js(strtoupper($fallback)),
        value: @js(strtoupper($fallback)),
        hexInput: @js(strtoupper($fallback)),
        hue: 210,
        saturation: 100,
        brightness: 100,
        alpha: 100,
        x: 100,
        y: 0,
        init() {
            this.syncFromValue(this.value || this.fallback);

            this.$watch('value', (next) => {
                const normalized = this.normalizeHex(next);
                if (normalized !== this.value || normalized !== this.hexInput) {
                    this.syncFromValue(normalized);
                }
            });
        },
        clamp(value, min, max) {
            return Math.min(max, Math.max(min, value));
        },
        normalizeHex(value) {
            let next = String(value || '').trim().toUpperCase();

            if (!next.startsWith('#')) {
                next = '#' + next;
            }

            if (/^#[0-9A-F]{6}([0-9A-F]{2})?$/.test(next)) {
                return next;
            }

            return this.fallback;
        },
        emitChanged() {
            this.$el.dispatchEvent(new CustomEvent('colorpicker:changed', {
                bubbles: true,
                detail: { value: this.value },
            }));
        },
        hexToRgbaString(hex) {
            const color = this.normalizeHex(hex).replace('#', '');
            const rgbHex = color.slice(0, 6);
            const numeric = Number.parseInt(rgbHex, 16);
            const alpha = color.length === 8 ? Number.parseInt(color.slice(6, 8), 16) / 255 : 1;

            return 'rgba(' + ((numeric >> 16) & 255) + ', ' + ((numeric >> 8) & 255) + ', ' + (numeric & 255) + ', ' + alpha + ')';
        },
        hexToRgbA(hex) {
            const color = this.normalizeHex(hex).replace('#', '');
            const rgbHex = color.slice(0, 6);
            const numeric = Number.parseInt(rgbHex, 16);

            return {
                r: (numeric >> 16) & 255,
                g: (numeric >> 8) & 255,
                b: numeric & 255,
                a: color.length === 8 ? (Number.parseInt(color.slice(6, 8), 16) / 255) * 100 : 100,
            };
        },
        rgbToHex(r, g, b, a = 100) {
            const base = '#' + [r, g, b].map((part) => Math.round(part).toString(16).padStart(2, '0')).join('').toUpperCase();
            const alpha = this.clamp(a, 0, 100);

            if (alpha >= 100) {
                return base;
            }

            return base + Math.round((alpha / 100) * 255).toString(16).padStart(2, '0').toUpperCase();
        },
        rgbToHsv(r, g, b) {
            const red = r / 255;
            const green = g / 255;
            const blue = b / 255;
            const max = Math.max(red, green, blue);
            const min = Math.min(red, green, blue);
            const delta = max - min;
            let hue = 0;

            if (delta !== 0) {
                if (max === red) {
                    hue = ((green - blue) / delta) % 6;
                } else if (max === green) {
                    hue = (blue - red) / delta + 2;
                } else {
                    hue = (red - green) / delta + 4;
                }
            }

            hue = Math.round(hue * 60);
            if (hue < 0) {
                hue += 360;
            }

            const saturation = max === 0 ? 0 : (delta / max) * 100;
            const value = max * 100;

            return { h: hue, s: saturation, v: value };
        },
        hsvToRgb(h, s, v) {
            const saturation = s / 100;
            const value = v / 100;
            const chroma = value * saturation;
            const segment = h / 60;
            const x = chroma * (1 - Math.abs((segment % 2) - 1));
            const match = value - chroma;
            let red = 0;
            let green = 0;
            let blue = 0;

            if (segment >= 0 && segment < 1) {
                red = chroma; green = x; blue = 0;
            } else if (segment >= 1 && segment < 2) {
                red = x; green = chroma; blue = 0;
            } else if (segment >= 2 && segment < 3) {
                red = 0; green = chroma; blue = x;
            } else if (segment >= 3 && segment < 4) {
                red = 0; green = x; blue = chroma;
            } else if (segment >= 4 && segment < 5) {
                red = x; green = 0; blue = chroma;
            } else {
                red = chroma; green = 0; blue = x;
            }

            return {
                r: (red + match) * 255,
                g: (green + match) * 255,
                b: (blue + match) * 255,
            };
        },
        syncFromValue(value) {
            const hex = this.normalizeHex(value);
            const rgba = this.hexToRgbA(hex);
            const hsv = this.rgbToHsv(rgba.r, rgba.g, rgba.b);

            this.hue = hsv.h;
            this.saturation = hsv.s;
            this.brightness = hsv.v;
            this.alpha = rgba.a;
            this.x = hsv.s;
            this.y = 100 - hsv.v;
            this.hexInput = hex;
            this.value = hex;
        },
        commitFromState() {
            const rgb = this.hsvToRgb(this.hue, this.saturation, this.brightness);
            const hex = this.rgbToHex(rgb.r, rgb.g, rgb.b, this.alpha);

            this.hexInput = hex;
            this.value = hex;
            this.emitChanged();
        },
        updateArea(event) {
            const rect = this.$refs.area.getBoundingClientRect();
            const x = this.clamp(((event.clientX - rect.left) / rect.width) * 100, 0, 100);
            const y = this.clamp(((event.clientY - rect.top) / rect.height) * 100, 0, 100);

            this.x = x;
            this.y = y;
            this.saturation = x;
            this.brightness = 100 - y;
            this.commitFromState();
        },
        bindArea(event) {
            this.updateArea(event);

            const move = (next) => this.updateArea(next);
            const stop = () => {
                window.removeEventListener('pointermove', move);
                window.removeEventListener('pointerup', stop);
            };

            window.addEventListener('pointermove', move);
            window.addEventListener('pointerup', stop, { once: true });
        },
        updateHue(event) {
            const rect = this.$refs.hue.getBoundingClientRect();
            const position = this.clamp(((event.clientX - rect.left) / rect.width) * 360, 0, 360);

            this.hue = position === 360 ? 359 : position;
            this.commitFromState();
        },
        bindHue(event) {
            this.updateHue(event);

            const move = (next) => this.updateHue(next);
            const stop = () => {
                window.removeEventListener('pointermove', move);
                window.removeEventListener('pointerup', stop);
            };

            window.addEventListener('pointermove', move);
            window.addEventListener('pointerup', stop, { once: true });
        },
        updateAlpha(event) {
            const rect = this.$refs.alpha.getBoundingClientRect();
            const position = this.clamp(((event.clientX - rect.left) / rect.width) * 100, 0, 100);

            this.alpha = position;
            this.commitFromState();
        },
        bindAlpha(event) {
            this.updateAlpha(event);

            const move = (next) => this.updateAlpha(next);
            const stop = () => {
                window.removeEventListener('pointermove', move);
                window.removeEventListener('pointerup', stop);
            };

            window.addEventListener('pointermove', move);
            window.addEventListener('pointerup', stop, { once: true });
        },
        updateHexInput(raw) {
            this.hexInput = String(raw || '').toUpperCase();

            if (/^#?[0-9A-Fa-f]{6}([0-9A-Fa-f]{2})?$/.test(this.hexInput.trim())) {
                this.syncFromValue(this.hexInput);
                this.emitChanged();
            }
        },
        commitHexInput() {
            const raw = String(this.hexInput || '').trim();
            if (/^#?[0-9A-Fa-f]{6}([0-9A-Fa-f]{2})?$/.test(raw)) {
                this.syncFromValue(raw);
                this.emitChanged();
                return;
            }

            this.hexInput = this.value;
        },
        applySwatch(color) {
            this.syncFromValue(color);
            this.emitChanged();
        },
        areaBackground() {
            return 'linear-gradient(to top, #000000, transparent), linear-gradient(to right, #ffffff, hsl(' + this.hue + ', 100%, 50%))';
        },
        alphaBackground() {
            const rgb = this.hsvToRgb(this.hue, this.saturation, this.brightness);
            const opaque = this.rgbToHex(rgb.r, rgb.g, rgb.b, 100);

            return 'linear-gradient(to right, rgba(255,255,255,0), ' + this.hexToRgbaString(opaque) + ')';
        },
    }"
    x-modelable="value"
>
    <div class="grid gap-3 lg:grid-cols-[minmax(0,1fr)_112px]">
        <div class="space-y-3">
            <div
                x-ref="area"
                class="relative h-40 w-full cursor-crosshair overflow-hidden rounded-[20px] border border-white/80 shadow-[inset_0_1px_0_rgba(255,255,255,0.45)]"
                :style="{ background: areaBackground() }"
                @pointerdown.prevent="bindArea($event)"
            >
                <div class="pointer-events-none absolute inset-0 bg-[radial-gradient(circle_at_top_left,rgba(255,255,255,0.18),transparent_45%)]"></div>
                <div class="pointer-events-none absolute h-5 w-5 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-white shadow-[0_0_0_2px_rgba(15,23,42,0.18)]" :style="{ left: x + '%', top: y + '%' }"></div>
            </div>

            <div
                x-ref="hue"
                class="relative h-3 w-full cursor-ew-resize overflow-hidden rounded-full border border-white/70"
                style="background: linear-gradient(to right, #ef4444 0%, #f59e0b 16%, #eab308 32%, #22c55e 48%, #06b6d4 64%, #3b82f6 80%, #a855f7 100%);"
                @pointerdown.prevent="bindHue($event)"
            >
                <div class="pointer-events-none absolute top-1/2 h-5 w-5 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-white bg-white shadow-[0_0_0_2px_rgba(15,23,42,0.14)]" :style="{ left: (hue / 360) * 100 + '%' }"></div>
            </div>

            <div class="space-y-1.5">
                <div class="flex items-center justify-between text-[10px] uppercase tracking-[0.22em] text-slate-400">
                    <span>Alpha</span>
                    <span x-text="Math.round(alpha) + '%'"></span>
                </div>
                <div class="rounded-full border border-white/70 bg-[linear-gradient(45deg,#e2e8f0_25%,transparent_25%,transparent_75%,#e2e8f0_75%),linear-gradient(45deg,#e2e8f0_25%,transparent_25%,transparent_75%,#e2e8f0_75%)] bg-[length:16px_16px] bg-[position:0_0,8px_8px] p-[2px]">
                    <div
                        x-ref="alpha"
                        class="relative h-3 cursor-ew-resize overflow-hidden rounded-full"
                        :style="{ background: alphaBackground() }"
                        @pointerdown.prevent="bindAlpha($event)"
                    >
                        <div class="pointer-events-none absolute top-1/2 h-5 w-5 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-white bg-white shadow-[0_0_0_2px_rgba(15,23,42,0.14)]" :style="{ left: alpha + '%' }"></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="space-y-2.5">
            <div class="rounded-[20px] border border-white/80 bg-[linear-gradient(45deg,#e2e8f0_25%,transparent_25%,transparent_75%,#e2e8f0_75%),linear-gradient(45deg,#e2e8f0_25%,transparent_25%,transparent_75%,#e2e8f0_75%)] bg-[length:18px_18px] bg-[position:0_0,9px_9px] p-2 shadow-sm">
                <div class="h-14 rounded-[14px]" :style="{ backgroundColor: value }"></div>
            </div>
            <div class="grid grid-cols-2 gap-2">
                <div class="rounded-2xl border border-slate-200 bg-slate-50 px-3 py-2 text-center">
                    <div class="text-[10px] uppercase tracking-[0.22em] text-slate-400">Hue</div>
                    <div class="mt-1 text-sm font-semibold text-slate-700" x-text="Math.round(hue)"></div>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-slate-50 px-3 py-2 text-center">
                    <div class="text-[10px] uppercase tracking-[0.22em] text-slate-400">Alpha</div>
                    <div class="mt-1 text-sm font-semibold text-slate-700" x-text="Math.round(alpha) + '%'"></div>
                </div>
            </div>
        </div>
    </div>

    <div class="flex items-center gap-2.5">
        <input
            data-colorpicker-focus
            type="text"
            x-model="hexInput"
            @input="updateHexInput($event.target.value)"
            @blur="commitHexInput()"
            @keydown.enter.prevent="commitHexInput()"
            class="w-full rounded-2xl border border-slate-200 bg-white px-3 py-2 font-mono text-sm text-slate-700 shadow-sm"
        >
    </div>

    <div class="flex flex-wrap gap-1.5">
        @foreach ($swatches as $swatch)
            <button
                type="button"
                class="h-7 w-7 rounded-lg border border-white/80 shadow-sm transition hover:scale-105"
                style="background-color: {{ $swatch }}"
                @click="applySwatch('{{ strtoupper($swatch) }}')"
            ></button>
        @endforeach
    </div>
</div>
