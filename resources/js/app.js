// ZUERST importieren: das Modul installiert seine Fehler-Listener direkt beim
// Import, also bevor die folgenden Module ausgewertet werden. Ein Fehler in
// einem dieser Module wird dadurch bereits erfasst (siehe alpine-watchdog.js).
import './alpine-watchdog';
// Ton-Repertoire (window.RTSound) direkt danach: der Sound-Store unten liest
// window.RTSound schon bei seiner Registrierung (vorher public/js/rt-sounds.js
// als klassisches Skript, das vor diesem Modul lief).
import './rt-sounds';
import './bootstrap';
import { communicationModule } from './communication-module';
import '../css/communication-module.css';
// Einsatzgebiete-Karte: wie zuvor das Inline-<style> NACH den globalen Bundles.
import '../css/employee-regions.css';
// Meldungen (swal:toast/swal:alert/showAlert) auf SweetAlert2. Frueh
// importieren, damit auch Meldungen aus dem Seitenaufbau ankommen.
import './rt-alerts';
// Manuelles Livewire-Bundling (offizieller Livewire-3-Weg fuer eigene
// Alpine-Plugins/Stores): Livewire + Alpine aus dem Livewire-ESM-Bundle
// importieren, alles registrieren, DANN Livewire.start(). Die Layouts
// nutzen dafuer @livewireScriptConfig statt @livewireScripts.
import { Livewire, Alpine } from '../../vendor/livewire/livewire/dist/livewire.esm';
// Echo/Pusher (laravel-echo, pusher-js) an der bisherigen Importstelle.
import { setupRealtimeEcho } from './realtime-echo';
// GSAP-Setup (window.gsap/ScrollTrigger + deklarative data-anim-Reveals)
import './gsap';
// Vengeance-Motion (zeigergefuehrter Karten-Glow, Optik in app.css)
import './vengeance-motion';
import { wagonList } from './wagon-list';
import { wagonVideoCapture } from './wagon-video-capture';
import { numberInput } from './number-input';
import { dateField } from './date-field';
import { dateRangePicker } from './date-range-picker';
import { dateTimeField } from './date-time-field';
import { createWagonListMotion } from './wagon-list-motion';
import {
    registerRailtimePwaInstall,
    registerRailtimePushSettings,
    setupRailtimePwa,
} from './pwa';
import { createNotificationSeenCache } from './notification-seen-cache';
import { createNotificationPresentationContext } from './notification-presentation';
import { staffTimeline } from './staff-timeline';
import { mapHighlight } from './map-highlight';
import { anchorDropdown } from './anchor-dropdown';
import { timelinePlanning } from './timeline-planning-actions';
import { distributionPanel } from './distribution-accordion';
import { timelineEventDetails } from './timeline-event-details';
import { shiftDetailDrawer } from './shift-detail-drawer';
import { serviceDetailPanel } from './service-detail-panel';
import { aiIntakeRecorder } from './ai-intake-recorder';
import { aiIntakeComposer } from './ai-intake-composer';
import { workTimeCapture } from './work-time-capture';
import { workTimeTerminal } from './work-time-terminal';
import { workdayShortcuts } from './workday-shortcuts';
import { workdayOverview } from './workday-overview';
import { railtimeTabs } from './tabs';
import { areaMenu } from './area-menu';
import { profileTabMotion } from './profile-tab-motion';
import { listScrollPagination } from './list-scroll-pagination';
import { systemHealth } from './system-health';
import { initMobileFormFocusRecovery } from './mobile-form-focus';
import { initKeyboardViewport } from './keyboard-viewport';
import { welcomeIntro } from './welcome-intro';
import { railtimeChatbot } from './chatbot';
// Der Assistent traegt die AI-Partikelwolke mit RT-Morph.
import { railtimeAssistantCloud } from './assistant-particle-cloud';
import { aiAssist } from './ai-assist';
import { calendarReveal } from './calendar-reveal';
import { ensureRailTimeNavigationCoordinator } from './navigation-coordinator';
import { chatMessageActions } from './chat-message-actions';
import { modalBody, trackLivewireRequests } from './modal-body';
import { confirmationBridge, installLivewireConfirm } from './livewire-confirm';
import { filePreview } from './file-preview';
import { filePoolDropzone, filePoolExternalDrop } from './filepool-upload';
import { registerRailtimeLiveLocation } from './live-location';
import { createEmployeeLastLocation } from './employee-last-location';
import { registerRoutingMap } from './routing-map';
import { registerIntakeLocationMap } from './intake-location-map';
import {
    MAIL_SIGNATURE_SCHEMA,
    createMailBuilder,
    createMailNavigationController,
    projectForMailDocument,
    rehydrateAuthoritativeMailProject,
    resolvePortableMediaRequirementIds,
    serializeMailDocumentForSave,
} from './mail-builder';
import {
    normalizeMailCompatibilityManifest,
    normalizeMailCompatibilityReport,
} from './mail-compatibility';
// Aus app.js ausgelagerte Bausteine. Diese Module haben beim Import keine
// Seiteneffekte; app.js ruft ihre install-/register-Funktionen an den
// bisherigen Stellen auf, damit Store-Definitionen, Listener-Reihenfolge
// und livewire:*-Hooks unveraendert bleiben. Sie importieren Livewire/Alpine
// aus demselben ESM-Bundle (gleiche Modulinstanz) und stehen deshalb nach
// dessen Import.
import { registerThemeStore, rtApplyTheme } from './theme';
import { installNavigationOverlay } from './navigation-overlay';
import { createRealtimeNotifications } from './realtime-notifications';
import { createChatRealtime } from './chat-realtime';
import { chatAudioPlayer } from './chat-audio-player';
import { chatTranscriptScroll } from './chat-transcript-scroll';
import { chatPaneNavigation, installChatViewportReset } from './chat-pane-navigation';
import { adminDashboardCharts } from './admin-dashboard-charts';
import { operationsCoverageChart } from './operations-coverage-chart';
import { dashboardWidgetGrid } from './dashboard-widget-grid';
import { installValidationErrorSound } from './validation-error-sound';
import { installBackNavigation } from './back-navigation';
import { installFeatherRefresh } from './feather-icons';
import { installAdminLayout, sidebarNavigation } from './sidebar-navigation';

