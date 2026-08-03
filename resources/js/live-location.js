import {
    createLiveLocationMap,
    readLiveLocationMapConfig,
    validLiveLocationCoordinates,
} from './live-location-map.js';

export const LIVE_LOCATION_DURATIONS = Object.freeze([15, 30, 45, 60, 90]);

const POSITION_OPTIONS = Object.freeze({
    enableHighAccuracy: true,
    maximumAge: 5_000,
    timeout: 15_000,
});
const MINIMUM_UPDATE_INTERVAL = 5_000;
const DISTANCE_UPDATE_METERS = 20;
const HEARTBEAT_UPDATE_INTERVAL = 30_000;
const ACTIVE_STATUSES = new Set(['active', 'sharing']);

function finiteNumber(value) {
    if (value === null || value === undefined || value === '') {
        return null;
    }

    const number = Number(value);

    return Number.isFinite(number) ? number : null;
}

function positiveInteger(value) {
    const number = Number(value);

    return Number.isSafeInteger(number) && number > 0 ? number : null;
}

function identifier(value) {
    if (value === null || value === undefined || value === '') {
        return null;
    }

    return String(value);
}

function stringValue(value, fallback = '') {
    return typeof value === 'string' && value.trim() ? value.trim() : fallback;
}

function booleanValue(value, fallback = false) {
    if (typeof value === 'boolean') {
        return value;
    }

    if (value === 1 || value === '1' || value === 'true') {
        return true;
    }

    if (value === 0 || value === '0' || value === 'false') {
        return false;
    }

    return fallback;
}

function timestamp(value) {
    const parsed = Date.parse(value || '');

    return Number.isFinite(parsed) ? parsed : null;
}

function messageTemplate(messages, key, fallback, values = {}) {
    let text = stringValue(messages?.[key], fallback);

    Object.entries(values).forEach(([name, value]) => {
        text = text
            .replaceAll(`{${name}}`, String(value))
            .replaceAll(`:${name}`, String(value));
    });

    return text;
}

function liveLocationSource(payload) {
    if (!payload || typeof payload !== 'object') {
        return payload || {};
    }

    const data = payload.data && !Array.isArray(payload.data) ? payload.data : null;

    return payload.live_location
        || payload.liveLocation
        || payload.share
        || data?.live_location
        || data?.liveLocation
        || data?.share
        || data
        || payload;
}

export function normalizeLiveLocationShare(payload = {}) {
    const source = liveLocationSource(payload);
    const position = source?.position && typeof source.position === 'object'
        ? source.position
        : source || {};
    const routes = source?.routes && typeof source.routes === 'object'
        ? source.routes
        : {};
    const latitude = finiteNumber(source?.latitude ?? position.latitude ?? position.lat);
    const longitude = finiteNumber(
        source?.longitude ?? source?.lng ?? position.longitude ?? position.lng,
    );
    const status = stringValue(source?.status, 'active').toLowerCase();

    return {
        id: identifier(source?.id ?? source?.share_id ?? source?.shareId),
        chatId: positiveInteger(source?.chat_id ?? source?.chatId),
        messageId: positiveInteger(source?.message_id ?? source?.messageId),
        status,
        latitude,
        longitude,
        accuracy: finiteNumber(source?.accuracy ?? position.accuracy),
        altitude: finiteNumber(source?.altitude ?? position.altitude),
        altitudeAccuracy: finiteNumber(
            source?.altitude_accuracy ?? source?.altitudeAccuracy
            ?? position.altitude_accuracy ?? position.altitudeAccuracy,
        ),
        heading: finiteNumber(source?.heading ?? position.heading),
        speed: finiteNumber(source?.speed ?? position.speed),
        locatedAt: stringValue(
            source?.last_position_at ?? source?.located_at
            ?? source?.lastPositionAt ?? source?.locatedAt,
        ),
        expiresAt: stringValue(source?.expires_at ?? source?.expiresAt),
        updateUrl: stringValue(
            routes.update ?? source?.update_url ?? source?.updateUrl,
        ),
        stopUrl: stringValue(
            routes.stop ?? source?.stop_url ?? source?.stopUrl,
        ),
        canStop: booleanValue(source?.can_stop ?? source?.canStop, false),
        senderName: stringValue(source?.sender_name ?? source?.senderName),
    };
}

export function normalizeLiveLocationResponse(payload = {}) {
    return normalizeLiveLocationShare(payload);
}

export function normalizeLiveLocationList(payload = {}) {
    const data = payload?.data;
    const candidates = Array.isArray(payload)
        ? payload
        : payload?.live_locations
            ?? payload?.liveLocations
            ?? payload?.active_shares
            ?? payload?.shares
            ?? (Array.isArray(data) ? data : null)
            ?? data?.live_locations
            ?? data?.liveLocations
            ?? data?.active_shares
            ?? data?.shares
            ?? [];

    return (Array.isArray(candidates) ? candidates : [])
        .map((share) => normalizeLiveLocationShare(share))
        .filter((share) => share.id);
}

export function isLiveLocationActive(share, now = Date.now()) {
    if (!share?.id || !ACTIVE_STATUSES.has(stringValue(share.status, 'active').toLowerCase())) {
        return false;
    }

    const expiresAt = timestamp(share.expiresAt);

    return expiresAt === null || expiresAt > now;
}

export function normalizeGeolocationPosition(position) {
    const coordinates = position?.coords || position || {};
    const latitude = finiteNumber(coordinates.latitude);
    const longitude = finiteNumber(coordinates.longitude);

    if (!validLiveLocationCoordinates(latitude, longitude)) {
        throw new RangeError('Der aktuelle Standort konnte nicht bestimmt werden.');
    }

    return {
        latitude,
        longitude,
        accuracy: Math.max(0, finiteNumber(coordinates.accuracy) ?? 0),
        altitude: finiteNumber(coordinates.altitude),
        altitude_accuracy: finiteNumber(
            coordinates.altitude_accuracy ?? coordinates.altitudeAccuracy,
        ),
        heading: finiteNumber(coordinates.heading),
        speed: finiteNumber(coordinates.speed),
    };
}

export function liveLocationPositionPayload(position) {
    const normalized = normalizeGeolocationPosition(position);

    return Object.fromEntries(
        Object.entries(normalized).filter(([, value]) => value !== null),
    );
}

export function liveLocationDistanceInMeters(first, second) {
    if (!validLiveLocationCoordinates(first?.latitude, first?.longitude)
        || !validLiveLocationCoordinates(second?.latitude, second?.longitude)) {
        return Number.POSITIVE_INFINITY;
    }

    const radians = (degrees) => degrees * (Math.PI / 180);
    const earthRadius = 6_371_000;
    const firstLatitude = radians(Number(first.latitude));
    const secondLatitude = radians(Number(second.latitude));
    const deltaLatitude = secondLatitude - firstLatitude;
    const deltaLongitude = radians(Number(second.longitude) - Number(first.longitude));
    const haversine = Math.sin(deltaLatitude / 2) ** 2
        + Math.cos(firstLatitude)
        * Math.cos(secondLatitude)
        * Math.sin(deltaLongitude / 2) ** 2;

    return earthRadius * 2 * Math.atan2(Math.sqrt(haversine), Math.sqrt(1 - haversine));
}

