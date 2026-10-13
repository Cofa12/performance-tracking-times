// Reports API request timings to cofa/performance-tracking-times from a
// Flutter app (package:http). See the README, "Mobile app".
//
//   final response = await RequestTiming.instance.send(
//       client, http.Request('GET', Uri.parse('https://api.example.com/api/orders')));
//
// Call RequestTiming.instance.flush() when the app goes to the background.

import 'dart:async';
import 'dart:convert';

import 'package:http/http.dart' as http;

class RequestTiming {
  RequestTiming._();

  static final instance = RequestTiming._();

  // Change to your API's address.
  static final _endpoint = Uri.parse('https://api.example.com/performance-tracking/api/client');
  static const _offsetTtlMs = 10 * 60 * 1000;

  final _queue = <Map<String, Object?>>[];
  final _offsets = <String, ({double offset, double rtt, double at})>{};
  Timer? _timer;

  static double _now() => DateTime.now().microsecondsSinceEpoch / 1000;

  /// Use instead of client.send() for calls to the API. Reads the whole body.
  Future<http.Response> send(http.Client client, http.BaseRequest request, {double? calcMs}) async {
    final t0 = _now();
    final response = await http.Response.fromStream(await client.send(request));
    final t3 = _now();
    final header = response.headers['x-request-timing'];
    if (header != null) _record(header, t0, t3, calcMs);
    return response;
  }

  void _record(String header, double t0, double t3, double? calcMs) {
    final parts = <String, String>{
      for (final p in header.split(';'))
        if (p.contains('=')) p.substring(0, p.indexOf('=')).trim(): p.substring(p.indexOf('=') + 1).trim(),
    };
    final id = parts['id'];
    final t1 = double.tryParse(parts['t1'] ?? '');
    final t2 = double.tryParse(parts['t2'] ?? '');
    if (id == null || t1 == null || t2 == null) return;

    // Clock offset (server minus device), per server, from the fastest exchange.
    final node = parts['n'] ?? '';
    final rtt = (t3 - t0) - (t2 - t1);
    final best = _offsets[node];
    if (rtt >= 0 && (best == null || rtt <= best.rtt || t3 - best.at > _offsetTtlMs)) {
      _offsets[node] = (offset: ((t1 - t0) + (t2 - t3)) / 2, rtt: rtt, at: t3);
    }

    _queue.add({
      'id': id,
      't0': t0,
      't3': t3,
      'offset_ms': _offsets[node]?.offset,
      if (calcMs != null) 'calc_ms': calcMs,
    });

    if (_queue.length >= 20) {
      flush();
    } else {
      _timer ??= Timer(const Duration(seconds: 5), flush);
    }
  }

  /// Sends the queued timings. Also call this when the app goes to the background.
  Future<void> flush() async {
    _timer?.cancel();
    _timer = null;
    if (_queue.isEmpty) return;

    final batch = _queue.take(50).toList();
    _queue.removeRange(0, batch.length);

    try {
      await http.post(
        _endpoint,
        headers: {'Content-Type': 'application/json'},
        body: jsonEncode({'timings': batch}),
      );
    } catch (_) {
      // Monitoring never breaks the app.
    }

    if (_queue.isNotEmpty) await flush();
  }
}
