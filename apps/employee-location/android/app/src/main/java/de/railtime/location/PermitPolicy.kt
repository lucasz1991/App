package de.railtime.location

/** No coordinates or authentication material are persisted by this policy. */
class PermitPolicy {
    var deadlineMs: Long = 0L
        private set
    private var receivedMonoMs = 0L
    private var serverTimeMs = 0L
    private var nextSampleServerMs = 0L
    private var lastQualityReportMs: Long? = null

    fun authorize(serverTimeMs: Long, expiresAtMs: Long, nextSampleAtMs: Long,
                  receivedMonoMs: Long, requestDurationMs: Long) {
        val ttl = (expiresAtMs - serverTimeMs).coerceIn(0L, 120_000L)
        this.deadlineMs = receivedMonoMs + (ttl - requestDurationMs.coerceAtLeast(0L)).coerceAtLeast(0L)
        this.receivedMonoMs = receivedMonoMs
        this.serverTimeMs = serverTimeMs
        this.nextSampleServerMs = nextSampleAtMs
    }

    fun valid(nowMonoMs: Long): Boolean = deadlineMs > nowMonoMs
    fun serverNow(nowMonoMs: Long): Long = serverTimeMs + (nowMonoMs - receivedMonoMs).coerceAtLeast(0L)
    fun sampleDue(nowMonoMs: Long): Boolean = valid(nowMonoMs) && serverNow(nowMonoMs) >= nextSampleServerMs
    fun acknowledge(nextSampleAtMs: Long) { nextSampleServerMs = nextSampleAtMs }
    fun invalidate() { deadlineMs = 0L }
    fun qualityReportDue(nowMonoMs: Long): Boolean = sampleDue(nowMonoMs) &&
        (lastQualityReportMs == null || nowMonoMs - lastQualityReportMs!! >= 60_000L)
    fun markQualityReport(nowMonoMs: Long) { lastQualityReportMs = nowMonoMs }

    fun acceptsFix(nowMonoMs: Long, fixMonoMs: Long, accuracyM: Double,
                   latitude: Double, longitude: Double): Boolean =
        sampleDue(nowMonoMs) && fixMonoMs <= nowMonoMs && nowMonoMs - fixMonoMs <= 30_000L &&
            serverNow(nowMonoMs) - (nowMonoMs - fixMonoMs) >= nextSampleServerMs &&
            accuracyM.isFinite() && accuracyM in 0.0..50.0 &&
            latitude.isFinite() && latitude in -90.0..90.0 &&
            longitude.isFinite() && longitude in -180.0..180.0
}