export function shouldPublishLiveLocation(previous, next, now = Date.now(), options = {}) {
    if (!previous || !validLiveLocationCoordinates(previous.latitude, previous.longitude)) {
        return true;
    }

    const minimumInterval = options.minimumInterval ?? MINIMUM_UPDATE_INTERVAL;
    const heartbeatInterval = options.heartbeatInterval ?? HEARTBEAT_UPDATE_INTERVAL;
    const distanceMeters = options.distanceMeters ?? DISTANCE_UPDATE_METERS;
    const previousAt = finiteNumber(previous.publishedAt) ?? 0;
    const elapsed = Math.max(0, now - previousAt);

    if (elapsed < minimumInterval) {
        return false;
    }

    return elapsed >= heartbeatInterval
        || liveLocationDistanceInMeters(previous, next) >= distanceMeters;
}

function csrfToken(documentLike) {
    return documentLike?.querySelector?.('meta[name="csrf-token"]')?.content || '';
}

export async function liveLocationRequest(url, options = {}, dependencies = {}) {
    if (!stringValue(url)) {
        throw new TypeError('Eine Server-URL fuer den Live-Standort fehlt.');
    }

    const documentLike = dependencies.documentLike ?? globalThis.document;
    const fetchLike = dependencies.fetchLike
        ?? globalThis.window?.fetch?.bind(globalThis.window)
        ?? globalThis.fetch;

    if (typeof fetchLike !== 'function') {
        throw new Error('Die Anfrage kann in diesem Browser nicht ausgefuehrt werden.');
    }

    const response = await fetchLike(url, {
        credentials: 'same-origin',
        ...options,
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(options.body ? { 'Content-Type': 'application/json' } : {}),
            ...(csrfToken(documentLike) ? { 'X-CSRF-TOKEN': csrfToken(documentLike) } : {}),
            ...(options.headers || {}),
        },
    });
    const text = response.status === 204 ? '' : await response.text();
    let payload = {};

    if (text) {
        try {
            payload = JSON.parse(text);
        } catch (_) {
            payload = {};
        }
    }

    if (!response.ok) {
        const error = new Error(
            stringValue(payload?.message, `Die Standortanfrage ist fehlgeschlagen (${response.status}).`),
        );
        error.status = response.status;
        error.payload = payload;
        throw error;
    }

    return payload;
}

function dispatchWindowEvent(windowLike, name, detail) {
    if (!windowLike?.dispatchEvent) {
        return;
    }

    const EventConstructor = windowLike.CustomEvent ?? globalThis.CustomEvent;

    if (typeof EventConstructor === 'function') {
        windowLike.dispatchEvent(new EventConstructor(name, { detail }));
    }
}

function publicShare(share) {
    if (!share) {
        return null;
    }

    const { _lastPublished, ...visible } = share;

    return { ...visible };
}

function compactSignal(share) {
    return {
        shareId: identifier(share?.id),
        chatId: positiveInteger(share?.chatId),
        messageId: positiveInteger(share?.messageId),
        status: stringValue(share?.status),
    };
}

export class LiveLocationCoordinator {
    constructor(options = {}) {
        this.window = options.windowLike ?? globalThis.window;
        this.document = options.documentLike ?? globalThis.document;
        this.navigator = options.navigatorLike ?? globalThis.navigator;
        this.now = options.now ?? (() => Date.now());
        this.setTimeout = options.setTimeoutLike ?? globalThis.setTimeout?.bind(globalThis);
        this.clearTimeout = options.clearTimeoutLike ?? globalThis.clearTimeout?.bind(globalThis);
        this.activeUrl = stringValue(
            options.activeUrl
            ?? this.document?.querySelector?.('meta[name="rt-live-location-active-url"]')?.content,
        );
        this.request = options.request ?? ((url, requestOptions) => liveLocationRequest(
            url,
            requestOptions,
            {
                documentLike: this.document,
                fetchLike: this.window?.fetch?.bind?.(this.window),
            },
        ));
        this.positionOptions = { ...POSITION_OPTIONS, ...(options.positionOptions || {}) };
        this.minimumUpdateInterval = options.minimumUpdateInterval ?? MINIMUM_UPDATE_INTERVAL;
        this.distanceUpdateMeters = options.distanceUpdateMeters ?? DISTANCE_UPDATE_METERS;
        this.heartbeatUpdateInterval = options.heartbeatUpdateInterval
            ?? HEARTBEAT_UPDATE_INTERVAL;

        this.secureContext = this.window?.isSecureContext !== false;
        this.supported = this.secureContext && Boolean(this.navigator?.geolocation);
        this.permissionState = 'unknown';
        this.needsResume = false;
        this.resumeError = '';
        this.resumeErrorCode = '';
        this.busy = null;
        this.error = '';
        this.errorCode = '';
        this.online = this.navigator?.onLine !== false;
        this.activeShares = [];
        this.watching = false;

        this._watchId = null;
        this._expiryTimer = null;
        this._permissionStatus = null;
        this._permissionListener = null;
        this._listeners = new Set();
        this._lifecycleListeners = [];
        this._resumePromptDismissed = false;
        this._resumePromise = null;
        this._reconcileGeneration = 0;
        this._initialized = false;
        this._initializePromise = null;
        this._updateInFlight = false;
        this._pendingPosition = null;
        this._destroyed = false;
    }

    snapshot() {
        const canRetryDeniedPermission = !this._permissionStatus;

        return {
            supported: this.supported,
            secureContext: this.secureContext,
            permissionState: this.permissionState,
            needsResume: this.needsResume,
            resumePromptVisible: this.needsResume
                && !this._resumePromptDismissed
                && this.activeShares.length > 0,
            canResume: this.supported
                && (this.permissionState !== 'denied' || canRetryDeniedPermission),
            resumeError: this.resumeError,
            resumeErrorCode: this.resumeErrorCode,
            busy: this.busy,
            error: this.error,
            errorCode: this.errorCode,
            online: this.online,
            activeShares: this.activeShares.map(publicShare),
            activeCount: this.activeShares.length,
            watching: this.watching,
        };
    }

    subscribe(listener) {
        if (typeof listener !== 'function') {
            return () => {};
        }

        this._listeners.add(listener);
        listener(this.snapshot());

        return () => this._listeners.delete(listener);
    }

    _emit() {
        const snapshot = this.snapshot();

        this._listeners.forEach((listener) => listener(snapshot));
    }

    _setError(error, code = 'request_failed') {
        this.error = stringValue(error?.message, 'Der Live-Standort konnte nicht aktualisiert werden.');
        this.errorCode = code;
        this._emit();
    }

