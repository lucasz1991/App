import XCTest
@testable import RailTimeLocation

final class PermitPolicyTests: XCTestCase {
    private func permit(expiry: TimeInterval = 220) -> PermitPolicy {
        var policy = PermitPolicy()
        policy.authorize(serverTime: Date(timeIntervalSince1970: 100), expiresAt: Date(timeIntervalSince1970: expiry),
                         nextSampleAt: Date(timeIntervalSince1970: 100), receivedMonotonic: 5, requestDuration: 1)
        return policy
    }
    func testLeaseClampsTwoMinutesAndSubtractsLatency() {
        let policy = permit(expiry: 900)
        XCTAssertTrue(policy.valid(123.999))
        XCTAssertFalse(policy.valid(124))
    }
    func testNoStaleOrInaccurateFixIsAccepted() {
        let policy = permit()
        XCTAssertTrue(policy.acceptsFix(now: 6, age: 1, accuracy: 50, latitude: 50, longitude: 8))
        XCTAssertFalse(policy.acceptsFix(now: 6, age: 1, accuracy: 50.1, latitude: 50, longitude: 8))
        XCTAssertFalse(policy.acceptsFix(now: 6, age: 31, accuracy: 1, latitude: 50, longitude: 8))
        XCTAssertFalse(policy.acceptsFix(now: 6, age: -1, accuracy: 1, latitude: 50, longitude: 8))
        XCTAssertFalse(policy.acceptsFix(now: 6, age: 1, accuracy: 1, latitude: .nan, longitude: 8))
    }
    func testNoCoordinateAfterExpiryOrStop() {
        var policy = permit()
        XCTAssertFalse(policy.acceptsFix(now: 124, age: 0, accuracy: 1, latitude: 50, longitude: 8))
        policy.invalidate()
        XCTAssertFalse(policy.valid(6))
    }
    func testCadenceUsesServerRatherThanWallClock() {
        var policy = permit()
        policy.acknowledge(Date(timeIntervalSince1970: 1000))
        XCTAssertFalse(policy.sampleDue(10))
        XCTAssertEqual(policy.serverNow(10), Date(timeIntervalSince1970: 105))
    }
    func testCachedFixBeforeCaptureWindowIsDiscarded() {
        XCTAssertFalse(permit().acceptsFix(now: 6, age: 2, accuracy: 1, latitude: 50, longitude: 8))
    }
    func testHandoffCannotRetargetOriginOrUseQueryCredential() {
        let code = String(repeating: "a", count: 48)
        let base = "https://railtime.example"
        XCTAssertEqual(HandoffLinks.readCode(URL(string: base + "/employee-location/open#code=" + code)!, pairedBase: base), code)
        XCTAssertNil(HandoffLinks.readCode(URL(string: "https://other.example/employee-location/open#code=" + code)!, pairedBase: base))
        XCTAssertNil(HandoffLinks.readCode(URL(string: base + "/employee-location/open?code=" + code)!, pairedBase: base))
        XCTAssertNil(HandoffLinks.readCode(URL(string: base + "/employee-location/open#code=" + code + "&code=" + code)!, pairedBase: base))
        XCTAssertNil(HandoffLinks.readCode(URL(string: base + "/employee-location/open#code=" + code + "&server=https%3A%2F%2Fother.example")!, pairedBase: base))
    }
    func testRegistrationRequiresCleanHttpsOrigin() {
        for base in ["http://railtime.example", "https://user:secret@railtime.example", "https://railtime.example/path", "https://railtime.example?token=secret"] {
            XCTAssertThrowsError(try APIClient.validateBase(base))
        }
    }
}
