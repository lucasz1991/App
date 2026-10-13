@once
<style>
.rt-regions { --regions-bg:#fff; --regions-surface:#f5f7fa; --regions-text:#17283f; --regions-muted:#697c97; --regions-border:#dce4ee; --regions-preferred:#087f62; --regions-possible:#b65b0b; --regions-emergency:#8551ba; color:var(--regions-text); background:var(--regions-bg); border:1px solid var(--regions-border); border-radius:18px; overflow:hidden; }
.dark .rt-regions { --regions-bg:#162235; --regions-surface:#1c2b40; --regions-text:#e8edf5; --regions-muted:#a7b5ca; --regions-border:#35465e; --regions-preferred:#47d7b0; --regions-possible:#ffb06a; --regions-emergency:#c7a0ed; }
.rt-regions [x-cloak] { display:none!important; }
.rt-regions [data-zone=preferred] { --zone:var(--regions-preferred); --zone-fill:rgba(8,127,98,.12); }
.rt-regions [data-zone=possible] { --zone:var(--regions-possible); --zone-fill:rgba(230,132,42,.14); }
.rt-regions [data-zone=emergency] { --zone:var(--regions-emergency); --zone-fill:rgba(133,81,186,.12); }
.rt-regions__header { padding:20px 24px; border-bottom:1px solid var(--regions-border); }
.rt-regions h2 { margin:0; font-size:19px; line-height:1.4; font-weight:650; letter-spacing:-.025em; }
.rt-regions__canvas { position:relative; isolation:isolate; height:clamp(540px,70svh,680px); min-width:0; padding:76px 20px 20px; background:var(--regions-surface); }
.rt-regions__toolbar { position:absolute; top:18px; left:20px; right:20px; z-index:3; display:flex; align-items:flex-start; justify-content:space-between; gap:12px; pointer-events:none; }
.rt-regions__toolbar > * { pointer-events:auto; }
.rt-regions__modes { display:inline-flex; align-items:center; gap:3px; padding:4px; border:1px solid var(--regions-border); border-radius:11px; background:var(--regions-bg); box-shadow:0 2px 8px rgba(23,40,63,.035); }
.rt-regions__modes button { display:inline-flex; align-items:center; justify-content:center; gap:8px; min-height:36px; padding:8px 11px; border-radius:7px; color:var(--regions-muted); font-size:12px; font-weight:500; white-space:nowrap; }
.rt-regions__modes button[aria-pressed=true] { background:var(--regions-surface); color:var(--regions-text); }
.rt-regions__modes button > i:not(.rt-regions__configured) { font-size:13px; }
.rt-regions__modes small { min-width:18px; padding:1px 4px; border-radius:4px; background:var(--regions-bg); font-size:10px; text-align:center; }
.rt-regions__configured { height:6px; width:6px; border-radius:50%; background:var(--regions-preferred); }
.rt-regions__tools { display:flex; align-items:center; gap:8px; }
.rt-regions__state-picker select { width:195px; max-width:100%; min-height:46px; background-color:var(--regions-bg); color:var(--regions-text); border:1px solid var(--regions-border); border-radius:10px; padding:10px 30px 10px 12px; font:inherit; font-size:12px; }
.rt-regions__tool { display:inline-flex; align-items:center; justify-content:center; gap:5px; height:46px; min-width:46px; padding:10px; border:1px solid var(--regions-border); border-radius:10px; background:var(--regions-bg); color:var(--regions-muted); }
.rt-regions__tool[aria-expanded=true] { color:var(--regions-text); border-color:var(--regions-muted); }
.rt-regions__tool small { font-size:10px; }
.rt-regions .rt-regions__map { width:100%; height:100%; max-height:none; margin:0; --rt-map-accent:#6a7d98; }
.rt-regions__overlay { position:absolute; inset:0; width:100%; height:100%; overflow:hidden; }
.rt-regions__state { fill:var(--zone-fill,transparent); stroke:var(--zone,transparent); stroke-width:2; cursor:pointer; transition:fill .12s ease,stroke .12s ease; }
.rt-regions__state:hover,.rt-regions__state:focus-visible,.rt-regions__state[data-open=true] { fill:var(--zone-fill,#c9d5e5); stroke:var(--regions-text); stroke-width:4; outline:none; }
.rt-regions__radii { pointer-events:none; }
.rt-regions__radii path { fill:var(--zone-fill); stroke:var(--zone); stroke-width:3; stroke-dasharray:9 7; }
.rt-regions__origin { fill:var(--regions-text); stroke:var(--regions-bg); stroke-width:5; }
.rt-regions__region-menu,.rt-regions__radius-panel,.rt-regions__exclusions { position:absolute; border:1px solid var(--regions-border); border-radius:12px; background:var(--regions-bg); box-shadow:0 8px 28px rgba(23,40,63,.1); }
.rt-regions__region-menu { z-index:5; width:248px; max-width:calc(100% - 24px); max-height:calc(100% - 24px); overflow:auto; padding:6px; }
.rt-regions__popover-title { display:flex; align-items:center; justify-content:space-between; gap:10px; padding:7px 8px 10px; }
.rt-regions__popover-title strong { font-size:12px; font-weight:600; }
.rt-regions__popover-title > button { display:inline-flex; align-items:center; justify-content:center; flex:0 0 28px; height:28px; border-radius:5px; color:var(--regions-muted); }
.rt-regions__region-menu [data-choice] { display:flex; align-items:center; gap:10px; width:100%; min-height:42px; padding:10px 12px; border-radius:7px; font-size:12px; text-align:left; color:var(--regions-text); }
.rt-regions__region-menu [data-choice]:hover,.rt-regions__region-menu [data-choice][aria-pressed=true] { background:var(--zone-fill,var(--regions-surface)); }
.rt-regions__zone-dot { display:inline-block; flex:0 0 8px; width:8px; height:8px; background:var(--zone); border-radius:50%; }
.rt-regions__choice-check { margin-left:auto; color:var(--zone); font-size:11px; }
.rt-regions__region-menu .rt-regions__remove-state { border-top:1px solid var(--regions-border); border-radius:0 0 7px 7px; margin-top:5px; color:var(--regions-muted); }
.rt-regions__radius-panel { z-index:2; top:78px; right:20px; width:302px; padding:16px; box-shadow:0 4px 18px rgba(23,40,63,.055); }
.rt-regions__base-field > label { display:block; margin-bottom:8px; font-size:12px; font-weight:600; }
.rt-regions input[type=text],.rt-regions input[type=number] { display:block; width:100%; min-width:0; min-height:42px; border:1px solid var(--regions-border); border-radius:8px; background:var(--regions-bg); color:var(--regions-text); font:inherit; font-size:13px; padding:10px; box-shadow:none; }
.rt-regions input::placeholder { color:var(--regions-muted); }
.rt-regions__origin-field { position:relative; }
.rt-regions__origin-field > i { position:absolute; left:12px; top:50%; transform:translateY(-50%); font-size:13px; color:var(--regions-muted); pointer-events:none; }
.rt-regions .rt-regions__origin-field input { padding-left:34px; }
.rt-regions__address { position:relative; min-width:0; }
.rt-regions__address-quality { float:right; font-size:10px; font-weight:400; color:var(--regions-muted); }
.rt-regions__origin-field > .rt-regions__search-loading { right:10px; left:auto; }
.rt-regions .rt-regions__address input { padding-right:32px; }
.rt-regions__address-results { position:absolute; z-index:6; top:100%; left:0; right:0; margin-top:5px; max-height:min(320px,40svh); overflow:auto; overscroll-behavior:contain; border:1px solid var(--regions-border); border-radius:10px; background:var(--regions-bg); box-shadow:0 8px 24px rgba(23,40,63,.12); }
.rt-regions__address-results ul { padding:4px; margin:0; list-style:none; }
.rt-regions__address-results [role=option] { display:flex; flex-direction:column; gap:4px; padding:10px; min-height:50px; border-radius:6px; cursor:pointer; font-size:12px; line-height:1.5; overflow-wrap:anywhere; }
.rt-regions__address-results [role=option][aria-selected=true] { background:var(--regions-surface); outline:1px solid var(--regions-border); outline-offset:-1px; }
.rt-regions__address-results small { font-size:10px; color:var(--regions-muted); }
.rt-regions__address-results p { margin:0; padding:12px; font-size:12px; line-height:1.5; }
.rt-regions__address-provider { display:block; padding:5px 12px; text-align:right; border-top:1px solid var(--regions-border); }
.rt-regions__radius-fields { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:7px; margin-top:14px; min-width:0; }
.rt-regions__radius-fields > label { min-width:0; border:1px solid var(--regions-border); border-radius:8px; padding:9px 8px 1px; background:var(--regions-surface); }
.rt-regions__radius-fields label > span:first-child { display:block; font-size:10px; color:var(--zone); font-weight:500; }
.rt-regions__unit { position:relative; display:block; }
.rt-regions__unit > span { position:absolute; right:0; top:50%; transform:translateY(-50%); font-size:9px; color:var(--regions-muted); pointer-events:none; }
.rt-regions .rt-regions__unit input[type=number] { padding:7px 17px 7px 0; border:0; border-radius:3px; background:transparent; font-size:18px; line-height:1.4; font-weight:500; font-variant-numeric:tabular-nums; appearance:textfield; }
.rt-regions__unit input::-webkit-inner-spin-button { display:none; }
.rt-regions__hint { font-size:10px; color:var(--regions-muted); line-height:1.6; margin:10px 0 0; }
.rt-regions__exclusions { z-index:4; right:20px; top:78px; width:350px; max-width:calc(100% - 40px); max-height:calc(100% - 96px); overflow:auto; padding:10px; }
.rt-regions__exclusions .rt-regions__hint { margin:0 8px 12px; }
.rt-regions__exclusion { display:grid; grid-template-columns:minmax(0,1fr) 70px 30px; align-items:center; gap:7px; margin-top:10px; }
.rt-regions__exclusion button { min-height:38px; color:var(--regions-muted); }
.rt-regions__text-button { font-size:12px; color:var(--regions-text); display:inline-flex; align-items:center; gap:7px; min-height:36px; margin:9px 8px 0; }
.rt-regions__footer { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px 20px; padding:16px 20px; border-top:1px solid var(--regions-border); }
.rt-regions__clear { display:inline-flex; align-items:center; gap:8px; padding:10px 0; min-height:42px; color:var(--regions-muted); font-size:12px; }
.rt-regions__clear:hover { color:#b42332; }
.rt-regions__feedback { flex:1; font-size:11px; line-height:1.5; color:var(--regions-muted); text-align:right; }
.rt-regions__feedback:empty { display:none; }
.rt-regions__actions { display:flex; gap:8px; flex-shrink:0; margin-left:auto; }
.rt-regions__actions button { display:inline-flex; align-items:center; justify-content:center; gap:8px; min-height:42px; border-radius:8px; padding:10px 16px; font-size:12px; font-weight:600; }
.rt-regions__save { background:#e90039; color:white; }
.rt-regions__save:hover { background:#c80031; }
.rt-regions__reset { background:var(--regions-surface); color:var(--regions-muted); }
.rt-regions__invalid { color:#b42332; font-size:11px; line-height:1.5; margin-top:8px; }
.rt-regions__success { color:var(--regions-preferred); }
.rt-regions__message { padding:24px; }
.rt-regions button:focus-visible,.rt-regions input:focus-visible,.rt-regions select:focus-visible { outline:2px solid var(--regions-text); outline-offset:3px; }
.rt-regions button:disabled { opacity:.45; cursor:default; }
@media(max-width:1050px) {
    .rt-regions__canvas[data-mode=radius] { padding-top:242px; height:680px; }
    .rt-regions__radius-panel { left:20px; right:20px; width:auto; display:grid; grid-template-columns:minmax(0,1.2fr) minmax(0,1fr); align-items:end; gap:0 16px; }
    .rt-regions__radius-fields { margin:0; }
    .rt-regions__radius-panel > p { grid-column:1 / -1; }
}
@media(max-width:700px) {
    .rt-regions__header { padding:17px 18px; }
    .rt-regions__canvas { height:550px; padding:122px 8px 16px; }
    .rt-regions__toolbar { top:12px; left:12px; right:12px; flex-wrap:wrap; gap:8px; }
    .rt-regions__modes { justify-content:space-between; width:100%; }
    .rt-regions__modes button { flex:1; padding:8px; font-size:11px; gap:6px; }
    .rt-regions__tools { width:100%; justify-content:space-between; }
    .rt-regions__state-picker { flex:1; }
    .rt-regions__state-picker select { width:100%; min-height:38px; font-size:12px; }
    .rt-regions__tool { height:38px; min-width:38px; margin-left:auto; }
    .rt-regions__canvas[data-mode=radius] { height:690px; padding-top:318px; }
    .rt-regions__radius-panel { top:110px; left:12px; right:12px; grid-template-columns:minmax(0,1fr); padding:12px; }
    .rt-regions__radius-fields { margin-top:12px; }
    .rt-regions__exclusions { right:12px; top:110px; max-width:calc(100% - 24px); max-height:calc(100% - 122px); }
    .rt-regions input[type=text] { font-size:16px; }
    .rt-regions__radius-fields label > span:first-child { font-size:11px; }
    .rt-regions__region-menu [data-choice] { min-height:44px; }
    .rt-regions__footer { padding:12px 16px 16px; gap:6px 12px; }
    .rt-regions__clear { font-size:11px; }
    .rt-regions__feedback { flex-basis:100%; order:-1; text-align:left; }
    .rt-regions__actions { width:100%; }
    .rt-regions__actions button { flex:1; }
}
@media(max-width:380px) {
    .rt-regions__modes button { gap:4px; padding:8px 6px; font-size:11px; }
    .rt-regions__modes button > i,.rt-regions__modes small { display:none; }
    .rt-regions__canvas[data-mode=radius] { height:720px; padding-top:350px; }
}
@media(prefers-reduced-motion:reduce) { .rt-regions * { transition:none!important; } }
</style>
@endonce
