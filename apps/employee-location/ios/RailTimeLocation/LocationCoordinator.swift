import CoreLocation
import Darwin
import Foundation
import SwiftUI

/// Continuous monotonic time includes display-off/sleep; wall-clock changes cannot extend a permit.
func continuousSeconds() -> TimeInterval {
    var info = mach_timebase_info_data_t()
    mach_timebase_info(&info)
    return Double(mach_continuous_time()) * Double(info.numer) / Double(info.denom) / 1_000_000_000
}

@MainActor
final class LocationCoordinator: NSObject, ObservableObject, CLLocationManagerDelegate {
    @Published var status = "Standortdienst nicht gestartet"
    @Published var paired = false
    @Published var active = false
    @Published var busy = false
    @Published var server = ""
    @Published var registrationCode = ""
    @Published var manualStartCode = ""
    var visible = true
    private let locationManager = CLLocationManager()
    private var backgroundSession: CLBackgroundActivitySession?
    private var registration: Registration?
    private var api: APIClient?
    private var handoffCode: String?
    private var policy = PermitPolicy()
    private var permitID: String?
    private var timer: Timer?
    private var lastRenewal: TimeInterval = 0
    private var lastFixSeen: TimeInterval = 0
    private var pendingQuality: String?
    private var generation = 0
    private var requestTask: Task<Void, Never>?

    override init() {
        super.init()
        locationManager.delegate = self
        locationManager.desiredAccuracy = kCLLocationAccuracyBest
        locationManager.distanceFilter = kCLDistanceFilterNone
        locationManager.activityType = .other
        locationManager.pausesLocationUpdatesAutomatically = false
        locationManager.allowsBackgroundLocationUpdates = true
        locationManager.showsBackgroundLocationIndicator = true
        registration = CredentialStore.read()
        if let registration, let client = try? APIClient(base: registration.base, token: registration.token) {
            server = registration.base
            api = client
            paired = true
        }
        // A stored registration never restarts an old tracking session.
    }

    func register() {
        guard !busy, !active else { return }
        let base = server.trimmingCharacters(in: .whitespacesAndNewlines).trimmingCharacters(in: CharacterSet(charactersIn: "/"))
        guard let client = try? APIClient(base: base), !registrationCode.isEmpty else { status = "HTTPS-Adresse und Registrierungscode eingeben."; return }
        let code = registrationCode
        registrationCode = ""
        let installationID = UUID().uuidString.lowercased()
        busy = true
        status = "Gerätezuweisung wird geprüft"
        requestTask = Task {
            do {
                let response = try await client.post("register", payload: ["code": code, "installation_id": installationID, "platform": "ios"], as: RegisterResponse.self)
                try Task.checkCancellation()
                let registration = Registration(base: base, token: response.token, installationId: response.installation_id)
                try CredentialStore.save(registration)
                self.registration = registration
                self.api = try APIClient(base: base, token: response.token)
                paired = true
                status = "Verbunden. Sitzungsfreigabe und Startlink unter Mein Arbeitstag öffnen."
            } catch { status = "Registrierung abgelehnt oder Verbindung unterbrochen. Neuen Code in RailTime erstellen." }
            busy = false
        }
    }

    func receiveLink(_ url: URL) {
        guard let registration, let code = HandoffLinks.readCode(url, pairedBase: registration.base) else {
            status = "Geschäftshandy verbinden und gültigen Startlink derselben RailTime-Instanz öffnen."
            return
        }
        handoffCode = code
        status = "Startlink erhalten. Der Standortdienst startet erst nach Ihrer Bedienung."
    }

