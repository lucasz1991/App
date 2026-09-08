<nav class="rt-sidebar-footer" aria-label="Support und Einstellungen" data-rt-sidebar-footer>
    <x-ui.dropdown.anchor-dropdown align="top" width="56" dropdown-id="sidebar-support" layer-group="sidebar-footer" :content-label="__('app.it_support')">
        <x-slot:trigger>
            <button type="button" class="rt-sidebar-footer__button" aria-label="{{ __('app.it_support') }}" title="{{ __('app.it_support') }}" data-active="{{ request()->routeIs('help', 'support') ? 'true' : 'false' }}">
                <i data-feather="life-buoy" aria-hidden="true"></i>
            </button>
        </x-slot:trigger>
        <x-slot:content>
            <a href="{{ route('help') }}" role="menuitem" class="rt-sidebar-footer__menu-link">{{ __('app.help') }}</a>
            <a href="{{ route('support') }}" role="menuitem" class="rt-sidebar-footer__menu-link">{{ __('app.it_support') }}</a>
        </x-slot:content>
    </x-ui.dropdown.anchor-dropdown>
    @can('settings.manage')
        <a href="{{ route('admin.settings') }}" class="rt-sidebar-footer__button" aria-label="{{ __('app.settings') }}" title="{{ __('app.settings') }}" @if(request()->routeIs('admin.settings')) aria-current="page" @endif>
            <i data-feather="settings" aria-hidden="true"></i>
        </a>
    @else
        <a href="{{ route('profile.show') }}" class="rt-sidebar-footer__button" aria-label="{{ __('app.settings') }}" title="{{ __('app.settings') }}" @if(request()->routeIs('profile.show')) aria-current="page" @endif>
            <i data-feather="settings" aria-hidden="true"></i>
        </a>
    @endcan
</nav>
