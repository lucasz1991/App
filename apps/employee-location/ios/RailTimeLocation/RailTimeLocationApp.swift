import SwiftUI

@main
@MainActor
struct RailTimeLocationApp: App {
    @StateObject private var coordinator = LocationCoordinator()
    @Environment(\.scenePhase) private var scenePhase
    var body: some Scene {
        WindowGroup {
            ContentView(coordinator: coordinator)
                .onOpenURL { coordinator.receiveLink($0) }
                .onContinueUserActivity(NSUserActivityTypeBrowsingWeb) { activity in
                    if let url = activity.webpageURL { coordinator.receiveLink(url) }
                }
                .onChange(of: scenePhase) { _, phase in
                    coordinator.visible = phase == .active
                    if phase == .active { coordinator.checkOnForeground() }
                }
        }
    }
}

@MainActor
struct ContentView: View {
    @ObservedObject var coordinator: LocationCoordinator
    @Environment(\.openURL) private var openURL
    var body: some View {
        NavigationStack {
            Form {
                Section {
                    Text("Die Mitarbeiteroberfläche und die tägliche Zustimmung bleiben in RailTime. Dieser Dienst meldet nur während freigegebener Arbeitszeit alle 15 Minuten einen präzisen Punkt. Kein Offline-Verlauf und kein automatischer Neustart.")
                    Text(coordinator.status).accessibilityAddTraits(.updatesFrequently)
                }
                Section("Geschäftshandy verbinden") {
                    TextField("HTTPS-Adresse Ihrer RailTime-Instanz", text: $coordinator.server)
                        .textInputAutocapitalization(.never).autocorrectionDisabled().keyboardType(.URL)
                        .disabled(coordinator.paired || coordinator.busy)
                    if !coordinator.paired {
                        SecureField("Einmaliger Registrierungscode", text: $coordinator.registrationCode)
                        Button("Geschäftshandy verbinden") { coordinator.register() }.disabled(coordinator.busy)
                    }
                }
                Section("Standortdienst") {
                    SecureField("Startcode, falls App-Link nicht geöffnet wurde", text: $coordinator.manualStartCode)
                    Button("Standortdienst sichtbar starten") { coordinator.start() }
                        .disabled(!coordinator.paired || coordinator.active || coordinator.busy)
                    Button("Standortdienst beenden", role: .destructive) { coordinator.stop() }
                        .disabled(!coordinator.paired)
                    Button("Tagesfreigabe verwalten / widerrufen") {
                        if let url = coordinator.manageURL { openURL(url) }
                    }.disabled(!coordinator.paired)
                }
                Section {
                    Button("Verbindung auf diesem Handy entfernen", role: .destructive) { coordinator.forgetDevice() }
                        .disabled(!coordinator.paired)
                }
            }
            .navigationTitle("RailTime Standort")
        }
    }
}