    _setResumeNeeded(value, { reveal = false } = {}) {
        const next = Boolean(value) && this.activeShares.length > 0;
        this.needsResume = next;

        if (!next || reveal) {
            this._resumePromptDismissed = false;
        }
    }

    _setResumeError(error, code = 'resume_failed') {
        this.resumeError = stringValue(
            error?.message,
            'Der Live-Standort konnte nicht fortgesetzt werden.',
        );
        this.resumeErrorCode = code;
        this._setError(error, code);
    }

    _clearResumeError() {
        this.resumeError = '';
        this.resumeErrorCode = '';
    }

    dismissResumePrompt() {
        if (!this.needsResume) {
            return false;
        }

        this._resumePromptDismissed = true;
        this._clearResumeError();
        this._emit();

        return true;
    }

    async initialize() {
        if (this._initializePromise) {
            return this._initializePromise;
        }

        this._initializePromise = (async () => {
            this._bindLifecycle();
            await this._refreshPermissionState();

            if (this.activeUrl) {
                await this.reconcile({ quiet: true });
            }

            this._initialized = true;

            return this.snapshot();
        })();

        return this._initializePromise;
    }

    _listen(target, type, listener, options) {
        if (!target?.addEventListener) {
            return;
        }

        target.addEventListener(type, listener, options);
        this._lifecycleListeners.push(() => target.removeEventListener(type, listener, options));
    }

    _bindLifecycle() {
        if (this._lifecycleListeners.length > 0) {
            return;
        }

        this._listen(this.document, 'visibilitychange', () => {
            if (this.document?.visibilityState === 'visible') {
                this._resumeAfterVisibility();
            }
        });
        this._listen(this.window, 'pageshow', () => this._resumeAfterVisibility());
        this._listen(this.window, 'online', () => {
            this.online = true;
            this._emit();
            this.reconcile({ quiet: true }).catch(() => null);
            this._flushPendingPosition();
        });
        this._listen(this.window, 'offline', () => {
            this.online = false;
            this._emit();
        });
        this._listen(this.document, 'livewire:navigated', () => {
            this.reconcile({ quiet: true }).catch(() => null);
        });
        this._listen(this.window, 'railtime:logout', () => this.handleLogout());
        this._listen(this.document, 'submit', (event) => {
            const form = event?.target;
            const action = form?.getAttribute?.('action') || form?.action || '';

            if (/(?:^|\/)logout(?:\?|$)/i.test(String(action))) {
                this.handleLogout();
            }
        }, true);
    }

    async _refreshPermissionState() {
        if (!this.supported) {
            this.permissionState = this.secureContext ? 'unsupported' : 'insecure';
            this._emit();
            return this.permissionState;
        }

        if (!this.navigator?.permissions?.query) {
            this.permissionState = 'unknown';
            this._emit();
            return this.permissionState;
        }

        try {
            const permission = await this.navigator.permissions.query({ name: 'geolocation' });
            this._permissionStatus = permission;
            this.permissionState = permission.state || 'unknown';
            this._permissionListener = () => {
                const previousState = this.permissionState;
                this.permissionState = permission.state || 'unknown';

                if (this.activeShares.length === 0) {
                    this._setResumeNeeded(false);
                } else if (this.permissionState === 'granted') {
                    this._setResumeNeeded(false);
                    this._clearResumeError();
                    this._ensureWatch();
                } else if (this.supported) {
                    this._setResumeNeeded(true, {
                        reveal: previousState !== this.permissionState,
                    });
                    this._clearWatch();
                } else {
                    this._setResumeNeeded(false);
                }

                this._emit();
            };

            if (permission.addEventListener) {
                permission.addEventListener('change', this._permissionListener);
            } else {
                permission.onchange = this._permissionListener;
            }
        } catch (_) {
            this.permissionState = 'unknown';
        }

        this._emit();

        return this.permissionState;
    }

    async reconcile({ quiet = false } = {}) {
        if (!this.activeUrl || this._destroyed) {
            return this.snapshot();
        }

        const generation = ++this._reconcileGeneration;

        if (!quiet) {
            this.busy = 'reconcile';
            this._emit();
        }

        try {
            const payload = await this.request(this.activeUrl, { method: 'GET' });
            if (this._destroyed || generation !== this._reconcileGeneration) {
                return this.snapshot();
            }

            const hadActiveShares = this.activeShares.length > 0;
            const shares = normalizeLiveLocationList(payload)
                .filter((share) => isLiveLocationActive(share, this.now()));
            this.activeShares = shares.map((share) => ({
                ...share,
                _lastPublished: validLiveLocationCoordinates(share.latitude, share.longitude)
                    ? {
                        latitude: share.latitude,
                        longitude: share.longitude,
                        publishedAt: timestamp(share.locatedAt) ?? 0,
                    }
                    : null,
            }));
            this.error = '';
            this.errorCode = '';
            this._scheduleExpiry();

            if (this.activeShares.length === 0) {
                this._setResumeNeeded(false);
                this._clearResumeError();
                this._clearWatch();
            } else if (this.permissionState === 'granted') {
                this._setResumeNeeded(false);
                this._clearResumeError();
                this._ensureWatch();
            } else if (this.supported) {
                this._setResumeNeeded(true, { reveal: !hadActiveShares });
                this._clearWatch();
            } else {
                // Unsupported or insecure contexts cannot satisfy a resume
                // action and therefore must never create a permanent CTA.
                this._setResumeNeeded(false);
                this._clearResumeError();
                this._clearWatch();
            }
        } catch (error) {
            if (!quiet) {
                this._setError(error, 'reconcile_failed');
            }
        } finally {
            if (!quiet) {
                this.busy = null;
            }
            this._emit();
        }

        return this.snapshot();
    }

    _positionOnce() {
        return new Promise((resolve, reject) => {
            if (!this.supported) {
                reject(new Error(
                    this.secureContext
                        ? 'Dieser Browser unterstuetzt keine Standortfreigabe.'
                        : 'Die Standortfreigabe benoetigt eine sichere HTTPS-Verbindung.',
                ));
                return;
            }

            let settled = false;
            let timeoutId = null;
            const finish = (callback, value) => {
                if (settled) {
                    return;
                }

                settled = true;

                if (timeoutId !== null) {
                    this.clearTimeout?.(timeoutId);
                }

                callback(value);
            };
            const configuredTimeout = Math.max(
                1_000,
                finiteNumber(this.positionOptions.timeout) ?? POSITION_OPTIONS.timeout,
            );

            if (this.setTimeout) {
                timeoutId = this.setTimeout(() => {
                    const error = new Error('Der aktuelle Standort antwortet nicht. Bitte versuchen Sie es erneut.');
                    error.code = 3;
                    finish(reject, error);
                }, configuredTimeout + 1_000);
            }

            try {
                this.navigator.geolocation.getCurrentPosition(
                    (position) => finish(resolve, position),
                    (error) => finish(reject, error),
                    this.positionOptions,
                );
            } catch (error) {
                finish(reject, error);
            }
        });
    }

