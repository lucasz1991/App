import './bootstrap';
// Manuelles Livewire-Bundling (offizieller Livewire-3-Weg fuer eigene
// Alpine-Plugins/Stores): Livewire + Alpine aus dem Livewire-ESM-Bundle
// importieren, alles registrieren, DANN Livewire.start(). Die Layouts
// nutzen dafuer @livewireScriptConfig statt @livewireScripts.
import { Livewire, Alpine } from '../../vendor/livewire/livewire/dist/livewire.esm';
import collapse from '@alpinejs/collapse';
import mask from '@alpinejs/mask';
import resize from '@alpinejs/resize';
import intersect from '@alpinejs/intersect';
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import Swiper from 'swiper';
import 'swiper/css';
// GSAP-Setup (window.gsap/ScrollTrigger + deklarative data-anim-Reveals)
import './gsap';

const loadAdminDashboardECharts = () => import('./admin-dashboard-echarts');

// ---------------------------------------------------------------
// Echtzeit (Laravel Reverb, Pusher-Protokoll). Nur aktiv, wenn ein
// Reverb-Key konfiguriert ist — ohne laufenden Reverb-Server faellt
// die App auf das 60s-Polling des Posteingangs zurueck.
// ---------------------------------------------------------------
window.Pusher = Pusher;

if (import.meta.env.VITE_REVERB_APP_KEY) {
    window.Echo = new Echo({
        broadcaster: 'reverb',
        key: import.meta.env.VITE_REVERB_APP_KEY,
        wsHost: import.meta.env.VITE_REVERB_HOST,
        wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
        wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
        forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
        enabledTransports: ['ws', 'wss'],
    });
}

// ACHTUNG: persist, sort und anchor bringt Livewires Alpine-Bundle bereits
// selbst mit — eine erneute Registrierung wirft "Cannot redefine property"
// in Livewire.start() und killt das restliche Modul (Sidebar-Init etc.).
Alpine.plugin(collapse);
Alpine.plugin(mask);
Alpine.plugin(resize);
Alpine.plugin(intersect);

// Zentraler Theme-Store: liest die Einstellung beim Start aus localStorage
// und schreibt sie beim Umschalten zurueck. Ueberlebt Reloads UND
// wire:navigate-Seitenwechsel (der Store lebt im Speicher weiter, Alpine
// bindet nach jeder Navigation neu).
Alpine.store('theme', {
    dark: localStorage.getItem('rt-theme') === 'true',

    toggle() {
        this.dark = !this.dark;
        localStorage.setItem('rt-theme', this.dark ? 'true' : 'false');
        rtApplyTheme();
    },
});

// Theme auf <html>/<body> anwenden. Noetig nach jeder wire:navigate-
// Navigation, weil Livewire dabei das <html>-Element (inkl. dark-Klasse)
// durch die serverseitig gerenderte Version ersetzt — dokumentiertes
// Livewire-Muster: im 'livewire:navigated'-Event erneut anwenden.
function rtApplyTheme() {
    const dark = Alpine.store('theme').dark;
    document.documentElement.classList.toggle('dark', dark);
    if (document.body) {
        document.body.setAttribute('data-mode', dark ? 'dark' : 'light');
    }
}

document.addEventListener('livewire:navigated', rtApplyTheme);