    func start() {
        guard visible, !active, !busy, let api else { return }
        let authorization = locationManager.authorizationStatus
        guard authorization == .authorizedWhenInUse || authorization == .authorizedAlways else {
            locationManager.requestWhenInUseAuthorization()
            status = "Standortberechtigung erlauben und anschließend erneut starten."
            return
        }
        guard locationManager.accuracyAuthorization == .fullAccuracy else {
            locationManager.requestTemporaryFullAccuracyAuthorization(withPurposeKey: "WorkLocation")
            status = "Präzisen Standort erlauben und anschließend erneut starten."
            return
        }
        let code = handoffCode ?? manualStartCode.trimmingCharacters(in: .whitespacesAndNewlines)
        guard validCode(code) else { status = "Neuen Startlink unter Mein Arbeitstag öffnen."; return }
        handoffCode = nil
        manualStartCode = ""
        generation += 1
        let currentGeneration = generation
        busy = true
        status = "Serverfreigabe wird geprüft"
        let sentAt = continuousSeconds()
        requestTask = Task {
            do {
                let permit = try await api.post("start", payload: ["code": code], as: PermitResponse.self)
                try Task.checkCancellation()
                guard currentGeneration == generation, visible else { throw ClientError.invalidResponse }
                try applyPermit(permit, sentAt: sentAt)
                active = true
                busy = false
                lastFixSeen = continuousSeconds()
                pendingQuality = nil
                backgroundSession = CLBackgroundActivitySession()
                locationManager.startUpdatingLocation()
                timer = Timer(timeInterval: 1, repeats: true) { [weak self] _ in
                    Task { @MainActor in self?.tick() }
                }
                if let timer { RunLoop.main.add(timer, forMode: .common) }
                status = "Aktiv – Meldung alle 15 Minuten während der Arbeitszeit"
            } catch {
                if currentGeneration == generation { stopLocal("Erfassung unterbrochen: Verbindung oder Freigabe fehlt") }
            }
        }
    }

    private func applyPermit(_ permit: PermitResponse, sentAt: TimeInterval) throws {
        let receivedAt = continuousSeconds()
        policy.authorize(serverTime: try serverDate(permit.server_time), expiresAt: try serverDate(permit.expires_at),
                         nextSampleAt: try serverDate(permit.next_sample_at), receivedMonotonic: receivedAt,
                         requestDuration: receivedAt - sentAt)
        guard policy.valid(receivedAt) else { throw ClientError.invalidResponse }
        permitID = permit.permit_id
        lastRenewal = receivedAt
    }

    private func tick() {
        guard active else { return }
        let now = continuousSeconds()
        guard policy.valid(now) else { stopLocal("Erfassung unterbrochen: Serverfreigabe abgelaufen"); return }
        if now - lastRenewal < 60 || busy {
            if policy.sampleDue(now) {
                if now - lastFixSeen >= 30 { pendingQuality = "unavailable" }
                if let pendingQuality { reportQuality(pendingQuality) }
            }
            return
        }
        guard let api else { return }
        let currentGeneration = generation
        busy = true
        let sentAt = now
        requestTask = Task {
            do {
                let permit = try await api.post("permit", as: PermitResponse.self)
                try Task.checkCancellation()
                guard currentGeneration == generation, active, policy.valid(continuousSeconds()) else { throw ClientError.invalidResponse }
                try applyPermit(permit, sentAt: sentAt)
                busy = false
            } catch {
                if currentGeneration == generation { handleError(error, message: "Erfassung unterbrochen: Verbindung oder Sitzungsfreigabe fehlt") }
            }
        }
    }

    func locationManager(_ manager: CLLocationManager, didUpdateLocations locations: [CLLocation]) {
        let now = continuousSeconds()
        guard active else { return }
        guard policy.valid(now) else { stopLocal("Erfassung unterbrochen: Serverfreigabe abgelaufen"); return }
        lastFixSeen = now
        guard !busy, policy.sampleDue(now), let point = locations.last,
              let api, let permitID else { return }
        let age = Date().timeIntervalSince(point.timestamp)
        guard policy.acceptsFix(now: now, age: age, accuracy: point.horizontalAccuracy,
                                latitude: point.coordinate.latitude, longitude: point.coordinate.longitude) else {
            pendingQuality = point.horizontalAccuracy.isFinite && point.horizontalAccuracy > 50 ? "inaccurate" : "unavailable"
            status = "Messung ungenau oder veraltet – noch kein Punkt gespeichert"
            if let pendingQuality { reportQuality(pendingQuality) }
            return
        }
        let currentGeneration = generation
        let payload: [String: Any] = ["permit_id": permitID, "event_id": UUID().uuidString.lowercased(),
            "measured_at": utcText(policy.serverNow(now).addingTimeInterval(-age)),
            "latitude": point.coordinate.latitude, "longitude": point.coordinate.longitude, "accuracy_m": point.horizontalAccuracy]
        busy = true
        requestTask = Task {
            do {
                guard currentGeneration == generation, active, policy.valid(continuousSeconds()) else { throw ClientError.invalidResponse }
                let response = try await api.post("points", payload: payload, as: PointResponse.self)
                try Task.checkCancellation()
                guard currentGeneration == generation, active, policy.valid(continuousSeconds()), response.accepted else { throw ClientError.invalidResponse }
                policy.acknowledge(try serverDate(response.next_sample_at))
                pendingQuality = nil
                busy = false
                status = "Standort gemeldet – nächste Meldung in etwa 15 Minuten"
            } catch {
                if currentGeneration == generation { handleError(error, message: "Erfassung unterbrochen: Standort nicht bestätigt") }
            }
        }
    }