    async start({ chatId, durationMinutes, startUrl, replyToMessageId = null } = {}) {
        const normalizedChatId = positiveInteger(chatId);
        const duration = Number(durationMinutes);
        const endpoint = stringValue(startUrl);

        if (!normalizedChatId || !LIVE_LOCATION_DURATIONS.includes(duration)) {
            throw new RangeError('Bitte waehle eine gueltige Freigabedauer.');
        }

        if (!endpoint) {
            throw new TypeError('Die Server-URL fuer die Standortfreigabe fehlt.');
        }

        this.busy = 'start';
        this.error = '';
        this.errorCode = '';
        this._clearResumeError();
        this._emit();

        try {
            const position = await this._positionOnce();
            const payload = {
                chat_id: normalizedChatId,
                duration_minutes: duration,
                ...liveLocationPositionPayload(position),
            };
            const replyId = positiveInteger(replyToMessageId);

            if (replyId) {
                payload.reply_to_message_id = replyId;
            }

            const response = await this.request(endpoint, {
                method: 'POST',
                body: JSON.stringify(payload),
            });
            const share = normalizeLiveLocationResponse(response);

            if (!share.id || !share.updateUrl || !share.stopUrl) {
                throw new Error('Die Serverantwort fuer den Live-Standort ist unvollstaendig.');
            }

            share.chatId ||= normalizedChatId;
            share._lastPublished = {
                latitude: finiteNumber(share.latitude) ?? payload.latitude,
                longitude: finiteNumber(share.longitude) ?? payload.longitude,
                publishedAt: this.now(),
            };
            this.activeShares = this.activeShares.filter((activeShare) => (
                activeShare.chatId !== share.chatId || activeShare.id === share.id
            ));
            this._upsertShare(share);
            this.permissionState = 'granted';
            this._setResumeNeeded(false);
            this._clearResumeError();
            this._ensureWatch();
            this._scheduleExpiry();
            dispatchWindowEvent(
                this.window,
                'railtime:live-location-started',
                compactSignal(share),
            );

            return publicShare(share);
        } catch (error) {
            const code = error?.code === 1 ? 'permission_denied' : 'start_failed';

            if (code === 'permission_denied') {
                this.permissionState = 'denied';
                this._clearWatch();
            }

            this._setError(error, code);
            throw error;
        } finally {
            this.busy = null;
            this._emit();
        }
    }

    async resume() {
        if (this.activeShares.length === 0) {
            this._setResumeNeeded(false);
            this._clearResumeError();
            this._emit();
            return false;
        }

        this.busy = 'resume';
        this.error = '';
        this.errorCode = '';
        this._clearResumeError();
        this._resumePromptDismissed = false;
        this._emit();

        try {
            const position = await this._positionOnce();
            this.permissionState = 'granted';
            this._setResumeNeeded(false);
            this._clearResumeError();
            await this._handlePosition(position, { force: true });
            this._ensureWatch();
            return true;
        } catch (error) {
            const code = error?.code === 1 ? 'permission_denied' : 'resume_failed';

            if (code === 'permission_denied') {
                this.permissionState = 'denied';
                this._clearWatch();
            }

            this._setResumeNeeded(this.supported, { reveal: true });
            this._setResumeError(error, code);
            throw error;
        } finally {
            this.busy = null;
            this._emit();
        }
    }

    _ensureWatch() {
        if (!this.supported
            || this._watchId !== null
            || this.activeShares.length === 0
            || this.permissionState === 'denied') {
            return;
        }

        this._watchId = this.navigator.geolocation.watchPosition(
            (position) => this._handlePosition(position).catch(() => null),
            (error) => {
                if (error?.code === 1) {
                    this.permissionState = 'denied';
                    this._setResumeNeeded(this.supported, { reveal: true });
                    this._clearWatch();
                    this._setResumeError(error, 'permission_denied');

                    return;
                }

                this._setError(error, error?.code === 1 ? 'permission_denied' : 'watch_failed');
            },
            this.positionOptions,
        );
        this.watching = true;
        this._emit();
    }

    _clearWatch() {
        if (this._watchId !== null) {
            this.navigator?.geolocation?.clearWatch?.(this._watchId);
            this._watchId = null;
        }

        this.watching = false;
    }

    async _handlePosition(position, { force = false } = {}) {
        const next = liveLocationPositionPayload(position);

        if (!this.online) {
            this._pendingPosition = next;
            return;
        }

        if (this._updateInFlight) {
            this._pendingPosition = next;
            return;
        }

        const now = this.now();
        const candidates = this.activeShares.filter((share) => share.updateUrl
            && isLiveLocationActive(share, now)
            && (force || shouldPublishLiveLocation(share._lastPublished, next, now, {
                minimumInterval: this.minimumUpdateInterval,
                distanceMeters: this.distanceUpdateMeters,
                heartbeatInterval: this.heartbeatUpdateInterval,
            })));

        if (candidates.length === 0) {
            return;
        }

        this._updateInFlight = true;

        try {
            await Promise.all(candidates.map(async (share) => {
                try {
                    const response = await this.request(share.updateUrl, {
                        method: 'PATCH',
                        body: JSON.stringify(next),
                    });
                    const updated = normalizeLiveLocationResponse(response);
                    const merged = {
                        ...share,
                        ...(updated.id ? updated : {}),
                        latitude: updated.latitude ?? next.latitude,
                        longitude: updated.longitude ?? next.longitude,
                        accuracy: updated.accuracy ?? next.accuracy,
                        updateUrl: updated.updateUrl || share.updateUrl,
                        stopUrl: updated.stopUrl || share.stopUrl,
                        _lastPublished: {
                            latitude: next.latitude,
                            longitude: next.longitude,
                            publishedAt: this.now(),
                        },
                    };

                    this._upsertShare(merged);
                } catch (error) {
                    if ([404, 410].includes(Number(error?.status))) {
                        this._removeShare(share.id);
                        return;
                    }

                    if ([401, 419].includes(Number(error?.status))) {
                        this._clearWatch();
                    }

                    this._setError(error, 'update_failed');
                }
            }));
        } finally {
            this._updateInFlight = false;
            this._scheduleExpiry();
            this._emit();

            const pending = this._pendingPosition;
            this._pendingPosition = null;

            if (pending) {
                this._handlePosition(pending).catch(() => null);
            }
        }
    }

    _flushPendingPosition() {
        if (!this._pendingPosition || !this.online) {
            return;
        }

        const pending = this._pendingPosition;
        this._pendingPosition = null;
        this._handlePosition(pending, { force: true }).catch(() => null);
    }

    _upsertShare(nextShare) {
        const nextId = identifier(nextShare?.id);

        if (!nextId) {
            return;
        }

        this._reconcileGeneration += 1;

        const index = this.activeShares.findIndex((share) => identifier(share.id) === nextId);

        if (index === -1) {
            this.activeShares = [...this.activeShares, nextShare];
        } else {
            this.activeShares = this.activeShares.map((share, shareIndex) => (
                shareIndex === index ? { ...share, ...nextShare } : share
            ));
        }

        this._emit();
    }

