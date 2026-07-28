const DEFAULT_STALE_AFTER_MS = 30_000;
const DEFAULT_HEARTBEAT_MS = 10_000;
const MOBILE_CHAT_BREAKPOINT = 768;

function numericChatId(value) {
    const chatId = Number(value || 0);

    return Number.isFinite(chatId) && chatId > 0 ? chatId : 0;
}

function createClientId(windowLike) {
    if (typeof windowLike?.crypto?.randomUUID === 'function') {
        return windowLike.crypto.randomUUID();
    }

    return `railtime-${Date.now()}-${Math.random().toString(36).slice(2)}`;
}

function createPresenceChannel(windowLike) {
    if (typeof windowLike?.BroadcastChannel !== 'function') {
        return null;
    }

    return new windowLike.BroadcastChannel('railtime-app-presence');
}

/**
 * Liefert nur dann einen Chat, wenn dessen Unterhaltung in diesem Tab wirklich
 * sichtbar ist. Auf Mobilgeraeten zaehlt die ausgeklappte Chatliste daher nicht
 * als "offener Chat", obwohl Livewire die letzte Auswahl weiterhin haelt.
 */
export function activeVisibleChatId({
    documentLike = globalThis.document,
    windowLike = globalThis.window,
} = {}) {
    if (!documentLike || documentLike.visibilityState !== 'visible') {
        return 0;
    }

    const chatPage = documentLike.querySelector?.('[data-active-chat-id]');
    const chatId = numericChatId(chatPage?.dataset?.activeChatId);

    if (!chatId) {
        return 0;
    }

    if (
        Number(windowLike?.innerWidth || 0) < MOBILE_CHAT_BREAKPOINT
        && chatPage.dataset.mobilePane !== 'chat'
    ) {
        return 0;
    }

    return chatId;
}

/**
 * Geraeteweite Praesenz fuer mehrere RailTime-Tabs. BroadcastChannel verteilt
 * nur kurzlebige UI-Zustaende; Nachrichteninhalte oder Benutzerkennungen
 * verlassen den jeweiligen Tab nicht.
 */
export class NotificationPresentationContext {
    constructor({
        windowLike = globalThis.window,
        documentLike = globalThis.document,
        channel = undefined,
        now = () => Date.now(),
        clientId = null,
        staleAfterMs = DEFAULT_STALE_AFTER_MS,
        heartbeatMs = DEFAULT_HEARTBEAT_MS,
    } = {}) {
        this.window = windowLike;
        this.document = documentLike;
        this.channel = channel === undefined ? createPresenceChannel(windowLike) : channel;
        this.now = now;
        this.clientId = clientId || createClientId(windowLike);
        this.staleAfterMs = staleAfterMs;
        this.heartbeatMs = heartbeatMs;
        this.peers = new Map();
        this.heartbeat = null;
        this.started = false;
        this.boundRefresh = () => this.refreshSoon();
        this.boundPageHide = () => this.publish({ visible: false, focused: false, activeChatId: 0 });
        this.boundChannelMessage = (event) => this.receive(event?.data);
    }

    start() {
        if (this.started) {
            return this;
        }

        this.started = true;
        this.channel?.addEventListener?.('message', this.boundChannelMessage);
        this.window?.addEventListener?.('focus', this.boundRefresh);
        this.window?.addEventListener?.('blur', this.boundRefresh);
        this.window?.addEventListener?.('resize', this.boundRefresh);
        this.window?.addEventListener?.('pagehide', this.boundPageHide);
        this.document?.addEventListener?.('visibilitychange', this.boundRefresh);
        this.document?.addEventListener?.('livewire:navigated', this.boundRefresh);
        this.window?.addEventListener?.('chat:pane-open', this.boundRefresh);
        this.window?.addEventListener?.('chat:pane-list', this.boundRefresh);

        this.publish();
        this.channel?.postMessage?.({ type: 'railtime:presence-request', from: this.clientId });

        if (typeof this.window?.setInterval === 'function') {
            this.heartbeat = this.window.setInterval(() => this.publish(), this.heartbeatMs);
        }

        return this;
    }

