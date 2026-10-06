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
            <a href="{{ route('support.cases') }}" role="menuitem" class="rt-sidebar-footer__menu-link">{{ auth()->user()?->can('support.manage') ? 'Supportfälle bearbeiten' : 'Meine Supportfälle' }}</a>
        </x-slot:content>
    </x-ui.dropdown.anchor-dropdown>
    @if(auth()->user()?->isAdmin())
        <x-ui.dropdown.anchor-dropdown align="top" width="64" dropdown-id="sidebar-settings" layer-group="sidebar-footer" content-label="Systemeinstellungen">
            <x-slot:trigger><button type="button" class="rt-sidebar-footer__button" aria-label="Systemeinstellungen" title="Systemeinstellungen" data-active="{{ request()->routeIs('admin.settings','admin.mail-documents.editor') ? 'true' : 'false' }}"><i data-feather="settings" aria-hidden="true"></i></button></x-slot:trigger>
            <x-slot:content>
                <a href="{{ route('admin.settings') }}" role="menuitem" class="rt-sidebar-footer__menu-link">Systemeinstellungen</a>
                <span class="block px-4 pt-3 text-xs font-semibold text-rt-muted">E-Mail</span>
                <a href="{{ route('admin.settings',['tab'=>'email','section'=>'templates']) }}" role="menuitem" class="rt-sidebar-footer__menu-link">Mailvorlagen & Editor</a>
                <a href="{{ route('admin.settings',['tab'=>'email','section'=>'mails']) }}" role="menuitem" class="rt-sidebar-footer__menu-link">E-Mail-Einstellungen</a>
            </x-slot:content>
        </x-ui.dropdown.anchor-dropdown>
    @else
        <a href="{{ route('profile.show') }}" class="rt-sidebar-footer__button" aria-label="{{ __('app.settings') }}" title="{{ __('app.settings') }}" @if(request()->routeIs('profile.show')) aria-current="page" @endif>
            <i data-feather="settings" aria-hidden="true"></i>
        </a>
    @endif
</nav>