ensureRailTimeNavigationCoordinator(window, document);
Alpine.data('aiIntakeRecorder', aiIntakeRecorder);
Alpine.data('aiIntakeComposer', aiIntakeComposer);
Alpine.data('distributionPanel', distributionPanel);

const rtSeenNotifications = createNotificationSeenCache(window);
const rtNotificationContext = createNotificationPresentationContext(window);
const rtNotifications = createRealtimeNotifications({ rtSeenNotifications, rtNotificationContext });

// Service Worker fragt Praesenz ab bzw. meldet Vordergrund-Pushes.
rtNotifications.installServiceWorkerBridge();

// Echtzeit (Laravel Reverb): window.Pusher/window.Echo.
setupRealtimeEcho();

// ACHTUNG: collapse, mask, resize, intersect, focus, anchor, morph und persist
// bringt Livewires Alpine-Bundle bereits selbst mit. Nicht erneut per
// Alpine.plugin() registrieren (persist wirft sonst "Cannot redefine property"
// in Livewire.start(), die uebrigen wuerden auf aeltere npm-Versionen zurueckfallen).

registerThemeStore();

// Shell-Zustand liegt absichtlich ausserhalb des austauschbaren <body>.
// Livewire 3 behaelt globale Alpine-Stores bei wire:navigate im Speicher;
// dadurch bindet der neue Body noch im selben Navigationszyklus wieder an
// denselben Sidebar-Zustand und die persistierte Navigation klappt nicht
// sichtbar ein und wieder aus.
Alpine.store('shell', {
    desktopSidebarExpanded: document.body?.getAttribute('data-sidebar-expanded') === 'true',

    setDesktopSidebarExpanded(expanded) {
        this.desktopSidebarExpanded = Boolean(expanded);
    },
});

// Zentraler Sound-Store: spiegelt die RTSound-Einstellung (rt-sounds.js)
// fuer den Topbar-Schalter. RTSound ist die fuehrende Quelle und kuemmert
// sich um die Best-Effort-Persistenz — so koennen Icon-Zustand und echtes
// Abspielverhalten auch bei blockiertem Storage nie auseinanderlaufen.
// Beim Einschalten gibt ein kurzer Bestaetigungston hoerbares Feedback.
Alpine.store('sound', {
    enabled: window.RTSound ? window.RTSound.enabled : true,

    toggle() {
        this.enabled = window.RTSound ? window.RTSound.toggle() : !this.enabled;
        if (this.enabled) {
            window.RTSound?.play('success');
        }
    },
});

document.addEventListener('livewire:navigated', rtApplyTheme);

// Seitenwechsel-Overlay fuer wire:navigate (siehe navigation-overlay.js).
installNavigationOverlay();

// ---------------------------------------------------------------
// Nach einer Unterbrechung die echte Sitzung pruefen und gegebenenfalls den
// CSRF-Token erneuern. Kein automatisches Neuladen: offene Eingaben bleiben
// erhalten, und fehlgeschlagene Aktionen werden niemals still wiederholt.
// ---------------------------------------------------------------

// Zaehlt laufende Livewire-Anfragen fuer die Inhaltsschleuse der Dialoge:
// ein Dialogrumpf, der gerade nachgeladen wird, gilt als noch nicht bereit.
trackLivewireRequests(Livewire);

// wire:confirm oeffnet den RailTime-Bestaetigungsdialog statt confirm().
installLivewireConfirm(Livewire);

Livewire.hook('request', ({ fail }) => {
    fail(({ status, preventDefault }) => {
        if (status !== 419) {
            return;
        }

        preventDefault();
        void window.RailtimeAppLifecycle?.handleExpiredRequest();
    });
});

window.Alpine = Alpine;