// ---------------------------------------------------------------
// Seitenwechsel-Overlay fuer wire:navigate: legt eine weiche, leicht
// unscharfe Ebene mit RailTime-Spinner ueber den Inhalt, damit ein
// Seitenwechsel sichtbar "laedt". Wird erst nach kurzer Verzoegerung
// gezeigt (kein Flackern bei vorab geladenen Seiten) und nach dem
// body-Swap bei Bedarf neu angehaengt.
// ---------------------------------------------------------------
(function () {
    let overlay = null;
    let showTimer = null;
    let active = false;

    function ensureOverlay() {
        // Livewire tauscht bei wire:navigate den <body> aus -> ggf. neu anhaengen.
        if (overlay && document.body.contains(overlay)) {
            return overlay;
        }
        if (!overlay) {
            overlay = document.createElement('div');
            overlay.id = 'rt-nav-overlay';
            overlay.setAttribute('aria-hidden', 'true');
            overlay.innerHTML = '<div class="rt-nav-spinner"></div>';
            overlay.style.cssText = [
                'position:fixed', 'inset:0', 'z-index:190',
                'display:flex', 'align-items:center', 'justify-content:center',
                'opacity:0', 'pointer-events:none',
                'backdrop-filter:blur(2px)', '-webkit-backdrop-filter:blur(2px)',
                'transition:opacity .2s ease',
            ].join(';');
        }
        document.body.appendChild(overlay);
        return overlay;
    }

    function start() {
        active = true;
        clearTimeout(showTimer);
        showTimer = setTimeout(function () {
            if (!active) return;
            const o = ensureOverlay();
            const dark = document.documentElement.classList.contains('dark');
            o.style.background = dark ? 'rgba(11,17,32,.55)' : 'rgba(243,246,250,.5)';
            o.style.opacity = '1';
        }, 120);
    }

    function done() {
        active = false;
        clearTimeout(showTimer);
        if (overlay) {
            overlay.style.opacity = '0';
        }
    }

    document.addEventListener('livewire:navigate', start);
    document.addEventListener('livewire:navigating', start);
    document.addEventListener('livewire:navigated', done);
})();

window.Alpine = Alpine;

Alpine.data('chatRealtime', (config) => ({
    channel: null,
    typingLabel: '',
    typingTimer: null,
    recorder: null,
    recording: false,
    recordingSeconds: 0,
    recordingLabel: '',
    recordingTimer: null,
    recordingStream: null,
    chunks: [],

    init() {
        this.recordingLabel = config.recordingText || 'Aufnahme';

        if (!window.Echo || !config.chatId) {
            return;
        }

        this.channel = window.Echo.private(`chat.${config.chatId}`)
            .listen('.chat.message.sent', (event) => {
                Livewire.dispatch('chat:refresh', { chatId: Number(event.chatId) });
                Livewire.dispatch('inbox:refresh');
            })
            .listen('.chat.read', (event) => {
                Livewire.dispatch('chat:refresh', { chatId: Number(event.chatId) });
            })
            .listenForWhisper('typing', (event) => {
                if (Number(event.userId) === Number(config.userId)) {
                    return;
                }

                window.clearTimeout(this.typingTimer);
                this.typingLabel = `${event.userName} ${config.typingText}`;
                this.typingTimer = window.setTimeout(() => {
                    this.typingLabel = '';
                }, 1800);
            });
    },

    destroy() {
        window.clearTimeout(this.typingTimer);
        window.clearInterval(this.recordingTimer);
        this.stopRecordingTracks();

        if (window.Echo && config.chatId) {
            window.Echo.leave(`chat.${config.chatId}`);
        }
    },

    sendTyping() {
        this.channel?.whisper('typing', {
            userId: Number(config.userId),
            userName: config.userName,
        });
    },

    async toggleRecording() {
        if (this.recording) {
            this.recorder?.stop();
            return;
        }

        if (!navigator.mediaDevices?.getUserMedia || !window.MediaRecorder) {
            window.dispatchEvent(new CustomEvent('swal:toast', {
                detail: { type: 'error', text: 'Sprachaufnahme wird von diesem Browser nicht unterstützt.' },
            }));
            return;
        }

        try {
            this.recordingStream = await navigator.mediaDevices.getUserMedia({ audio: true });
            const preferredMime = [
                'audio/webm;codecs=opus',
                'audio/ogg;codecs=opus',
                'audio/mp4',
            ].find((mime) => MediaRecorder.isTypeSupported(mime));

            this.chunks = [];
            this.recorder = preferredMime
                ? new MediaRecorder(this.recordingStream, { mimeType: preferredMime })
                : new MediaRecorder(this.recordingStream);

            this.recorder.addEventListener('dataavailable', (event) => {
                if (event.data.size > 0) {
                    this.chunks.push(event.data);
                }
            });

            this.recorder.addEventListener('stop', () => this.finishRecording(), { once: true });
            this.recorder.start(250);
            this.recording = true;
            this.recordingSeconds = 0;
            this.updateRecordingLabel();
            this.recordingTimer = window.setInterval(() => {
                this.recordingSeconds += 1;
                this.updateRecordingLabel();
            }, 1000);
        } catch (error) {
            this.stopRecordingTracks();
            window.dispatchEvent(new CustomEvent('swal:toast', {
                detail: { type: 'error', text: 'Das Mikrofon konnte nicht verwendet werden.' },
            }));
        }
    },

    finishRecording() {
        window.clearInterval(this.recordingTimer);
        this.recording = false;
        this.stopRecordingTracks();

        if (this.chunks.length === 0) {
            return;
        }

        const mime = this.recorder?.mimeType || this.chunks[0]?.type || 'audio/webm';
        const extension = mime.includes('ogg') ? 'ogg' : (mime.includes('mp4') ? 'm4a' : 'webm');
        const file = new File(
            [new Blob(this.chunks, { type: mime })],
            `sprachnachricht-${Date.now()}.${extension}`,
            { type: mime }
        );

        this.$wire.uploadMultiple('uploads', [file]);
        this.chunks = [];
    },

    stopRecordingTracks() {
        this.recordingStream?.getTracks().forEach((track) => track.stop());
        this.recordingStream = null;
    },

    updateRecordingLabel() {
        const minutes = Math.floor(this.recordingSeconds / 60);
        const seconds = String(this.recordingSeconds % 60).padStart(2, '0');
        this.recordingLabel = `${config.recordingText || 'Aufnahme'} ${minutes}:${seconds}`;
    },
}));

