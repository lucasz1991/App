package de.railtime.location

import org.junit.Assert.*
import org.junit.Test

class HandoffLinksTest {
    private val code = "a".repeat(48)
    private val origin = "https://railtime.example"

    @Test fun `one-use code only comes from fragment on exact paired origin and path`() {
        assertEquals(code, HandoffLinks.readCode("$origin/employee-location/open#code=$code", origin))
        assertEquals(code, HandoffLinks.readCode("https://RAILTIME.example:443/employee-location/open#code=$code", origin))
        assertNull(HandoffLinks.readCode("http://railtime.example/employee-location/open#code=$code", origin))
        assertNull(HandoffLinks.readCode("https://other.example/employee-location/open#code=$code", origin))
        assertNull(HandoffLinks.readCode("$origin:8443/employee-location/open#code=$code", origin))
        assertNull(HandoffLinks.readCode("$origin/employee-location/open?code=$code", origin))
        assertNull(HandoffLinks.readCode("$origin/another/path#code=$code", origin))
    }
    @Test fun `ambiguous or origin-retargeting fragments are rejected`() {
        assertNull(HandoffLinks.readCode("$origin/employee-location/open#code=$code&code=$code", origin))
        assertNull(HandoffLinks.readCode("$origin/employee-location/open#code=$code&server=https%3A%2F%2Fother.example", origin))
        assertNull(HandoffLinks.readCode("$origin/employee-location/open#code=$code&token=secret", origin))
        assertNull(HandoffLinks.readCode("$origin/employee-location/open#code=%ZZ", origin))
    }
    @Test fun `registration rejects cleartext user info and paths`() {
        for (base in listOf("http://railtime.example", "https://name:secret@railtime.example", "https://railtime.example/path", "https://railtime.example?token=secret", "https://railtime.example#fragment")) {
            assertTrue(runCatching { ApiClient.validateBase(base) }.isFailure)
        }
    }
}
