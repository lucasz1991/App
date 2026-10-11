import Foundation
import Security

struct Registration: Codable {
    let base: String
    let token: String
    let installationId: String
}

enum CredentialStore {
    private static let service = "de.railtime.location.registration"
    private static let account = "corporate-installation"
    private static var query: [String: Any] {
        [kSecClass as String: kSecClassGenericPassword,
         kSecAttrService as String: service, kSecAttrAccount as String: account]
    }
    static func save(_ registration: Registration) throws {
        let data = try JSONEncoder().encode(registration)
        SecItemDelete(query as CFDictionary)
        var item = query
        item[kSecValueData as String] = data
        item[kSecAttrAccessible as String] = kSecAttrAccessibleAfterFirstUnlockThisDeviceOnly
        guard SecItemAdd(item as CFDictionary, nil) == errSecSuccess else { throw ClientError.invalidResponse }
    }
    static func read() -> Registration? {
        var item = query
        item[kSecReturnData as String] = true
        item[kSecMatchLimit as String] = kSecMatchLimitOne
        var result: CFTypeRef?
        guard SecItemCopyMatching(item as CFDictionary, &result) == errSecSuccess,
              let data = result as? Data else { return nil }
        return try? JSONDecoder().decode(Registration.self, from: data)
    }
    static func clear() { SecItemDelete(query as CFDictionary) }
}