    _removeShare(shareId) {
        const normalizedId = identifier(shareId);
        this._reconcileGeneration += 1;
        this.activeShares = this.activeShares.filter(
            (share) => identifier(share.id) !== normalizedId,
        );

        if (this.activeShares.length === 0) {
            this._setResumeNeeded(false);
            this._clearResumeError();
            this._clearWatch();
        }

        this._scheduleExpiry();
        this._emit();
    }

    async stop(shareOrId, stopUrl = '') {
        const requestedId = identifier(
            typeof shareOrId === 'object' ? shareOrId?.id ?? shareOrId?.share_id : shareOrId,
        );
        const share = this.activeShares.find((item) => identifier(item.id) === requestedId)
            ?? (typeof shareOrId === 'object' ? normalizeLiveLocationShare(shareOrId) : null);
        const endpoint = stringValue(stopUrl || share?.stopUrl);

        if (!requestedId || !endpoint) {
            throw new TypeError('Die Freigabe kann ohne Server-URL nicht beendet werden.');
        }

        this.busy = `stop:${requestedId}`;
        this.error = '';
        this.errorCode = '';
        this._emit();

        try {
            const response = await this.request(endpoint, { method: 'DELETE' });
            const stopped = normalizeLiveLocationResponse(response);
            this._removeShare(requestedId);
            dispatchWindowEvent(this.window, 'railtime:live-location-stopped', {
                shareId: requestedId,
                chatId: positiveInteger(stopped.chatId ?? share?.chatId),
                messageId: positiveInteger(stopped.messageId ?? share?.messageId),
                status: stringValue(stopped.status, 'stopped'),
            });

            return stopped.id ? publicShare(stopped) : { ...publicShare(share), status: 'stopped' };
        } catch (error) {
            this._setError(error, 'stop_failed');
            throw error;
        } finally {
            this.busy = null;
            this._emit();
        }
    }

    async stopAll(reason = 'manual') {
        const shares = [...this.activeShares];

        if (reason === 'logout') {
            // The backend terminates owned shares as part of logout. Locally we
            // synchronously remove every coordinate and watcher before the form
            // leaves the page; no browser storage is involved.
            this.handleLogout();
            return [];
        }

        return Promise.allSettled(shares.map((share) => this.stop(share)));
    }

    handleLogout() {
        this._reconcileGeneration += 1;
        this.activeShares = [];
        this._pendingPosition = null;
        this._setResumeNeeded(false);
        this._clearResumeError();
        this._clearWatch();
        this._scheduleExpiry();
        this._emit();
    }

    handleRealtimeSignal(event = {}) {
        const detail = {
            shareId: identifier(event.shareId ?? event.share_id ?? event.id),
            chatId: positiveInteger(event.chatId ?? event.chat_id),
            messageId: positiveInteger(event.messageId ?? event.message_id),
            status: stringValue(event.status).toLowerCase(),
        };

        if (detail.shareId && detail.status && !ACTIVE_STATUSES.has(detail.status)) {
            this._removeShare(detail.shareId);
        }

        return detail;
    }

    async _resumeAfterVisibility() {
        if (this._resumePromise) {
            return this._resumePromise;
        }

        this._resumePromise = (async () => {
            if (this.activeShares.length === 0) {
                this._setResumeNeeded(false);
                this._emit();

                return false;
            }

            if (this.permissionState !== 'granted') {
                this._setResumeNeeded(this.supported);
                this._emit();

                return false;
            }

            this._setResumeNeeded(false);
            // Mobile browsers may retain a stale native watch id while the OS
            // has suspended its callbacks. Recreate exactly one watcher after
            // returning to the foreground.
            this._clearWatch();
            this._ensureWatch();

            try {
                const position = await this._positionOnce();
                await this._handlePosition(position, { force: true });
            } catch (_) {
                // watchPosition remains the normal recovery path. A one-off
                // refresh may legitimately time out immediately after resume.
            }

            return true;
        })().finally(() => {
            this._resumePromise = null;
        });

        return this._resumePromise;
    }

    _scheduleExpiry() {
        if (this._expiryTimer !== null) {
            this.clearTimeout?.(this._expiryTimer);
            this._expiryTimer = null;
        }

        const now = this.now();
        const active = [];
        const expired = [];

        this.activeShares.forEach((share) => {
            if (isLiveLocationActive(share, now)) {
                active.push(share);
            } else {
                expired.push(share);
            }
        });
        this.activeShares = active;

        expired.forEach((share) => {
            dispatchWindowEvent(this.window, 'railtime:live-location-expired', compactSignal({
                ...share,
                status: 'expired',
            }));
        });

        if (this.activeShares.length === 0) {
            this._setResumeNeeded(false);
            this._clearResumeError();
            this._clearWatch();
            return;
        }

        const expirations = this.activeShares
            .map((share) => timestamp(share.expiresAt))
            .filter((value) => value !== null);

        if (expirations.length === 0 || !this.setTimeout) {
            return;
        }

        const delay = Math.min(
            Math.max(0, Math.min(...expirations) - now) + 25,
            2_147_483_647,
        );
        this._expiryTimer = this.setTimeout(() => {
            this._expiryTimer = null;
            this._scheduleExpiry();
            this._emit();
        }, delay);
    }

    destroy() {
        this._destroyed = true;
        this._reconcileGeneration += 1;
        this._clearWatch();

        if (this._expiryTimer !== null) {
            this.clearTimeout?.(this._expiryTimer);
            this._expiryTimer = null;
        }

        this._lifecycleListeners.splice(0).forEach((remove) => remove());

        if (this._permissionStatus && this._permissionListener) {
            if (this._permissionStatus.removeEventListener) {
                this._permissionStatus.removeEventListener('change', this._permissionListener);
            } else if (this._permissionStatus.onchange === this._permissionListener) {
                this._permissionStatus.onchange = null;
            }
        }

        this._listeners.clear();
    }
}

export function createLiveLocationStore(coordinator) {
    const store = {
        supported: false,
        secureContext: false,
        permissionState: 'unknown',
        needsResume: false,
        resumePromptVisible: false,
        canResume: false,
        resumeError: '',
        resumeErrorCode: '',
        busy: null,
        error: '',
        errorCode: '',
        online: true,
        activeShares: [],
        activeCount: 0,
        watching: false,

        start(options) {
            return coordinator.start(options);
        },

        stop(shareOrId, stopUrl = '') {
            return coordinator.stop(shareOrId, stopUrl);
        },

        stopAll(reason = 'manual') {
            return coordinator.stopAll(reason);
        },

        resume() {
            return coordinator.resume();
        },

        dismissResumePrompt() {
            return coordinator.dismissResumePrompt();
        },

        reconcile(options = {}) {
            return coordinator.reconcile(options);
        },

        handleRealtimeSignal(event) {
            return coordinator.handleRealtimeSignal(event);
        },
    };

    coordinator.subscribe((snapshot) => Object.assign(store, snapshot));

    return store;
}