Alpine.data('chatAudioPlayer', () => ({
    playing: false,
    currentTime: 0,
    duration: 0,

    get progress() {
        return this.duration > 0 ? Math.min(100, (this.currentTime / this.duration) * 100) : 0;
    },

    get formattedTime() {
        const value = this.playing || this.currentTime > 0 ? this.currentTime : this.duration;
        const safeValue = Number.isFinite(value) ? Math.max(0, value) : 0;
        const minutes = Math.floor(safeValue / 60);
        const seconds = String(Math.floor(safeValue % 60)).padStart(2, '0');

        return `${minutes}:${seconds}`;
    },

    toggle() {
        if (this.$refs.audio.paused) {
            this.$refs.audio.play().catch(() => {
                this.playing = false;
            });
            return;
        }

        this.$refs.audio.pause();
    },

    metadataLoaded() {
        this.duration = Number.isFinite(this.$refs.audio.duration) ? this.$refs.audio.duration : 0;
    },

    timeUpdated() {
        this.currentTime = this.$refs.audio.currentTime || 0;
    },

    seek(value) {
        const nextTime = Math.max(0, Math.min(Number(value) || 0, this.duration || 0));
        this.$refs.audio.currentTime = nextTime;
        this.currentTime = nextTime;
    },

    ended() {
        this.playing = false;
        this.currentTime = 0;
        this.$refs.audio.currentTime = 0;
    },
}));

