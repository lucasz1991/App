// Adjust only the frame rate of the display track already selected by the user.
const normalRate = 12;
const reducedRate = 8;

function publishedShare(room, sid) {
    const publication = room?.localParticipant?.getTrackPublication?.('screen_share');
    const media = publication?.track?.mediaStreamTrack;
    if (!publication?.trackSid || (sid !== undefined && publication.trackSid !== sid) || publication.isMuted
        || media?.kind !== 'video' || media.readyState !== 'live' || media.enabled === false) return null;
    return media;
}

function ceiling(track) {
    const rate = track.getConstraints?.().frameRate;
    const limits = [normalRate, track.getSettings?.().frameRate, track.getCapabilities?.().frameRate?.max,
        typeof rate === 'number' ? rate : rate?.max, rate?.ideal, rate?.exact];
    return Math.min(...limits.filter(value => Number.isFinite(value) && value > 0));
}

function sameRate(a, b) {
    if (!a || !b || typeof a !== 'object' || typeof b !== 'object') return a === b;
    return ['ideal', 'min', 'max', 'exact'].every(key => a[key] === b[key]);
}

export function createShareQualityController() {
    let limit = null;
    let generation = 0;
    let pending = Promise.resolve();

    function enqueue(task) {
        pending = pending.then(task).catch(() => false);
        return pending;
    }

    function ownsLimit(room) {
        return Boolean(limit && limit.room === room && publishedShare(room, limit.sid) === limit.track
            && sameRate(limit.track.getConstraints().frameRate, limit.applied));
    }

    async function restore() {
        if (!limit) return false;
        const previous = limit;
        if (!ownsLimit(previous.room)) {
            limit = null;
            return false;
        }
        // Other callers can have changed dimensions or other constraints since the reduction.
        const constraints = previous.track.getConstraints();
        if (previous.original === undefined) delete constraints.frameRate;
        else constraints.frameRate = previous.original;
        await previous.track.applyConstraints(constraints);
        limit = null;
        return publishedShare(previous.room, previous.sid) === previous.track;
    }

    return {
        currentFrameRate(room) {
            const track = publishedShare(room);
            return track ? ceiling(track) : null;
        },
        isLimited: ownsLimit,

        apply(room, sid, limited) {
            if (!sid) return Promise.resolve(false);
            const track = publishedShare(room, sid);
            const request = generation;
            return enqueue(async () => {
                if (!track || request !== generation || publishedShare(room, sid) !== track) return false;
                if (limit && (limit.track !== track || limit.room !== room || limit.sid !== sid)) await restore();
                if (!limited) return restore();
                if (ownsLimit(room)) return true;
                limit = null;
                if (!track.applyConstraints || !track.getConstraints || ceiling(track) <= reducedRate) return false;
                const constraints = track.getConstraints();
                const original = constraints.frameRate;
                if (original?.exact !== undefined || original?.min > reducedRate
                    || constraints.advanced?.some(set => set.frameRate !== undefined)) return false;
                const applied = { max: reducedRate };
                if (original?.min !== undefined) applied.min = original.min;
                if (request !== generation || publishedShare(room, sid) !== track) return false;
                await track.applyConstraints({ ...constraints, frameRate: applied });
                if (publishedShare(room, sid) !== track || !sameRate(track.getConstraints().frameRate, applied)) return false;
                limit = { room, sid, track, original, applied };
                return request === generation;
            });
        },

        reset(room) {
            generation++;
            return enqueue(() => limit && (!room || limit.room === room) ? restore() : false);
        },
    };
}