function componentStore(component) {
    return component?.$store?.liveLocation
        ?? globalThis.window?.RailTimeLiveLocationStore
        ?? globalThis.window?.RailTimeLiveLocation;
}

export function railtimeLiveLocationShare(config = {}) {
    return {
        messages: config.messages || {},
        durations: [...LIVE_LOCATION_DURATIONS],
        chatId: positiveInteger(config.chatId ?? config.chat_id),
        startUrl: stringValue(config.startUrl ?? config.start_url),
        replyToMessageId: positiveInteger(
            config.replyToMessageId ?? config.reply_to_message_id,
        ),
        open: false,
        busy: false,
        error: '',

        get canShare() {
            const store = componentStore(this);

            return Boolean(store?.supported) && !this.busy;
        },

        openPicker() {
            this.error = '';
            this.open = true;
        },

        closePicker() {
            if (!this.busy) {
                this.open = false;
            }
        },

        async share(minutes) {
            const duration = Number(minutes);

            if (!LIVE_LOCATION_DURATIONS.includes(duration)) {
                this.error = messageTemplate(
                    this.messages,
                    'invalidDuration',
                    'Bitte waehle eine gueltige Freigabedauer.',
                );
                return null;
            }

            const store = componentStore(this);

            if (!store?.start) {
                this.error = messageTemplate(
                    this.messages,
                    'unavailable',
                    'Die Standortfreigabe ist derzeit nicht verfuegbar.',
                );
                return null;
            }

            this.busy = true;
            this.error = '';

            try {
                const share = await store.start({
                    chatId: this.chatId,
                    durationMinutes: duration,
                    startUrl: this.startUrl,
                    replyToMessageId: this.replyToMessageId,
                });
                this.open = false;

                return share;
            } catch (error) {
                this.error = stringValue(
                    error?.message,
                    messageTemplate(
                        this.messages,
                        'startFailed',
                        'Der Live-Standort konnte nicht gestartet werden.',
                    ),
                );
                return null;
            } finally {
                this.busy = false;
            }
        },
    };
}

function cardDataset(element) {
    const data = element?.dataset || {};
    const source = {};
    const assign = (key, value) => {
        if (value !== undefined && value !== null && value !== '') {
            source[key] = value;
        }
    };

    assign('id', data.liveLocationShareId);
    assign('message_id', data.liveLocationMessageId);
    assign('chat_id', data.liveLocationChatId);
    assign('latitude', data.liveLocationLatitude);
    assign('longitude', data.liveLocationLongitude);
    assign('accuracy', data.liveLocationAccuracy);
    assign('last_position_at', data.liveLocationLocatedAt);
    assign('expires_at', data.liveLocationExpiresAt);
    assign('status', data.liveLocationStatus);
    assign('stop_url', data.liveLocationStopUrl);
    assign('can_stop', data.liveLocationCanStop);
    assign('sender_name', data.liveLocationSenderName);

    return source;
}

function remainingSeconds(expiresAt, now) {
    const expires = timestamp(expiresAt);

    return expires === null ? 0 : Math.max(0, Math.ceil((expires - now) / 1_000));
}

function durationLabel(seconds) {
    const total = Math.max(0, Number(seconds) || 0);
    const hours = Math.floor(total / 3_600);
    const minutes = Math.floor((total % 3_600) / 60);
    const remainder = total % 60;

    if (hours > 0) {
        return `${hours}:${String(minutes).padStart(2, '0')}:${String(remainder).padStart(2, '0')}`;
    }

    return `${minutes}:${String(remainder).padStart(2, '0')}`;
}

function assignShare(component, share) {
    component.shareId = share.id;
    component.messageId = share.messageId;
    component.chatId = share.chatId;
    component.status = share.status;
    component.latitude = share.latitude;
    component.longitude = share.longitude;
    component.accuracy = share.accuracy;
    component.locatedAt = share.locatedAt;
    component.expiresAt = share.expiresAt;
    component.updateUrl = share.updateUrl;
    component.stopUrl = share.stopUrl;
    component.canStop = share.canStop;
    component.senderName = share.senderName;
}

function componentShare(component) {
    return {
        id: component.shareId,
        message_id: component.messageId,
        chat_id: component.chatId,
        status: component.status,
        latitude: component.latitude,
        longitude: component.longitude,
        accuracy: component.accuracy,
        last_position_at: component.locatedAt,
        expires_at: component.expiresAt,
        routes: {
            update: component.updateUrl,
            stop: component.stopUrl,
        },
        can_stop: component.canStop,
        sender_name: component.senderName,
    };
}