Alpine.data('chatPaneNavigation', (initialHasSelection = false) => ({
    mobilePane: initialHasSelection ? 'chat' : 'list',
    listCollapsed: localStorage.getItem('rt-chat-list-collapsed') === 'true',
    touchStartX: null,
    touchStartY: null,

    showList() {
        this.mobilePane = 'list';
    },

    showChat() {
        this.mobilePane = 'chat';
    },

    resumeLastChat() {
        if (this.$root.dataset.hasSelectedChat === 'true') {
            this.showChat();
        }
    },

    toggleList() {
        this.listCollapsed = !this.listCollapsed;

        try {
            localStorage.setItem('rt-chat-list-collapsed', this.listCollapsed ? 'true' : 'false');
        } catch (_) {
            // Die Navigation funktioniert auch, wenn der Browser Storage sperrt.
        }
    },

    touchStart(event) {
        if (window.innerWidth >= 768 || event.touches.length !== 1) {
            this.cancelSwipe();
            return;
        }

        if (event.target.closest('input, textarea, select, button, audio, video, [role="dialog"], [data-no-chat-swipe]')) {
            this.cancelSwipe();
            return;
        }

        this.touchStartX = event.touches[0].clientX;
        this.touchStartY = event.touches[0].clientY;
    },

    touchEnd(event) {
        if (window.innerWidth >= 768 || this.touchStartX === null || event.changedTouches.length !== 1) {
            this.cancelSwipe();
            return;
        }

        const deltaX = event.changedTouches[0].clientX - this.touchStartX;
        const deltaY = event.changedTouches[0].clientY - this.touchStartY;
        const threshold = Math.max(72, Math.min(140, window.innerWidth * 0.22));

        if (Math.abs(deltaX) >= threshold && Math.abs(deltaX) > Math.abs(deltaY) * 1.25) {
            if (deltaX < 0 && this.mobilePane === 'chat') {
                this.showList();
            } else if (deltaX > 0 && this.mobilePane === 'list') {
                this.resumeLastChat();
            }
        }

        this.cancelSwipe();
    },

    cancelSwipe() {
        this.touchStartX = null;
        this.touchStartY = null;
    },
}));

