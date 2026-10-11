package de.railtime.location

import org.junit.Assert.*
import org.junit.Test

class PermitPolicyTest {
    private fun permit(expires: Long = 220_000L): PermitPolicy = PermitPolicy().apply {
        authorize(100_000L, expires, 100_000L, 5_000L, 1_000L)
    }
    @Test fun `lease cannot exceed two minutes and includes request latency`() {
        val policy = permit(900_000L)
        assertTrue(policy.valid(123_999L))
        assertFalse(policy.valid(124_000L))
    }
    @Test fun `late refresh does not resurrect an expired permit`() {
        val policy = permit()
        assertFalse(policy.valid(124_000L))
        policy.invalidate()
        assertFalse(policy.valid(5_000L))
    }
    @Test fun `no inaccurate or stale coordinate is accepted`() {
        val policy = permit()
        assertTrue(policy.acceptsFix(6_000L, 5_000L, 50.0, 50.0, 8.0))
        assertFalse(policy.acceptsFix(6_000L, 5_000L, 50.1, 50.0, 8.0))
        assertFalse(policy.acceptsFix(50_000L, 5_000L, 5.0, 50.0, 8.0))
        assertFalse(policy.acceptsFix(6_000L, 7_000L, 5.0, 50.0, 8.0))
        assertFalse(policy.acceptsFix(6_000L, 5_000L, 5.0, Double.NaN, 8.0))
    }
    @Test fun `cadence uses server clock rather than device wall clock`() {
        val policy = permit()
        policy.acknowledge(1_000_000L)
        assertFalse(policy.sampleDue(10_000L))
        assertEquals(105_000L, policy.serverNow(10_000L))
    }
    @Test fun `expired permit rejects even an accurate fix`() {
        assertFalse(permit().acceptsFix(124_000L, 124_000L, 1.0, 50.0, 8.0))
    }
    @Test fun `a cached fix before the capture window is never uploaded`() {
        assertFalse(permit().acceptsFix(6_000L, 4_000L, 1.0, 50.0, 8.0))
    }
}