    private func reportQuality(_ quality: String) {
        let now = continuousSeconds()
        guard active, !busy, policy.qualityReportDue(now), let api, let permitID else { return }
        let currentGeneration = generation
        // Metadata only: this payload has no position, measurement time or coordinate history.
        let payload: [String: Any] = ["permit_id": permitID, "quality_status": quality]
        policy.markQualityReport(now)
        busy = true
        requestTask = Task {
            do {
                guard currentGeneration == generation, active, policy.valid(continuousSeconds()) else { throw ClientError.invalidResponse }
                _ = try await api.post("quality", payload: payload, as: QualityResponse.self)
                try Task.checkCancellation()
                guard currentGeneration == generation, active, policy.valid(continuousSeconds()) else { throw ClientError.invalidResponse }
                policy.markQualityReport(continuousSeconds())
                busy = false
            } catch {
                guard currentGeneration == generation else { return }
                if let apiError = error as? APIError, apiError.status == 429, policy.valid(continuousSeconds()) {
                    policy.markQualityReport(continuousSeconds())
                    busy = false
                } else { handleError(error, message: "Erfassung unterbrochen: Qualitätsmeldung nicht bestätigt") }
            }
        }
    }

    func locationManager(_ manager: CLLocationManager, didFailWithError error: Error) {
        // A temporary locationUnknown can recover without retaining the rejected coordinate.
        if let locationError = error as? CLError, locationError.code == .locationUnknown {
            status = "Standort derzeit nicht verfügbar"
            pendingQuality = "unavailable"
            reportQuality("unavailable")
        } else if active { stop() }
    }

    func locationManagerDidChangeAuthorization(_ manager: CLLocationManager) {
        if active && ((manager.authorizationStatus != .authorizedWhenInUse && manager.authorizationStatus != .authorizedAlways) || manager.accuracyAuthorization != .fullAccuracy) {
            stop()
        }
    }

    func checkOnForeground() { if active { tick() } }

    func stop() {
        let client = api
        stopLocal("Standortdienst beendet")
        Task { try? await client?.stop() }
    }

    func forgetDevice() {
        stop()
        CredentialStore.clear()
        registration = nil
        api = nil
        paired = false
        handoffCode = nil
        status = "Verbindung entfernt. Neue Registrierung erforderlich."
    }

    var manageURL: URL? { registration.flatMap { URL(string: $0.base + "/mein-arbeitstag") } }

    private func stopLocal(_ message: String) {
        generation += 1
        active = false
        busy = false
        policy.invalidate()
        permitID = nil
        locationManager.stopUpdatingLocation()
        backgroundSession?.invalidate()
        backgroundSession = nil
        timer?.invalidate()
        timer = nil
        requestTask?.cancel()
        requestTask = nil
        status = message
    }

    private func handleError(_ error: Error, message: String) {
        if let apiError = error as? APIError, apiError.status == 401 {
            CredentialStore.clear()
            registration = nil
            api = nil
            paired = false
        }
        stopLocal(message)
    }

    private func validCode(_ value: String) -> Bool {
        value.range(of: "^[A-Za-z0-9_-]{16,256}$", options: .regularExpression) != nil
    }
}
