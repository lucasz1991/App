@props(['label', 'search' => false])

@once
<style data-rt-floating-toolbar-styles>
.rt-floating-toolbar {
    --rt-floating-toolbar-control: 34px;
    display: flex; flex: none; align-items: center; gap: 6px; min-width: 0; min-height: calc(var(--rt-floating-toolbar-control) + 13px);
    padding: 6px 12px; border-bottom: 1px solid var(--rt-shell-border, #e1e6ed);
    background: var(--rt-shell-surface-solid, #fff); color: var(--rt-shell-text, #182538);
}
.rt-floating-toolbar > [data-floating-search] {
    flex: none; width: var(--rt-floating-toolbar-control) !important;
    min-width: var(--rt-floating-toolbar-control) !important; height: var(--rt-floating-toolbar-control) !important;
    margin-right: auto; border-radius: 9px;
}
.rt-floating-toolbar > [data-floating-search].is-expanded { flex: 1 1 0; width: auto !important; }
.rt-floating-toolbar [data-floating-search] .rt-expandable-search__trigger {
    width: var(--rt-floating-toolbar-control) !important; min-width: var(--rt-floating-toolbar-control) !important;
    height: var(--rt-floating-toolbar-control) !important; border-radius: 9px !important; box-shadow: none !important;
}
.rt-floating-toolbar [data-floating-search] .rt-expandable-search__input {
    width: 100%; height: var(--rt-floating-toolbar-control) !important; min-height: var(--rt-floating-toolbar-control);
    padding: 0 var(--rt-floating-toolbar-control) !important; border: 1px solid var(--rt-shell-border, #e1e6ed);
    border-radius: 9px !important; background: var(--rt-shell-panel, #f4f7fa); color: var(--rt-shell-text, #182538);
    font-size: 12px; box-shadow: none !important;
}
.rt-floating-toolbar [data-floating-search] .rt-expandable-search__input:focus {
    border-color: var(--rt-shell-accent, #e4002b);
    box-shadow: inset 0 0 0 3px rgb(228 0 43 / 16%) !important;
}
.rt-floating-toolbar [data-floating-search] .rt-expandable-search__clear { width: var(--rt-floating-toolbar-control); height: var(--rt-floating-toolbar-control); }
.rt-floating-toolbar__actions { display: flex; flex: none; align-items: center; gap: 6px; margin-left: auto; }
.rt-floating-toolbar__label { min-width: 0; flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 12px; }
.rt-floating-toolbar .rt-floating-toolbar__button {
    display: inline-flex; flex: none; align-items: center; justify-content: center;
    width: var(--rt-floating-toolbar-control); min-width: var(--rt-floating-toolbar-control);
    height: var(--rt-floating-toolbar-control); min-height: var(--rt-floating-toolbar-control);
    padding: 0; border: 0; border-radius: 9px; background: transparent; color: inherit;
}
.rt-floating-toolbar .rt-floating-toolbar__button:hover:not(:disabled),
.rt-floating-toolbar .rt-floating-toolbar__button[aria-expanded='true'] { background: var(--rt-shell-panel, #f4f7fa); }
.rt-floating-toolbar .rt-floating-toolbar__button:focus-visible { outline: 2px solid var(--rt-shell-accent, #e4002b); outline-offset: 1px; }
.rt-floating-toolbar .rt-floating-toolbar__button:disabled { opacity: .4; }
.rt-floating-toolbar button > i { width: 16px; height: 16px; font-size: 14px; line-height: 16px; text-align: center; }
@media (max-width: 760px) {
    .rt-floating-toolbar { --rt-floating-toolbar-control: 44px; min-height: 53px; padding: 4px 10px; }
    .rt-floating-toolbar [data-floating-search] .rt-expandable-search__input { font-size: 16px; }
}
</style>
@endonce

<div {{ $attributes->class(['rt-floating-toolbar']) }} role="{{ $search ? 'search' : 'toolbar' }}" aria-label="{{ $label }}">
    {{ $slot }}
    @isset($actions)
        <div class="rt-floating-toolbar__actions">{{ $actions }}</div>
    @endisset
</div>
