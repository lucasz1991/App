import { Alpine } from '../../vendor/livewire/livewire/dist/livewire.esm';

// Zentraler Theme-Store: liest die Einstellung beim Start aus localStorage
// und schreibt sie beim Umschalten zurueck. Ueberlebt Reloads UND
// wire:navigate-Seitenwechsel (der Store lebt im Speicher weiter, Alpine
// bindet nach jeder Navigation neu).
function registerThemeStore() {
    Alpine.store('theme', {
        dark: localStorage.getItem('rt-theme') === 'true',

        toggle() {
            this.dark = !this.dark;
            localStorage.setItem('rt-theme', this.dark ? 'true' : 'false');
            rtApplyTheme();
        },
    });
}

// Theme auf <html>/<body> anwenden. Noetig nach jeder wire:navigate-
// Navigation, weil Livewire dabei das <html>-Element (inkl. dark-Klasse)
// durch die serverseitig gerenderte Version ersetzt — dokumentiertes
// Livewire-Muster: im 'livewire:navigated'-Event erneut anwenden.
function rtApplyTheme() {
    const dark = Alpine.store('theme').dark;
    document.documentElement.classList.toggle('dark', dark);
    // Einziger Dark-Mode-Selektor ist html.dark. data-mode bleibt nur fuer die
    // [data-mode=dark]-Regeln der Legacy-Datei build/css/tailwind.min.css.
    if (document.body) {
        document.body.setAttribute('data-mode', dark ? 'dark' : 'light');
    }
}

export { registerThemeStore, rtApplyTheme };
