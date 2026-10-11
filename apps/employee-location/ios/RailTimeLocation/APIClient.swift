import Foundation

struct APIError: Error { let status: Int }
enum ClientError: Error { case invalidURL, invalidResponse, invalidDate }

struct PermitResponse: Decodable {
    let permit_id: String
    let expires_at: String
    let server_time: String
    let next_sample_at: String
    let work_time_entry_id: Int
}
struct RegisterResponse: Decodable {
    let token: String
    let installation_id: String
    let server_time: String
}
struct PointResponse: Decodable {
    let accepted: Bool
    let next_sample_at: String
    let server_time: String
}
struct QualityResponse: Decodable {
    let reported: Bool
    let server_time: String
}

final class NoRedirectDelegate: NSObject, URLSessionTaskDelegate {
    func urlSession(_ session: URLSession, task: URLSessionTask,
                    willPerformHTTPRedirection response: HTTPURLResponse, newRequest request: URLRequest,
                    completionHandler: @escaping (URLRequest?) -> Void) {
        completionHandler(nil)
    }
}

final class APIClient {
    let base: URL
    private let token: String?
    private let session: URLSession
    private let redirectDelegate: NoRedirectDelegate

    init(base: String, token: String? = nil) throws {
        self.base = try Self.validateBase(base)
        self.token = token
        let config = URLSessionConfiguration.ephemeral
        config.httpShouldSetCookies = false
        config.httpCookieStorage = nil
        config.urlCache = nil
        config.requestCachePolicy = .reloadIgnoringLocalCacheData
        config.timeoutIntervalForRequest = 8
        config.timeoutIntervalForResource = 16
        config.waitsForConnectivity = false
        let delegate = NoRedirectDelegate()
        redirectDelegate = delegate
        session = URLSession(configuration: config, delegate: delegate, delegateQueue: nil)
    }

    static func validateBase(_ input: String) throws -> URL {
        guard let components = URLComponents(string: input), components.scheme == "https",
              let host = components.host, !host.isEmpty, components.user == nil, components.password == nil,
              components.query == nil, components.fragment == nil,
              components.path.isEmpty || components.path == "/", let url = components.url else {
            throw ClientError.invalidURL
        }
        return url
    }

    func post<T: Decodable>(_ action: String, payload: [String: Any] = [:], as type: T.Type) async throws -> T {
        let data = try await request(action, payload: payload)
        return try JSONDecoder().decode(type, from: data)
    }

    func stop() async throws { _ = try await request("stop", payload: [:]) }

    private func request(_ action: String, payload: [String: Any]) async throws -> Data {
        guard ["register", "start", "permit", "points", "quality", "stop"].contains(action) else { throw ClientError.invalidURL }
        let url = base.appendingPathComponent("api/employee-location/" + action)
        var request = URLRequest(url: url)
        request.httpMethod = "POST"
        request.setValue("application/json", forHTTPHeaderField: "Content-Type")
        request.setValue("application/json", forHTTPHeaderField: "Accept")
        if let token { request.setValue("Bearer " + token, forHTTPHeaderField: "Authorization") }
        request.httpBody = try JSONSerialization.data(withJSONObject: payload)
        let (data, response) = try await session.data(for: request)
        guard let http = response as? HTTPURLResponse else { throw ClientError.invalidResponse }
        guard (200..<300).contains(http.statusCode) else { throw APIError(status: http.statusCode) }
        return data
    }

    deinit { session.invalidateAndCancel() }
}

func serverDate(_ text: String) throws -> Date {
    let format = ISO8601DateFormatter()
    format.formatOptions = [.withInternetDateTime, .withFractionalSeconds]
    if let date = format.date(from: text) { return date }
    format.formatOptions = [.withInternetDateTime]
    guard let date = format.date(from: text) else { throw ClientError.invalidDate }
    return date
}

func utcText(_ date: Date) -> String {
    let format = ISO8601DateFormatter()
    format.formatOptions = [.withInternetDateTime, .withFractionalSeconds]
    return format.string(from: date)
}