Alpine.data('adminDashboardCharts', (config = {}) => ({
    charts: [],
    counterTweens: [],
    themeObserver: null,
    resizeObserver: null,
    renderTimer: null,

    init() {
        this.$nextTick(() => {
            this.animateCounters();
            this.renderCharts();
        });

        this.themeObserver = new MutationObserver(() => {
            window.clearTimeout(this.renderTimer);
            this.renderTimer = window.setTimeout(() => this.renderCharts(), 80);
        });
        this.themeObserver.observe(document.documentElement, {
            attributes: true,
            attributeFilter: ['class'],
        });
    },

    destroy() {
        window.clearTimeout(this.renderTimer);
        this.themeObserver?.disconnect();
        this.resizeObserver?.disconnect();
        this.counterTweens.forEach((tween) => tween.kill());
        this.destroyCharts();
    },

    animateCounters() {
        const formatter = new Intl.NumberFormat(document.documentElement.lang || 'de-DE');
        const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        this.$root.querySelectorAll('[data-dashboard-count]').forEach((element) => {
            const target = Number(element.dataset.dashboardCount || 0);

            if (!window.gsap || reduceMotion) {
                element.textContent = formatter.format(target);
                return;
            }

            const state = { value: 0 };
            const tween = window.gsap.to(state, {
                value: target,
                duration: 1.15,
                delay: Number(element.dataset.dashboardDelay || 0),
                ease: 'power3.out',
                onUpdate: () => {
                    element.textContent = formatter.format(Math.round(state.value));
                },
            });
            this.counterTweens.push(tween);
        });
    },

    destroyCharts() {
        this.resizeObserver?.disconnect();
        this.charts.forEach((chart) => chart.dispose());
        this.charts = [];
    },

    mountChart(element, option) {
        if (!element) return;

        const chart = echarts.init(element, null, { renderer: 'svg' });
        chart.setOption(option);
        this.charts.push(chart);
        this.resizeObserver?.observe(element);
    },

    renderCharts() {
        this.destroyCharts();

        const dark = document.documentElement.classList.contains('dark');
        const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        const textColor = dark ? '#a9b6c9' : '#64748b';
        const strongText = dark ? '#f8fafc' : '#172033';
        const gridColor = dark ? '#273449' : '#e2e8f0';
        const surfaceColor = dark ? '#111827' : '#ffffff';
        const mutedSurface = dark ? '#273449' : '#e8edf4';
        const red = '#e4002b';
        const fontFamily = 'Plus Jakarta Sans Variable, sans-serif';
        const growth = config.userGrowth || { labels: [], totals: [], registrations: [] };
        const activity = config.activity || { labels: [], values: [] };
        const status = config.status || { labels: [], values: [] };
        const animation = reduceMotion ? false : {
            animation: true,
            animationDuration: 720,
            animationEasing: 'cubicOut',
            animationDelay: (index) => index * 28,
        };
        const tooltip = {
            backgroundColor: surfaceColor,
            borderColor: gridColor,
            borderWidth: 1,
            padding: [9, 11],
            textStyle: { color: strongText, fontFamily, fontSize: 12 },
            extraCssText: 'border-radius:10px;box-shadow:0 12px 30px rgba(15,23,42,.12);',
        };

        this.resizeObserver = new ResizeObserver((entries) => {
            entries.forEach(({ target }) => echarts.getInstanceByDom(target)?.resize());
        });

        if (this.$refs.growthChart) {
            const registrationsMax = Math.max(1, ...(growth.registrations || []));
            const compact = this.$refs.growthChart.clientWidth < 560;

            this.mountChart(this.$refs.growthChart, {
                ...animation,
                textStyle: { fontFamily },
                aria: { enabled: true },
                grid: { top: 18, right: 10, bottom: 12, left: 8, containLabel: true },
                tooltip: { ...tooltip, trigger: 'axis', axisPointer: { type: 'line', lineStyle: { color: gridColor } } },
                xAxis: {
                    type: 'category',
                    data: growth.labels || [],
                    boundaryGap: true,
                    axisLine: { lineStyle: { color: gridColor } },
                    axisTick: { show: false },
                    axisLabel: { color: textColor, fontSize: 10, margin: 14, interval: compact ? 3 : 1 },
                },
                yAxis: [
                    {
                        type: 'value',
                        min: 0,
                        minInterval: 1,
                        axisLine: { show: false },
                        axisTick: { show: false },
                        axisLabel: { color: textColor, fontSize: 10, margin: 12 },
                        splitLine: { lineStyle: { color: gridColor, width: 1 } },
                    },
                    { type: 'value', min: 0, max: registrationsMax, show: false },
                ],
                series: [
                    {
                        name: config.labels?.total || 'Gesamt',
                        type: 'line',
                        data: growth.totals || [],
                        smooth: 0.32,
                        showSymbol: false,
                        symbol: 'circle',
                        symbolSize: 7,
                        lineStyle: { color: strongText, width: 2.5, cap: 'round' },
                        itemStyle: { color: strongText, borderColor: surfaceColor, borderWidth: 2 },
                        emphasis: { focus: 'series', scale: 1.15 },
                        z: 3,
                    },
                    {
                        name: config.labels?.registrations || 'Neu',
                        type: 'bar',
                        yAxisIndex: 1,
                        data: growth.registrations || [],
                        barWidth: compact ? 4 : 6,
                        itemStyle: { color: red, borderRadius: [4, 4, 0, 0] },
                        emphasis: { itemStyle: { color: '#f51b3b' } },
                        z: 2,
                    },
                ],
            });
        }

        if (this.$refs.statusChart) {
            const totalAccounts = (status.values || []).reduce((sum, value) => sum + Number(value || 0), 0);

            this.mountChart(this.$refs.statusChart, {
                ...animation,
                textStyle: { fontFamily },
                aria: { enabled: true },
                title: {
                    text: new Intl.NumberFormat(document.documentElement.lang || 'de-DE').format(totalAccounts),
                    subtext: config.labels?.accounts || 'Konten',
                    left: 'center',
                    top: '34%',
                    textStyle: { color: strongText, fontFamily, fontSize: 27, fontWeight: 650 },
                    subtextStyle: { color: textColor, fontFamily, fontSize: 10, lineHeight: 18 },
                },
                tooltip: { ...tooltip, trigger: 'item', formatter: '{b}: <strong>{c}</strong> ({d}%)' },
                series: [{
                    type: 'pie',
                    radius: ['73%', '84%'],
                    center: ['50%', '48%'],
                    startAngle: 90,
                    clockwise: true,
                    avoidLabelOverlap: true,
                    itemStyle: { borderWidth: 0 },
                    label: { show: false },
                    emphasis: { scale: true, scaleSize: 4 },
                    data: (status.values || []).map((value, index) => ({
                        value,
                        name: status.labels?.[index] || '',
                        itemStyle: { color: index === 0 ? red : mutedSurface },
                    })),
                }],
            });
        }

        if (this.$refs.activityChart) {
            this.mountChart(this.$refs.activityChart, {
                ...animation,
                textStyle: { fontFamily },
                aria: { enabled: true },
                grid: { top: 12, right: 8, bottom: 8, left: 8 },
                tooltip: {
                    ...tooltip,
                    trigger: 'axis',
                    axisPointer: { type: 'line', lineStyle: { color: '#475569' } },
                    formatter: (items) => {
                        const point = items?.[0];
                        return point ? `${activity.labels?.[point.dataIndex] || ''}<br><strong>${point.value}</strong> ${config.labels?.activity || ''}` : '';
                    },
                },
                xAxis: { type: 'category', data: activity.labels || [], show: false, boundaryGap: false },
                yAxis: { type: 'value', show: false, min: 0, minInterval: 1 },
                series: [{
                    name: config.labels?.activity || 'Aktive Nutzer',
                    type: 'line',
                    data: activity.values || [],
                    smooth: 0.28,
                    symbol: 'circle',
                    symbolSize: 5,
                    showSymbol: true,
                    lineStyle: { color: red, width: 2 },
                    itemStyle: { color: red, borderColor: '#080b10', borderWidth: 2 },
                    areaStyle: {
                        color: {
                            type: 'linear', x: 0, y: 0, x2: 0, y2: 1,
                            colorStops: [
                                { offset: 0, color: '#49121d' },
                                { offset: 1, color: '#10141b' },
                            ],
                        },
                    },
                    emphasis: { focus: 'series', scale: 1.25 },
                }],
            });
        }
    },
}));

