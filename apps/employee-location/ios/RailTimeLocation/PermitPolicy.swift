import Foundation

struct PermitPolicy {
    private(set) var deadline: TimeInterval = 0
    private var receivedMonotonic: TimeInterval = 0
    private var serverTime: TimeInterval = 0
    private var nextSampleTime: TimeInterval = 0

    mutating func authorize(serverTime: Date, expiresAt: Date, nextSampleAt: Date,
                            receivedMonotonic: TimeInterval, requestDuration: TimeInterval) {
        let ttl = min(120, max(0, expiresAt.timeIntervalSince(serverTime)))
        deadline = receivedMonotonic + max(0, ttl - max(0, requestDuration))
        self.receivedMonotonic = receivedMonotonic
        self.serverTime = serverTime.timeIntervalSince1970
        self.nextSampleTime = nextSampleAt.timeIntervalSince1970
    }

    func valid(_ now: TimeInterval) -> Bool { deadline > now }
    func serverNow(_ now: TimeInterval) -> Date {
        Date(timeIntervalSince1970: serverTime + max(0, now - receivedMonotonic))
    }
    func sampleDue(_ now: TimeInterval) -> Bool {
        valid(now) && serverNow(now).timeIntervalSince1970 >= nextSampleTime
    }
    func acceptsFix(now: TimeInterval, age: TimeInterval, accuracy: Double,
                    latitude: Double, longitude: Double) -> Bool {
        sampleDue(now) && age >= 0 && age <= 30 && serverNow(now).timeIntervalSince1970 - age >= nextSampleTime &&
        accuracy.isFinite && accuracy >= 0 && accuracy <= 50 &&
        latitude.isFinite && (-90...90).contains(latitude) &&
        longitude.isFinite && (-180...180).contains(longitude)
    }
    mutating func acknowledge(_ date: Date) { nextSampleTime = date.timeIntervalSince1970 }
    mutating func invalidate() { deadline = 0 }
}