    stop() {
        if (!this.started) {
            return;
        }

        this.publish({ visible: false, focused: false, activeChatId: 0 });
        this.started = false;
        this.channel?.removeEventListener?.('message', this.boundChannelMessage);
        this.window?.removeEventListener?.('focus', this.boundRefresh);
        this.window?.removeEventListener?.('blur', this.boundRefresh);
        this.window?.removeEventListener?.('resize', this.boundRefresh);
        this.window?.removeEventListener?.('pagehide', this.boundPageHide);
        this.document?.removeEventListener?.('visibilitychange', this.boundRefresh);
        this.document?.removeEventListener?.('livewire:navigated', this.boundRefresh);
        this.window?.removeEventListener?.('chat:pane-open', this.boundRefresh);
        this.window?.removeEventListener?.('chat:pane-list', this.boundRefresh);

        if (this.heartbeat !== null) {
            this.window?.clearInterval?.(this.heartbeat);
            this.heartbeat = null;
        }

        this.channel?.close?.();
    }

    refreshSoon() {
        const refresh = () => this.publish();

        if (typeof this.window?.requestAnimationFrame === 'function') {
            this.window.requestAnimationFrame(refresh);

            return;
        }

        this.window?.setTimeout?.(refresh, 0);
    }

    localSnapshot(overrides = {}) {
        const visible = this.document?.visibilityState === 'visible';

        return {
            type: 'railtime:presence',
            clientId: this.clientId,
            visible,
            focused: visible && Boolean(this.document?.hasFocus?.()),
            activeChatId: visible
                ? activeVisibleChatId({ documentLike: this.document, windowLike: this.window })
                : 0,
            updatedAt: this.now(),
            ...overrides,
        };
    }

    publish(overrides = {}) {
        const snapshot = this.localSnapshot(overrides);
        this.peers.set(this.clientId, snapshot);
        this.channel?.postMessage?.(snapshot);

        return snapshot;
    }

    receive(message) {
        if (!message || typeof message !== 'object') {
            return;
        }

        if (message.type === 'railtime:presence-request') {
            this.publish();

            return;
        }

        if (
            message.type !== 'railtime:presence'
            || !message.clientId
            || message.clientId === this.clientId
        ) {
            return;
        }

        this.peers.set(String(message.clientId), {
            ...message,
            clientId: String(message.clientId),
            visible: Boolean(message.visible),
            focused: Boolean(message.focused),
            activeChatId: numericChatId(message.activeChatId),
            updatedAt: Number(message.updatedAt || 0),
        });
        this.prune();
    }

    prune() {
        const oldestValidTimestamp = this.now() - this.staleAfterMs;

        for (const [clientId, snapshot] of this.peers) {
            if (clientId !== this.clientId && snapshot.updatedAt < oldestValidTimestamp) {
                this.peers.delete(clientId);
            }
        }
    }

    visibleSnapshots() {
        this.publish();
        this.prune();

        return [...this.peers.values()].filter((snapshot) => snapshot.visible);
    }

    isLocalChatVisible(chatId) {
        return numericChatId(chatId) > 0
            && activeVisibleChatId({
                documentLike: this.document,
                windowLike: this.window,
            }) === numericChatId(chatId);
    }

    isChatVisible(chatId) {
        const targetChatId = numericChatId(chatId);

        return targetChatId > 0
            && this.visibleSnapshots().some(
                (snapshot) => numericChatId(snapshot.activeChatId) === targetChatId,
            );
    }

    /**
     * Ein sichtbarer, fokussierter Tab ist bevorzugt. Sind mehrere Fenster
     * sichtbar, waehlt die stabile Client-ID genau einen Praesentations-Tab.
     */
    shouldPresent(payload, { forceLocal = false } = {}) {
        const local = this.publish();

        if (!local.visible) {
            return false;
        }

        const chatId = payload?.category === 'chat'
            ? numericChatId(payload.chatId || payload.chat_id)
            : 0;

        if (chatId > 0 && this.isChatVisible(chatId)) {
            return false;
        }

        if (forceLocal) {
            return true;
        }

        const visible = this.visibleSnapshots();
        const focused = visible.filter((snapshot) => snapshot.focused);
        const candidates = focused.length > 0 ? focused : visible;
        const owner = candidates
            .map((snapshot) => String(snapshot.clientId))
            .sort((left, right) => left.localeCompare(right))[0];

        return owner === this.clientId;
    }
}

export function createNotificationPresentationContext(windowLike = globalThis.window) {
    const context = new NotificationPresentationContext({
        windowLike,
        documentLike: windowLike?.document,
    });

    return windowLike?.document?.querySelector?.('meta[name="rt-user-id"]')
        ? context.start()
        : context;
}