// Bewegung des Wagenlisten-Editors. Global, weil die Alpine-Komponente sie
// bewusst nur defensiv anspricht — ohne dieses Objekt bleibt der Editor
// vollstaendig bedienbar, nur eben ohne Uebergaenge.
window.RailTimeWagonMotion = createWagonListMotion();

// E-Mail-Modus des Page Builders. Er bleibt bewusst ein eigener, nur von der
// Mail-Editorseite gestarteter Laufzeitpfad und wird nicht von der neuen
// dateibasierten Marketing-Motivverwaltung verwendet.
window.RailTimeMailBuilder = {
    signatureSchema: MAIL_SIGNATURE_SCHEMA,
    create: createMailBuilder,
    createNavigationController: createMailNavigationController,
    projectFor: projectForMailDocument,
    rehydrateAuthoritative: rehydrateAuthoritativeMailProject,
    resolvePortableMediaRequirementIds,
    serializeForSave: serializeMailDocumentForSave,
    normalizeCompatibilityManifest: normalizeMailCompatibilityManifest,
    normalizeCompatibilityReport: normalizeMailCompatibilityReport,
};

registerRailtimePushSettings(Alpine);
registerRailtimePwaInstall(Alpine);
setupRailtimePwa();
const rtLiveLocation = registerRailtimeLiveLocation(Alpine);
createEmployeeLastLocation().start();
registerRoutingMap(Alpine);
registerIntakeLocationMap(Alpine);

Alpine.data('wagonList', wagonList);
Alpine.data('wagonVideoCapture', wagonVideoCapture);
Alpine.data('rtNumberInput', numberInput);
Alpine.data('rtDateField', dateField);
Alpine.data('rtDateRangePicker', dateRangePicker);
Alpine.data('rtDateTimeField', dateTimeField);
Alpine.data('rtStaffTimeline', staffTimeline);
Alpine.data('rtMapHighlight', mapHighlight);
Alpine.data('rtAnchorDropdown', anchorDropdown);
Alpine.data('rtTimelinePlanning', timelinePlanning);
Alpine.data('rtTimelineEventDetails', timelineEventDetails);
Alpine.data('rtShiftDetailDrawer', shiftDetailDrawer);
Alpine.data('rtServiceDetailPanel', serviceDetailPanel);
Alpine.data('rtWorkTimeCapture', workTimeCapture);
Alpine.data('rtWorkTimeTerminal', workTimeTerminal);
Alpine.data('workdayShortcuts', workdayShortcuts);
Alpine.data('rtWorkdayOverview', workdayOverview);
Alpine.data('rtSidebarNavigation', sidebarNavigation);
Alpine.data('railtimeTabs', railtimeTabs);
Alpine.data('rtAreaMenu', areaMenu);
Alpine.data('rtProfileTabMotion', profileTabMotion);
Alpine.data('rtListScrollPagination', listScrollPagination);
Alpine.data('systemHealth', systemHealth);
Alpine.data('welcomeIntro', welcomeIntro);
Alpine.data('railtimeChatbot', railtimeChatbot);
Alpine.data('communicationModule', communicationModule);
Alpine.data('railtimeAssistantCloud', railtimeAssistantCloud);
Alpine.data('rtAiAssist', aiAssist);
Alpine.data('rtCalendarReveal', calendarReveal);
Alpine.data('chatMessageActions', chatMessageActions);
Alpine.data('rtModalBody', modalBody);
Alpine.data('rtConfirmBridge', confirmationBridge);
Alpine.data('filePreview', filePreview);
Alpine.data('filePoolDropzone', filePoolDropzone);
Alpine.data('filePoolExternalDrop', filePoolExternalDrop);
initMobileFormFocusRecovery();
initKeyboardViewport();

Alpine.data('chatRealtime', createChatRealtime({ rtNotificationContext, rtLiveLocation }));
Alpine.data('chatAudioPlayer', chatAudioPlayer);
Alpine.data('chatTranscriptScroll', chatTranscriptScroll);
installChatViewportReset();
Alpine.data('chatPaneNavigation', chatPaneNavigation);
Alpine.data('adminDashboardCharts', adminDashboardCharts);
Alpine.data('operationsCoverageChart', operationsCoverageChart);
Alpine.data('dashboardWidgetGrid', dashboardWidgetGrid);

// Fehlerton bei neuen Validierungsfehlern (siehe validation-error-sound.js).
installValidationErrorSound(Livewire);

Livewire.start();

rtApplyTheme();

rtNotifications.enableForegroundPushHandling();
rtNotifications.subscribeUserChannel();
rtNotifications.installNotificationSoundTriggers();

// Zurueck-Navigation ueber den eigenen Seitenverlauf (window.RTNavigation).
installBackNavigation();

// Feather-Platzhalter nach Livewire-Morphs ersetzen.
installFeatherRefresh(Livewire);

// Sidebar und Admin-Layout (Desktop-Hover, Drawer, Swipe, aktiver Menuepunkt).
installAdminLayout();
