import { Livewire, Alpine } from '../../vendor/livewire/livewire/dist/livewire.esm';
import collapse from '@alpinejs/collapse';
import { numberInput } from './number-input';
import { dateField } from './date-field';
import { dateRangePicker } from './date-range-picker';
import { dateTimeField } from './date-time-field';
import { modalBody, trackLivewireRequests } from './modal-body';
import { portalNavigation } from './customer-portal-navigation';

// Deliberately isolated: no employee chat, Echo, GPS, PWA or work-time capture bootstrap.
window.Alpine = Alpine;
window.Livewire = Livewire;
Alpine.plugin(collapse);
Alpine.store('theme', {
    dark: localStorage.getItem('rt-theme') === 'true',
    toggle() {
        this.dark = !this.dark;
        localStorage.setItem('rt-theme', this.dark ? 'true' : 'false');
        document.documentElement.classList.toggle('dark', this.dark);
    },
});
Alpine.data('rtNumberInput', numberInput);
Alpine.data('rtDateField', dateField);
Alpine.data('rtDateRangePicker', dateRangePicker);
Alpine.data('rtDateTimeField', dateTimeField);
Alpine.data('rtModalBody', modalBody);
Alpine.data('rtPortalNavigation', portalNavigation);
trackLivewireRequests(Livewire);
Livewire.start();
