import Foundation

enum HandoffLinks {
    static func readCode(_ url: URL, pairedBase: String) -> String? {
        guard let base = try? APIClient.validateBase(pairedBase),
              let incoming = URLComponents(url: url, resolvingAgainstBaseURL: false),
              incoming.scheme == "https", incoming.user == nil, incoming.password == nil,
              incoming.host?.lowercased() == base.host?.lowercased(),
              (incoming.port ?? 443) == (base.port ?? 443),
              incoming.path == "/employee-location/open", incoming.query == nil,
              let fragment = incoming.percentEncodedFragment,
              let items = URLComponents(string: "https://fragment.invalid/?" + fragment)?.queryItems else { return nil }
        var values = [String: String]()
        for item in items {
            guard ["code", "server"].contains(item.name), values[item.name] == nil, let value = item.value else { return nil }
            values[item.name] = value
        }
        if let server = values["server"], server.trimmingCharacters(in: CharacterSet(charactersIn: "/")) != pairedBase.trimmingCharacters(in: CharacterSet(charactersIn: "/")) { return nil }
        guard let code = values["code"], code.range(of: "^[A-Za-z0-9_-]{16,256}$", options: .regularExpression) != nil else { return nil }
        return code
    }
}