Livewire.start();

rtApplyTheme();

// ---------------------------------------------------------------
// Live-Benachrichtigungen: privaten User-Channel abonnieren.
// Bei neuer Nachricht: Toast anzeigen + Posteingang aktualisieren.
// Das Abo ueberlebt wire:navigate (Modul laeuft nur einmal).
// ---------------------------------------------------------------
(function () {
    const userId = document.querySelector('meta[name="rt-user-id"]')?.content;
    if (!window.Echo || !userId) {
        return;
    }

    const lang = window.rtLang || {};

    window.Echo.private(`App.Models.User.${userId}`)
        .listen('.message.received', (event) => {
            const title = lang.newMessage || 'Neue Nachricht';
            const from = event.from ? `${lang.from || 'Von'}: ${event.from}` : '';
            const text = [from, event.subject].filter(Boolean).join(' — ');

            window.dispatchEvent(new CustomEvent('swal:toast', {
                detail: { type: 'info', title, text },
            }));

            Livewire.dispatch('inbox:refresh');
        })
        .listen('.chat.message.received', (event) => {
            const title = lang.newChatMessage || 'Neue Chatnachricht';
            const text = event.from ? `${lang.from || 'Von'}: ${event.from}` : '';

            window.dispatchEvent(new CustomEvent('swal:toast', {
                detail: { type: 'info', title, text },
            }));

            Livewire.dispatch('chat:refresh', { chatId: Number(event.chatId) });
            Livewire.dispatch('inbox:refresh');
        });
})();

window.Swiper = Swiper;
let sidebarCollapseTimer = null;

function initMetisMenu() {
    if (!window.MetisMenu) {
        return;
    }

    const sideMenu = document.getElementById('side-menu');
    if (!sideMenu) {
        return;
    }

    if (window.__webreachMetisMenu && typeof window.__webreachMetisMenu.dispose === 'function') {
        window.__webreachMetisMenu.dispose();
    }

    window.__webreachMetisMenu = new window.MetisMenu('#side-menu');
}

