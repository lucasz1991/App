import { Track, VideoQuality } from 'livekit-client';

// Use the actual element attached by LiveKit, including the view chosen when a
// participant publishes a camera and a screen share at the same time.
function renderedVideo(publication) {
    const track = publication.track;
    if (!track || !publication.isSubscribed || !publication.isDesired || publication.isMuted) return null;
    let largest = 0;
    let shortEdge = 0;
    let priority = publication.source === Track.Source.ScreenShare;
    for (const element of track.attachedElements || []) {
        const doc = element.ownerDocument;
        const win = doc?.defaultView;
        if (!element.isConnected || !win || element.tagName !== 'VIDEO') continue;
        const pip = doc.pictureInPictureElement === element;
        if (!pip && (doc.hidden || publication.isEnabled === false)) continue;

        const rect = element.getBoundingClientRect();
        const width = Math.max(0, Math.min(rect.right, win.innerWidth) - Math.max(0, rect.left));
        const height = Math.max(0, Math.min(rect.bottom, win.innerHeight) - Math.max(0, rect.top));
        const area = pip ? Math.max(1, width * height) : width * height;
        if (!area) continue;
        const tile = element.closest('.rt-call-tile');
        priority ||= pip || doc.fullscreenElement === element || (tile && doc.fullscreenElement === tile)
            || tile?.classList.contains('rt-call-tile--zoomed') || tile?.classList.contains('rt-call-tile--speaking');
        largest = Math.max(largest, area);
        shortEdge = Math.max(shortEdge, Math.min(element.videoWidth, element.videoHeight));
    }
    return largest ? { publication, area: largest, priority, shortEdge } : null;
}

export function createReceiveQualityController() {
    let currentRoom;
    const owned = new Map();

    function restore(publication, cap) {
        // LiveKit rejects setVideoQuality() while an unsubscribe is pending.
        // Keep that reset for a later sync if this publication is reused.
        if (!publication.isDesired) return false;
        if (publication.videoQuality === cap.applied) publication.setVideoQuality(cap.previous);
        owned.delete(publication);
        return true;
    }

    function reset(room = currentRoom) {
        if (room !== currentRoom) return;
        for (const [publication, cap] of owned) {
            cap.restore = true;
            restore(publication, cap);
        }
    }

    function inspect(room) {
        if (currentRoom !== room) {
            reset();
            owned.clear();
            currentRoom = room;
        }
        const present = new Set();
        const visible = [];
        for (const participant of room?.remoteParticipants?.values() || []) {
            for (const publication of participant.trackPublications.values()) {
                if (publication.kind !== Track.Kind.Video) continue;
                present.add(publication);
                const view = renderedVideo(publication);
                if (view) visible.push(view);
            }
        }

        // Preserve a main picture in a silent gallery or a one-to-one call.
        // Stable sorting keeps equally sized cameras in participant order.
        visible.sort((a, b) => b.area - a.area);
        if (visible.length && !visible.some(view => view.priority)) visible[0].priority = true;
        const eligible = new Map();
        for (const view of visible.slice().reverse()) {
            const pub = view.publication;
            if (!view.priority && pub.source === Track.Source.Camera && typeof pub.setVideoQuality === 'function'
                && (owned.has(pub) || pub.videoQuality !== VideoQuality.LOW)) {
                eligible.set(pub.trackSid, view);
            }
        }
        for (const [pub, cap] of owned) {
            if (!present.has(pub) || pub.videoQuality !== cap.applied) {
                owned.delete(pub);
            } else if (cap.restore || cap.track !== pub.track || eligible.get(pub.trackSid)?.publication !== pub) {
                cap.restore = true;
                restore(pub, cap);
            }
        }
        return { eligible, visible };
    }

    return {
        sync(room) {
            const { eligible, visible } = inspect(room);
            return {
                eligibleSids: new Set(eligible.keys()),
                visibleSids: new Set(visible.map(view => view.publication.trackSid)),
                cappedSids: new Set([...owned].filter(([, cap]) => !cap.restore).map(([pub]) => pub.trackSid)),
            };
        },

        reduce(room, sid) {
            const view = inspect(room).eligible.get(sid);
            if (!view) return false;
            const pub = view.publication;
            if (owned.has(pub) || pub.videoQuality === VideoQuality.LOW) return false;
            const previous = pub.videoQuality ?? VideoQuality.HIGH;
            const applied = view.shortEdge > 360 && previous === VideoQuality.HIGH ? VideoQuality.MEDIUM : VideoQuality.LOW;
            pub.setVideoQuality(applied);
            owned.set(pub, { previous, applied, track: pub.track, restore: false });
            return true;
        },

        recover(room, sid) {
            if (currentRoom !== room) return false;
            for (const [pub, cap] of owned) {
                if (pub.trackSid === sid) return restore(pub, cap);
            }
            return false;
        },

        reset,
    };
}
