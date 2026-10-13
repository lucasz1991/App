@once
<style>
.rt-regions {
    --regions-bg: #fff;
    --regions-muted-bg: #f6f8fb;
    --regions-text: #17283f;
    --regions-muted: #697c97;
    --regions-border: #dce4ee;
    --regions-preferred: #087f62;
    --regions-possible: #b65b0b;
    --regions-emergency: #8551ba;
    overflow: hidden;
    border: 1px solid var(--regions-border);
    border-radius: 20px;
    background: var(--regions-bg);
    color: var(--regions-text);
}
.dark .rt-regions {
    --regions-bg: #162235;
    --regions-muted-bg: #1c2b40;
    --regions-text: #e8edf5;
    --regions-muted: #a7b5ca;
    --regions-border: #35465e;
    --regions-preferred: #47d7b0;
    --regions-possible: #ffb06a;
    --regions-emergency: #c7a0ed;
}
.rt-regions [data-zone=preferred] { --zone: var(--regions-preferred); --zone-fill: rgba(8,127,98,.09); }
.rt-regions [data-zone=possible] { --zone: var(--regions-possible); --zone-fill: rgba(230,132,42,.12); }
.rt-regions [data-zone=emergency] { --zone: var(--regions-emergency); --zone-fill: rgba(133,81,186,.1); }
.rt-regions__header { padding: 24px 28px; border-bottom: 1px solid var(--regions-border); }
.rt-regions h2 { margin: 0; font-size: 20px; line-height: 1.4; font-weight: 650; letter-spacing: -.025em; }
.rt-regions__header p { margin: 5px 0 0; font-size: 12px; color: var(--regions-muted); line-height: 1.6; }
.rt-regions__layout { display: grid; grid-template-columns: minmax(0,1fr) minmax(340px,1fr); }
.rt-regions__visual { position: relative; min-width: 0; padding: 22px 12px 16px; background: var(--regions-muted-bg); border-right: 1px solid var(--regions-border); }
.rt-regions__map-heading { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 0 12px; font-size: 12px; font-weight: 600; }
.rt-regions__map-heading span:last-child { font-size: 11px; color: var(--regions-muted); font-weight: 400; }
.rt-regions .rt-regions__map { height: auto; aspect-ratio: 1154 / 1363; max-height: none; width: 100%; margin: 12px auto 0; --rt-map-accent: #6a7d98; }
.rt-regions__overlay { position: absolute; inset: 0; width: 100%; height: 100%; overflow: hidden; }
.rt-regions__state { fill: var(--zone-fill,transparent); stroke: var(--zone,transparent); stroke-width: 2; cursor: pointer; transition: fill .15s ease,stroke .15s ease; }
.rt-regions__state:hover, .rt-regions__state:focus-visible { fill: var(--zone-fill,#c9d5e5); stroke: var(--regions-text); stroke-width: 4; outline: none; }
.rt-regions__radii { pointer-events: none; }
.rt-regions__radii path { fill: var(--zone-fill); stroke: var(--zone); stroke-width: 3; stroke-dasharray: 9 7; }
.rt-regions__origin { fill: var(--regions-text); stroke: var(--regions-bg); stroke-width: 5; }
.rt-regions__editor { min-width: 0; padding: 24px; display: flex; flex-direction: column; gap: 25px; }
.rt-regions__zones { display: grid; grid-template-columns: repeat(3,minmax(0,1fr)); gap: 5px; padding: 5px; background: var(--regions-muted-bg); border: 1px solid var(--regions-border); border-radius: 14px; }
.rt-regions__zones button { min-width: 0; display: flex; flex-direction: column; align-items: flex-start; gap: 9px; border: 1px solid transparent; border-radius: 9px; padding: 12px 10px; color: var(--regions-text); text-align: left; transition: background .15s ease,border-color .15s ease; }
.rt-regions__zone-name { display: flex; align-items: center; gap: 7px; font-size: 13px; font-weight: 600; }
.rt-regions__zone-dot { display: inline-block; flex: 0 0 7px; width: 7px; height: 7px; background: var(--zone); border-radius: 50%; }
.rt-regions__zones button small { font-size: 10px; color: var(--regions-muted); padding-left: 14px; }
.rt-regions__zones button strong { color: var(--zone); font-size: 12px; font-weight: 600; }
.rt-regions__zones button:hover { background: var(--regions-bg); }
.rt-regions__zones button[aria-pressed=true] { background: var(--regions-bg); border-color: var(--zone); box-shadow: 0 2px 5px rgba(23,40,63,.05); }
.rt-regions__zones button[aria-pressed=true] .rt-regions__zone-name { color: var(--zone); }
.rt-regions fieldset { min-width: 0; }
.rt-regions legend { display: flex; align-items: center; justify-content: space-between; gap: 8px; width: 100%; margin: 0 0 12px; font-size: 13px; font-weight: 600; }
.rt-regions legend > span { font-size: 11px; color: var(--regions-muted); font-weight: 400; }
.rt-regions input[type=text], .rt-regions input[type=number] { display: block; width: 100%; min-width: 0; min-height: 46px; border: 1px solid var(--regions-border); border-radius: 9px; background: var(--regions-bg); color: var(--regions-text); font: inherit; font-size: 13px; padding: 11px 12px; box-shadow: none; }
.rt-regions input::placeholder { color: var(--regions-muted); }
.rt-regions__origin-field { position: relative; }
.rt-regions__origin-field > i { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); font-size: 14px; color: var(--regions-muted); pointer-events: none; }
.rt-regions .rt-regions__origin-field input { padding-left: 38px; }
.rt-regions__radius-fields { display: grid; grid-template-columns: repeat(3,minmax(0,1fr)); gap: 10px; margin-top: 14px; }
.rt-regions__radius-fields > label { min-width: 0; border: 1px solid var(--regions-border); border-radius: 10px; padding: 11px 12px 3px; background: var(--regions-muted-bg); }
.rt-regions__radius-fields label > span:first-child { display: block; font-size: 11px; color: var(--zone); font-weight: 600; }
.rt-regions__unit { position: relative; display: block; }
.rt-regions__unit > span { position: absolute; right: 0; top: 50%; transform: translateY(-50%); font-size: 11px; color: var(--regions-muted); pointer-events: none; }
.rt-regions .rt-regions__unit input[type=number] { padding: 6px 24px 7px 0; border: 0; border-radius: 4px; background: transparent; font-size: 21px; line-height: 1.4; font-weight: 500; font-variant-numeric: tabular-nums; appearance: textfield; }
.rt-regions__unit input::-webkit-inner-spin-button { display: none; }
.rt-regions__hint { font-size: 11px; color: var(--regions-muted); line-height: 1.6; margin: 11px 0 0; }
.rt-regions legend > .rt-regions__current-zone { display: inline-flex; align-items: center; gap: 6px; padding: 4px 8px; border-radius: 5px; background: var(--zone-fill); color: var(--zone); font-size: 10px; }
.rt-regions__state-list { display: grid; grid-template-columns: repeat(2,minmax(0,1fr)); gap: 7px; }
.rt-regions__state-list button { min-width: 0; display: flex; align-items: center; gap: 9px; text-align: left; font-size: 12px; line-height: 1.35; min-height: 42px; border: 1px solid var(--regions-border); border-radius: 8px; padding: 9px; color: var(--regions-text); background: var(--zone-fill,var(--regions-bg)); transition: border-color .15s ease,background .15s ease; }
.rt-regions__state-list button i { display: inline-flex; align-items: center; justify-content: center; font-size: 9px; flex: 0 0 18px; width: 18px; height: 18px; border: 1px solid var(--zone,var(--regions-border)); border-radius: 5px; color: var(--zone,var(--regions-muted)); }
.rt-regions__state-list button:not([data-zone=none]) { border-color: color-mix(in srgb,var(--zone) 28%,var(--regions-border)); }
.rt-regions__state-list button:not([data-zone=none]) i { background: var(--zone); color: var(--regions-bg); }
.rt-regions__state-list button:hover { border-color: var(--zone,var(--regions-muted)); background: var(--zone-fill,var(--regions-muted-bg)); }
.rt-regions__state-list button span { overflow-wrap: anywhere; }
.rt-regions__exclusions { border-top: 1px solid var(--regions-border); padding-top: 16px; }
.rt-regions__exclusions summary { font-size: 12px; cursor: pointer; min-height: 28px; }
.rt-regions__exclusions summary > span { display: inline-block; min-width: 20px; text-align: center; border-radius: 5px; background: var(--regions-muted-bg); color: var(--regions-muted); margin-left: 8px; }
.rt-regions__exclusion { display: grid; grid-template-columns: minmax(0,1fr) 75px 32px; align-items: center; gap: 7px; margin-top: 10px; }
.rt-regions__exclusion button { min-height: 38px; color: var(--regions-muted); }
.rt-regions__text-button { font-size: 12px; color: var(--regions-text); display: inline-flex; align-items: center; gap: 7px; min-height: 36px; margin-top: 9px; }
.rt-regions__footer { border-top: 1px solid var(--regions-border); display: flex; align-items: center; justify-content: space-between; gap: 22px; padding: 18px 24px; }
.rt-regions__feedback { font-size: 11px; line-height: 1.6; color: var(--regions-muted); max-width: 520px; }
.rt-regions__actions { display: flex; gap: 8px; flex-shrink: 0; }
.rt-regions__actions button { display: inline-flex; align-items: center; justify-content: center; gap: 8px; min-height: 42px; border-radius: 9px; padding: 10px 16px; font-size: 12px; font-weight: 600; }
.rt-regions__save { background: #e90039; color: white; }
.rt-regions__save:hover { background: #c80031; }
.rt-regions__reset { background: var(--regions-muted-bg); color: var(--regions-muted); }
.rt-regions__invalid { color: #b42332; font-size: 11px; line-height: 1.5; margin-top: 8px; }
.rt-regions__success { color: var(--regions-preferred); }
.rt-regions__message { padding: 24px; }
.rt-regions button:focus-visible, .rt-regions input:focus-visible, .rt-regions summary:focus-visible { outline: 2px solid var(--regions-text); outline-offset: 3px; }
.rt-regions button:disabled { opacity: .55; cursor: wait; }
@media(max-width:1000px) {
    .rt-regions__layout { grid-template-columns: minmax(0,1fr) minmax(360px,1fr); }
    .rt-regions__editor { padding: 20px 16px; }
    .rt-regions__zones button { padding: 10px 7px; }
    .rt-regions__zone-name { font-size: 12px; gap: 5px; }
    .rt-regions__radius-fields { gap: 7px; }
    .rt-regions__radius-fields > label { padding: 10px 9px 3px; }
}
@media(max-width:800px) {
    .rt-regions__header { padding: 20px; }
    .rt-regions__layout { grid-template-columns: minmax(0,1fr); }
    .rt-regions__visual { border-right: 0; border-bottom: 1px solid var(--regions-border); padding: 18px 8px 10px; }
    .rt-regions .rt-regions__map { max-width: 560px; margin: 10px auto 0; }
    .rt-regions__editor { padding: 20px; gap: 23px; }
    .rt-regions__state-list button { min-height: 44px; }
    .rt-regions__footer { padding: 18px 20px; align-items: stretch; flex-direction: column; gap: 12px; }
    .rt-regions__actions { justify-content: flex-end; }
    .rt-regions__actions button { flex: 1; }
}
@media(max-width:380px) {
    .rt-regions__editor { padding: 18px 14px; }
    .rt-regions__zones { gap: 3px; padding: 4px; }
    .rt-regions__zones button { padding: 10px 5px; }
    .rt-regions__zone-name { gap: 4px; font-size: 11px; }
    .rt-regions__zones button small { padding-left: 0; font-size: 10px; }
    .rt-regions__state-list { gap: 6px; }
    .rt-regions__state-list button { font-size: 11px; padding: 8px 6px; gap: 6px; }
}
@media(prefers-reduced-motion:reduce) { .rt-regions * { transition: none!important; } }
</style>
@endonce
