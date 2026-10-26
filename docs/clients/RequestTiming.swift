// Reports API request timings to cofa/performance-tracking-times from an iOS
// app (URLSession, async/await, iOS 15+). See the README, "Mobile app".
//
// A plain call, instead of URLSession.shared.data(for:):
//
//   let (data, response) = try await RequestTiming.shared.data(for: URLRequest(url: ordersURL))
//
// With "calculating" time: the work the app does to prepare the request
// (building and encoding the body, signing, encrypting, compressing an
// upload) is timed and reported with it:
//
//   let (data, response) = try await RequestTiming.shared.measure {
//       var request = URLRequest(url: ordersURL)
//       request.httpMethod = "POST"
//       request.setValue("application/json", forHTTPHeaderField: "Content-Type")
//       request.httpBody = try JSONEncoder().encode(order)   // counted as calculating
//       return request
//   }
//
// Send the queued timings when the app goes to the background:
//
//   .onChange(of: scenePhase) { if $0 == .background { Task { await RequestTiming.shared.flush() } } }

import Foundation

public actor RequestTiming {
    // Change to your API's address.
    public static let shared = RequestTiming(
        endpoint: URL(string: "https://api.example.com/performance-tracking/api/client")!
    )

    private static let offsetTTLMs = 10.0 * 60 * 1000
    // Server limit for calculating time; a larger value would get the whole
    // report rejected, so it is dropped instead.
    private static let maxCalcMs = 10.0 * 60 * 1000

    private struct Report: Encodable {
        let id: String
        let t0: Double
        let t3: Double
        let offset_ms: Double?
        let calc_ms: Double?
        let headers_only: Bool
    }

    private struct Offset {
        let offset: Double
        let rtt: Double
        let at: Double
    }

    private let endpoint: URL
    private let session: URLSession
    private var queue: [Report] = []
    private var offsets: [String: Offset] = [:]
    private var timer: Task<Void, Never>?

    public init(endpoint: URL, session: URLSession = .shared) {
        self.endpoint = endpoint
        self.session = session
    }

    /// Use instead of `session.data(for:)` for calls to the API. `calcMs` is
    /// the time spent preparing the request, if you measured it yourself
    /// (see `measure`).
    public nonisolated func data(for request: URLRequest, calcMs: Double? = nil) async throws -> (Data, URLResponse) {
        let t0 = Self.wallNow()
        let (data, response) = try await session.data(for: request) // reads the whole body
        let t3 = Self.wallNow()

        if let header = (response as? HTTPURLResponse)?.value(forHTTPHeaderField: "X-Request-Timing") {
            await record(header, t0: t0, t3: t3, calcMs: calcMs)
        }

        return (data, response)
    }

    /// Runs `prepare`, which builds the request, and reports the time it took
    /// as "calculating" along with the request it returns. Keep other network
    /// calls (a token refresh, say) outside `prepare`: their time is not
    /// calculating.
    public nonisolated func measure(_ prepare: () async throws -> URLRequest) async throws -> (Data, URLResponse) {
        // Monotonic: the phone changing its clock does not affect it.
        let start = DispatchTime.now().uptimeNanoseconds
        let request = try await prepare()
        let calcMs = Double(DispatchTime.now().uptimeNanoseconds - start) / 1_000_000

        return try await data(for: request, calcMs: calcMs)
    }

    /// Sends the queued timings. Also call this when the app goes to the background.
    public func flush() async {
        timer?.cancel()
        timer = nil
        await send()
    }

    private func send() async {
        guard !queue.isEmpty else { return }

        let batch = Array(queue.prefix(50))
        queue.removeFirst(batch.count)

        var request = URLRequest(url: endpoint)
        request.httpMethod = "POST"
        request.setValue("application/json", forHTTPHeaderField: "Content-Type")
        request.httpBody = try? JSONEncoder().encode(["timings": batch])

        _ = try? await session.data(for: request) // monitoring never breaks the app

        if !queue.isEmpty { await send() }
    }

    private func record(_ header: String, t0: Double, t3: Double, calcMs: Double?) {
        var parts: [String: String] = [:]
        for part in header.split(separator: ";") {
            let pair = part.split(separator: "=", maxSplits: 1)
            if pair.count == 2 {
                parts[pair[0].trimmingCharacters(in: .whitespaces)] = pair[1].trimmingCharacters(in: .whitespaces)
            }
        }

        guard let id = parts["id"], let t1 = Double(parts["t1"] ?? ""), let t2 = Double(parts["t2"] ?? "") else { return }

        // Clock offset (server minus device), per server, from the fastest exchange.
        let node = parts["n"] ?? ""
        let rtt = (t3 - t0) - (t2 - t1)
        if rtt >= 0, offsets[node].map({ rtt <= $0.rtt || t3 - $0.at > Self.offsetTTLMs }) ?? true {
            offsets[node] = Offset(offset: ((t1 - t0) + (t2 - t3)) / 2, rtt: rtt, at: t3)
        }

        queue.append(Report(
            id: id,
            t0: t0,
            t3: t3,
            offset_ms: offsets[node]?.offset,
            calc_ms: calcMs.flatMap { $0 >= 0 && $0 <= Self.maxCalcMs ? $0 : nil },
            headers_only: false
        ))

        if queue.count >= 20 {
            timer?.cancel()
            timer = nil
            Task { await self.send() }
        } else if timer == nil {
            timer = Task {
                try? await Task.sleep(nanoseconds: 5_000_000_000)
                guard !Task.isCancelled else { return }
                await self.timerFired()
            }
        }
    }

    private func timerFired() async {
        timer = nil // not cancelled: the report request runs inside this task
        await send()
    }

    private static func wallNow() -> Double {
        Date().timeIntervalSince1970 * 1000
    }
}