function clearSidebarCollapseTimer() {
    if (sidebarCollapseTimer) {
        window.clearTimeout(sidebarCollapseTimer);
        sidebarCollapseTimer = null;
    }
}

function isDesktopHoverSidebar() {
    return window.innerWidth >= 1140 && Boolean(document.querySelector('.vertical-menu'));
}

function isSidebarHoveredOrFocused() {
    const activeElement = document.activeElement;
    const hoverInsideSidebar = document.querySelector('.vertical-menu:hover, .topbar-brand:hover');
    const focusInsideSidebar = activeElement?.closest('.vertical-menu, .topbar-brand');

    return Boolean(hoverInsideSidebar || focusInsideSidebar);
}

function setDesktopSidebarExpanded(expanded) {
    if (document.body.getAttribute('data-sidebar-collapsible') !== 'true') {
        return;
    }

    document.body.setAttribute('data-sidebar-expanded', expanded ? 'true' : 'false');
    syncSidebarToggleState();
}

function setMobileSidebarOpen(open) {
    document.body.classList.toggle('sidebar-enable', open);
    syncSidebarToggleState();
}

function syncSidebarToggleState() {
    const expanded = isDesktopHoverSidebar()
        ? document.body.getAttribute('data-sidebar-expanded') === 'true'
        : document.body.classList.contains('sidebar-enable');

    document.querySelectorAll('.vertical-menu-btn').forEach((button) => {
        button.setAttribute('aria-expanded', expanded ? 'true' : 'false');
    });
}

function scheduleDesktopSidebarCollapse() {
    clearSidebarCollapseTimer();

    sidebarCollapseTimer = window.setTimeout(() => {
        const activeElement = document.activeElement;
        const focusInsideSidebar = activeElement?.closest('.vertical-menu, .topbar-brand');
        const hoverInsideSidebar = document.querySelector('.vertical-menu:hover, .topbar-brand:hover');

        if (!focusInsideSidebar && !hoverInsideSidebar) {
            setDesktopSidebarExpanded(false);
        }
    }, 90);
}

function syncSidebarInteractionMode() {
    const hasSidebar = Boolean(document.querySelector('.vertical-menu'));
    if (!hasSidebar) {
        return;
    }

    const desktopMode = isDesktopHoverSidebar();
    document.body.setAttribute('data-sidebar-collapsible', desktopMode ? 'true' : 'false');

    if (desktopMode) {
        setMobileSidebarOpen(false);

        const isExpanded = document.body.getAttribute('data-sidebar-expanded') === 'true';
        const shouldStayExpanded = isExpanded || isSidebarHoveredOrFocused();

        document.body.setAttribute('data-sidebar-expanded', shouldStayExpanded ? 'true' : 'false');
        syncSidebarToggleState();
        return;
    }

    clearSidebarCollapseTimer();
    document.body.setAttribute('data-sidebar-expanded', 'false');
    syncSidebarToggleState();
}

function initLeftMenuCollapse() {
    document.querySelectorAll('.vertical-menu-btn').forEach((button) => {
        if (button.dataset.webreachBound === '1') {
            return;
        }

        button.dataset.webreachBound = '1';

        button.addEventListener('click', (event) => {
            event.preventDefault();

            if (isDesktopHoverSidebar()) {
                clearSidebarCollapseTimer();
                setDesktopSidebarExpanded(document.body.getAttribute('data-sidebar-expanded') !== 'true');
                return;
            }

            setMobileSidebarOpen(!document.body.classList.contains('sidebar-enable'));
            initMenuItemScroll();
        });
    });
}