export function railtimeLiveLocationCard(config = {}) {
    const initial = normalizeLiveLocationShare(
        config.live_location ?? config.liveLocation ?? config.share ?? config,
    );

    return {
        messages: config.messages || {},
        shareId: initial.id,
        messageId: initial.messageId,
        chatId: initial.chatId,
        status: initial.status,
        latitude: initial.latitude,
        longitude: initial.longitude,
        accuracy: initial.accuracy,
        locatedAt: initial.locatedAt,
        expiresAt: initial.expiresAt,
        updateUrl: initial.updateUrl,
        stopUrl: initial.stopUrl,
        canStop: initial.canStop,
        senderName: initial.senderName,
        nowMs: Date.now(),
        busy: false,
        stopping: false,
        error: '',
        mapReady: false,
        mapError: '',
        _map: null,
        _mapLoading: null,
        _mapFactory: config.mapFactory || createLiveLocationMap,
        _mapConfig: config.mapConfig || null,
        _observer: null,
        _mutationObserver: null,
        _statusListener: null,
        _clockTimer: null,
        _destroyed: false,

        get isActive() {
            return isLiveLocationActive(normalizeLiveLocationShare(componentShare(this)), this.nowMs);
        },

        get remainingSeconds() {
            return remainingSeconds(this.expiresAt, this.nowMs);
        },

        get remainingLabel() {
            return durationLabel(this.remainingSeconds);
        },

        get lastUpdatedLabel() {
            const located = timestamp(this.locatedAt);

            if (located === null) {
                return messageTemplate(this.messages, 'locationPending', 'Standort wird ermittelt');
            }

            const seconds = Math.max(0, Math.floor((this.nowMs - located) / 1_000));

            if (seconds < 60) {
                return messageTemplate(this.messages, 'updatedNow', 'Gerade aktualisiert');
            }

            const minutes = Math.floor(seconds / 60);

            return messageTemplate(
                this.messages,
                this.messages?.updatedMinutesAgo ? 'updatedMinutesAgo' : 'updatedMinutes',
                'Vor {count} Min. aktualisiert',
                { count: minutes, minutes },
            );
        },

        init() {
            this._destroyed = false;
            this.syncFromElement();
            const windowLike = config.windowLike ?? globalThis.window;
            const mapElement = this.$refs?.map;

            if (mapElement && typeof windowLike?.IntersectionObserver === 'function') {
                this._observer = new windowLike.IntersectionObserver((entries) => {
                    if (entries.some((entry) => entry.isIntersecting)) {
                        this.ensureMap();
                        this._observer?.disconnect();
                        this._observer = null;
                    }
                }, { rootMargin: '160px' });
                this._observer.observe(mapElement);
            } else {
                this.ensureMap();
            }

            if (this.$el && typeof windowLike?.MutationObserver === 'function') {
                this._mutationObserver = new windowLike.MutationObserver(() => {
                    this.syncFromElement();
                });
                this._mutationObserver.observe(this.$el, {
                    attributes: true,
                    attributeFilter: [
                        'data-live-location-latitude',
                        'data-live-location-longitude',
                        'data-live-location-accuracy',
                        'data-live-location-located-at',
                        'data-live-location-expires-at',
                        'data-live-location-status',
                        'data-live-location-stop-url',
                        'data-live-location-can-stop',
                    ],
                });
            }

            this._statusListener = (event) => {
                const detail = event?.detail || {};

                if (identifier(detail.shareId ?? detail.share_id) === identifier(this.shareId)
                    && detail.status) {
                    this.status = String(detail.status);
                }
            };
            windowLike?.addEventListener?.('railtime:live-location-changed', this._statusListener);
            windowLike?.addEventListener?.('railtime:live-location-stopped', this._statusListener);
            windowLike?.addEventListener?.('railtime:live-location-expired', this._statusListener);

            this._clockTimer = windowLike?.setInterval?.(() => {
                this.nowMs = Date.now();

                if (timestamp(this.expiresAt) !== null
                    && ACTIVE_STATUSES.has(this.status)
                    && this.remainingSeconds === 0) {
                    this.status = 'expired';
                }
            }, 1_000) ?? null;
        },

        syncFromElement() {
            const source = cardDataset(this.$el);

            if (Object.keys(source).length > 0) {
                const updated = normalizeLiveLocationShare({
                    ...componentShare(this),
                    ...source,
                });
                assignShare(this, updated);
            }

            this._map?.update({
                latitude: this.latitude,
                longitude: this.longitude,
                accuracy: this.accuracy,
            }, { recenter: true });
            dispatchWindowEvent(config.windowLike ?? globalThis.window, 'railtime:live-location-card-updated', {
                share: normalizeLiveLocationShare(componentShare(this)),
            });
        },

        async ensureMap() {
            if (this._map) {
                return this._map;
            }

            if (this._mapLoading) {
                return this._mapLoading;
            }

            if (this._destroyed
                || !validLiveLocationCoordinates(this.latitude, this.longitude)
                || !this.$refs?.map) {
                return null;
            }

            const windowLike = config.windowLike ?? globalThis.window;
            this._mapLoading = this._mapFactory({
                element: this.$refs.map,
                latitude: this.latitude,
                longitude: this.longitude,
                accuracy: this.accuracy,
                interactive: false,
                mapConfig: this._mapConfig || readLiveLocationMapConfig(),
                windowLike,
            });
            const loading = this._mapLoading;

            try {
                const map = await loading;

                if (this._destroyed) {
                    map.destroy();
                    return null;
                }

                this._map = map;
                map.update({
                    latitude: this.latitude,
                    longitude: this.longitude,
                    accuracy: this.accuracy,
                }, { recenter: true });
                map.invalidate({ recenter: true });
                this.mapReady = true;
                this.mapError = '';
                return map;
            } catch (error) {
                this.mapError = stringValue(
                    this.messages?.mapFailed,
                    'Die Karte konnte nicht geladen werden.',
                );
                return null;
            } finally {
                if (this._mapLoading === loading) {
                    this._mapLoading = null;
                }
            }
        },

        openPreview() {
            dispatchWindowEvent(config.windowLike ?? globalThis.window, 'railtime:live-location-preview', {
                share: normalizeLiveLocationShare(componentShare(this)),
            });
        },

        async stopSharing() {
            const store = componentStore(this);

            if (!store?.stop || !this.shareId || !this.stopUrl) {
                return null;
            }

            this.busy = true;
            this.stopping = true;
            this.error = '';

            try {
                const stopped = await store.stop(this.shareId, this.stopUrl);
                this.status = stopped?.status || 'stopped';
                return stopped;
            } catch (error) {
                this.error = stringValue(
                    error?.message,
                    messageTemplate(
                        this.messages,
                        'stopFailed',
                        'Die Standortfreigabe konnte nicht beendet werden.',
                    ),
                );
                return null;
            } finally {
                this.busy = false;
                this.stopping = false;
            }
        },

        destroy() {
            this._destroyed = true;
            this._observer?.disconnect();
            this._mutationObserver?.disconnect();
            const windowLike = config.windowLike ?? globalThis.window;
            windowLike?.removeEventListener?.('railtime:live-location-changed', this._statusListener);
            windowLike?.removeEventListener?.('railtime:live-location-stopped', this._statusListener);
            windowLike?.removeEventListener?.('railtime:live-location-expired', this._statusListener);
            windowLike?.clearInterval?.(this._clockTimer);
            this._map?.destroy();
            this._map = null;
            this.mapReady = false;
        },
    };
}

