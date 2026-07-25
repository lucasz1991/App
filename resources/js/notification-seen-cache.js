export class NotificationSeenCache {
    constructor({
        ttl = 120_000,
        now = () => Date.now(),
        channel = null,
    } = {}) {
        this.ttl = ttl;
        this.now = now;
        this.channel = channel;
        this.seen = new Map();

        this.channel?.addEventListener?.('message', (event) => {
            const id = String(event.data?.id || '');
            const expiresAt = Number(event.data?.expiresAt || 0);

            if (id && expiresAt > this.now()) {
                this.seen.set(id, expiresAt);
            }
        });
    }

    take(notificationId) {
        const id = String(notificationId || '').trim();

        if (!id) {
            return true;
        }

        const now = this.now();
        this.prune(now);

        if ((this.seen.get(id) || 0) > now) {
            return false;
        }

        const expiresAt = now + this.ttl;
        this.seen.set(id, expiresAt);
        this.channel?.postMessage?.({ id, expiresAt });

        return true;
    }

    prune(now = this.now()) {
        for (const [id, expiresAt] of this.seen) {
            if (expiresAt <= now) {
                this.seen.delete(id);
            }
        }
    }
}

export function createNotificationSeenCache(windowLike = globalThis.window) {
    let channel = null;

    if (typeof windowLike?.BroadcastChannel === 'function') {
        channel = new windowLike.BroadcastChannel('railtime-notification-seen');
    }

    return new NotificationSeenCache({ channel });
}