function initActiveMenu() {
    const pageUrl = window.location.href.split(/[?#]/)[0];
    const menuItems = Array.from(document.querySelectorAll('#sidebar-menu a'));
    const nestedLists = document.querySelectorAll('#sidebar-menu ul');

    menuItems.forEach((item) => item.classList.remove('active'));
    document.querySelectorAll('#sidebar-menu li.mm-active').forEach((item) => item.classList.remove('mm-active'));
    nestedLists.forEach((list) => {
        if (list.id !== 'side-menu') {
            list.classList.remove('mm-show');
        }
    });

    const exactMatches = menuItems.filter((item) => item.href === pageUrl);
    const fallbackMatches = menuItems.filter((item) => item.dataset.menuActive === 'true');
    const activeItems = exactMatches.length > 0 ? exactMatches : fallbackMatches;

    activeItems.forEach((item) => {
        item.classList.add('active');

        let currentLi = item.closest('li');
        while (currentLi) {
            currentLi.classList.add('mm-active');

            const parentUl = currentLi.parentElement;
            if (parentUl && parentUl.tagName === 'UL' && parentUl.id !== 'side-menu') {
                parentUl.classList.add('mm-show');
            }

            currentLi = parentUl ? parentUl.closest('li') : null;
        }
    });
}

function initMenuItemScroll() {
    setTimeout(() => {
        const sidebarMenu = document.getElementById('side-menu');
        const activeItem = sidebarMenu?.querySelector('.mm-active .active');

        if (!activeItem || activeItem.offsetTop <= 300) {
            return;
        }

        const verticalMenu = document.querySelector('.vertical-menu');
        const scroller = verticalMenu?.querySelector('.simplebar-content-wrapper');

        if (scroller) {
            scroller.scrollTop = activeItem.offsetTop;
        }
    }, 150);
}

function initFeather() {
    if (window.feather && typeof window.feather.replace === 'function') {
        window.feather.replace();
    }
}

function initSidebarInteractions() {
    if (document.body.dataset.webreachSidebarInteractionsBound !== '1') {
        document.body.dataset.webreachSidebarInteractionsBound = '1';

        document.querySelectorAll('.vertical-menu, .topbar-brand').forEach((element) => {
            if (element.dataset.webreachSidebarHoverBound === '1') {
                return;
            }

            element.dataset.webreachSidebarHoverBound = '1';

            element.addEventListener('mouseenter', () => {
                if (!isDesktopHoverSidebar()) {
                    return;
                }

                clearSidebarCollapseTimer();
                setDesktopSidebarExpanded(true);
            });

            element.addEventListener('mouseleave', () => {
                if (!isDesktopHoverSidebar()) {
                    return;
                }

                scheduleDesktopSidebarCollapse();
            });

            element.addEventListener('focusin', () => {
                if (!isDesktopHoverSidebar()) {
                    return;
                }

                clearSidebarCollapseTimer();
                setDesktopSidebarExpanded(true);
            });

            element.addEventListener('focusout', () => {
                if (!isDesktopHoverSidebar()) {
                    return;
                }

                scheduleDesktopSidebarCollapse();
            });
        });

        document.addEventListener(
            'pointerdown',
            (event) => {
                const target = event.target instanceof Element ? event.target : null;

                if (isDesktopHoverSidebar()) {
                    if (!target || !target.closest('.vertical-menu, .topbar-brand')) {
                        clearSidebarCollapseTimer();
                        setDesktopSidebarExpanded(false);
                    }

                    return;
                }

                if (!target || !target.closest('.vertical-menu, .vertical-menu-btn')) {
                    setMobileSidebarOpen(false);
                }
            },
            true
        );

        document.addEventListener('keydown', (event) => {
            if (event.key !== 'Escape') {
                return;
            }

            clearSidebarCollapseTimer();
            setDesktopSidebarExpanded(false);
            setMobileSidebarOpen(false);
        });

        window.addEventListener('resize', syncSidebarInteractionMode);
    }

    syncSidebarInteractionMode();
}

function initAdminLayout() {
    syncSidebarInteractionMode();
    initMetisMenu();
    initLeftMenuCollapse();
    initSidebarInteractions();
    initActiveMenu();
    initMenuItemScroll();
    initFeather();
}

document.addEventListener('DOMContentLoaded', initAdminLayout);
document.addEventListener('livewire:load', initAdminLayout);
document.addEventListener('livewire:navigated', initAdminLayout);