export function railtimeLiveLocationPreview(config = {}) {
    return {
        messages: config.messages || {},
        open: false,
        share: null,
        busy: false,
        stopping: false,
        error: '',
        shareId: null,
        messageId: null,
        chatId: null,
        status: '',
        latitude: null,
        longitude: null,
        accuracy: null,
        locatedAt: '',
        expiresAt: '',
        updateUrl: '',
        stopUrl: '',
        canStop: false,
        senderName: '',
        nowMs: Date.now(),
        mapReady: false,
        mapError: '',
        _map: null,
        _mapLoading: null,
        _mappedShareId: null,
        _mapFactory: config.mapFactory || createLiveLocationMap,
        _mapConfig: config.mapConfig || null,
        _previewListener: null,
        _cardListener: null,
        _signalListener: null,
        _clockTimer: null,
        _destroyed: false,

        get isActive() {
            return isLiveLocationActive(normalizeLiveLocationShare(componentShare(this)), this.nowMs);
        },

        get remainingSeconds() {
            return remainingSeconds(this.expiresAt, this.nowMs);
        },

        get remainingLabel() {
            return durationLabel(this.remainingSeconds);
        },

        get lastUpdatedLabel() {
            const located = timestamp(this.locatedAt);

            if (located === null) {
                return messageTemplate(this.messages, 'locationPending', 'Standort wird ermittelt');
            }

            const seconds = Math.max(0, Math.floor((this.nowMs - located) / 1_000));

            if (seconds < 60) {
                return messageTemplate(this.messages, 'updatedNow', 'Gerade aktualisiert');
            }

            return messageTemplate(
                this.messages,
                this.messages?.updatedMinutesAgo ? 'updatedMinutesAgo' : 'updatedMinutes',
                'Vor {count} Min. aktualisiert',
                {
                    count: Math.floor(seconds / 60),
                    minutes: Math.floor(seconds / 60),
                },
            );
        },

        init() {
            const windowLike = config.windowLike ?? globalThis.window;
            this._destroyed = false;
            this._previewListener = (event) => this.openWith(event);
            this._cardListener = (event) => {
                const share = normalizeLiveLocationShare(event?.detail?.share ?? event?.detail);

                if (this.open && share.id && identifier(share.id) === identifier(this.shareId)) {
                    this.applyShare(share);
                }
            };
            this._signalListener = (event) => {
                const detail = event?.detail || {};

                if (this.open
                    && identifier(detail.shareId ?? detail.share_id) === identifier(this.shareId)
                    && detail.status) {
                    this.status = String(detail.status);
                }
            };
            windowLike?.addEventListener?.('railtime:live-location-preview', this._previewListener);
            windowLike?.addEventListener?.('railtime:live-location-card-updated', this._cardListener);
            windowLike?.addEventListener?.('railtime:live-location-changed', this._signalListener);
            windowLike?.addEventListener?.('railtime:live-location-stopped', this._signalListener);
            windowLike?.addEventListener?.('railtime:live-location-expired', this._signalListener);
            this._clockTimer = windowLike?.setInterval?.(() => {
                this.nowMs = Date.now();

                if (timestamp(this.expiresAt) !== null
                    && ACTIVE_STATUSES.has(this.status)
                    && this.remainingSeconds === 0) {
                    this.status = 'expired';
                }
            }, 1_000) ?? null;
        },

        applyShare(share) {
            const normalized = normalizeLiveLocationShare(share);
            this.share = normalized;
            assignShare(this, normalized);
            this._map?.update({
                latitude: this.latitude,
                longitude: this.longitude,
                accuracy: this.accuracy,
            }, { recenter: true });

            if (this._map) {
                this._mappedShareId = this.shareId;
            }
        },

        openWith(payload) {
            const share = normalizeLiveLocationShare(
                payload?.detail?.share ?? payload?.detail ?? payload?.share ?? payload,
            );

            if (!share.id || !validLiveLocationCoordinates(share.latitude, share.longitude)) {
                this.error = messageTemplate(
                    this.messages,
                    'locationUnavailable',
                    'Fuer diese Freigabe ist noch kein Standort verfuegbar.',
                );
                return false;
            }

            this.applyShare(share);
            this.error = '';
            this.open = true;
            this.nowMs = Date.now();
            const windowLike = config.windowLike ?? globalThis.window;
            const renderMap = () => this.ensureMap()
                .then((map) => map?.invalidate({ recenter: true }));

            if (typeof this.$nextTick === 'function') {
                this.$nextTick(renderMap);
            } else if (typeof windowLike?.requestAnimationFrame === 'function') {
                windowLike.requestAnimationFrame(renderMap);
            } else {
                Promise.resolve().then(renderMap);
            }

            return true;
        },

        async ensureMap() {
            if (this._mapLoading) {
                return this._mapLoading;
            }

            if (!this.open
                || this._destroyed
                || !validLiveLocationCoordinates(this.latitude, this.longitude)
                || !this.$refs?.map) {
                return this._map;
            }

            if (this._map) {
                this._map.update({
                    latitude: this.latitude,
                    longitude: this.longitude,
                    accuracy: this.accuracy,
                }, { recenter: true });
                this._map.invalidate({ recenter: true });
                return this._map;
            }

            const windowLike = config.windowLike ?? globalThis.window;
            this._mapLoading = this._mapFactory({
                element: this.$refs.map,
                latitude: this.latitude,
                longitude: this.longitude,
                accuracy: this.accuracy,
                interactive: true,
                mapConfig: this._mapConfig || readLiveLocationMapConfig(),
                windowLike,
            });
            const loading = this._mapLoading;

            try {
                const map = await loading;

                if (this._destroyed || !this.open) {
                    map.destroy();
                    return null;
                }

                this._map = map;
                map.update({
                    latitude: this.latitude,
                    longitude: this.longitude,
                    accuracy: this.accuracy,
                }, { recenter: true });
                this._mappedShareId = this.shareId;
                this.mapReady = true;
                this.mapError = '';
                map.invalidate({ recenter: true });
                return map;
            } catch (_) {
                this.mapError = messageTemplate(
                    this.messages,
                    'mapFailed',
                    'Die Karte konnte nicht geladen werden.',
                );
                return null;
            } finally {
                if (this._mapLoading === loading) {
                    this._mapLoading = null;
                }
            }
        },

        recenter() {
            this._map?.recenter();
        },

        close() {
            this.open = false;
            this._map?.destroy();
            this._map = null;
            this._mappedShareId = null;
            this.mapReady = false;
        },

        async stopSharing() {
            const store = componentStore(this);

            if (!store?.stop || !this.shareId || !this.stopUrl) {
                return null;
            }

            this.busy = true;
            this.stopping = true;
            this.error = '';

            try {
                const stopped = await store.stop(this.shareId, this.stopUrl);
                this.status = stopped?.status || 'stopped';
                return stopped;
            } catch (error) {
                this.error = stringValue(
                    error?.message,
                    messageTemplate(
                        this.messages,
                        'stopFailed',
                        'Die Standortfreigabe konnte nicht beendet werden.',
                    ),
                );
                return null;
            } finally {
                this.busy = false;
                this.stopping = false;
            }
        },

        destroy() {
            const windowLike = config.windowLike ?? globalThis.window;
            this._destroyed = true;
            windowLike?.removeEventListener?.('railtime:live-location-preview', this._previewListener);
            windowLike?.removeEventListener?.('railtime:live-location-card-updated', this._cardListener);
            windowLike?.removeEventListener?.('railtime:live-location-changed', this._signalListener);
            windowLike?.removeEventListener?.('railtime:live-location-stopped', this._signalListener);
            windowLike?.removeEventListener?.('railtime:live-location-expired', this._signalListener);
            windowLike?.clearInterval?.(this._clockTimer);
            this._map?.destroy();
            this._map = null;
            this._mappedShareId = null;
            this.mapReady = false;
        },
    };
}

export function registerRailtimeLiveLocation(Alpine, options = {}) {
    const windowLike = options.windowLike ?? globalThis.window;
    const coordinator = options.coordinator
        ?? windowLike?.RailTimeLiveLocation
        ?? new LiveLocationCoordinator(options);
    const store = createLiveLocationStore(coordinator);

    if (windowLike) {
        windowLike.RailTimeLiveLocation = coordinator;
        windowLike.RailTimeLiveLocationStore = store;
    }

    Alpine.store('liveLocation', store);
    Alpine.data('railtimeLiveLocationShare', railtimeLiveLocationShare);
    Alpine.data('railtimeLiveLocationCard', railtimeLiveLocationCard);
    Alpine.data('railtimeLiveLocationPreview', railtimeLiveLocationPreview);
    coordinator.initialize().catch((error) => coordinator._setError(error, 'initialize_failed'));

    return coordinator;
}
