<?php

namespace Cofa\PerformanceTracking\Http\Controllers;

use Cofa\PerformanceTracking\Models\PerformanceTiming;
use Cofa\PerformanceTracking\NetworkSplit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Receives the client side of each exchange.
 *
 * Body: {"timings": [{
 *   "id": "<X-Request-Timing id>",
 *   "t0": <epoch ms, request sent>,
 *   "t3": <epoch ms, response fully received>,
 *   "calc_ms": <ms spent preparing the request, optional>,
 *   "connect_ms": <ms for DNS + TCP + TLS, optional>,
 *   "offset_ms": <server clock minus client clock, optional>
 * }]}
 */
class ClientReportController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        // sendBeacon posts text/plain to avoid a CORS preflight.
        $payload = $request->isJson() ? $request->json()->all() : (json_decode($request->getContent(), true) ?: []);

        $validated = validator($payload, [
            'timings' => ['required', 'array', 'max:50'],
            'timings.*.id' => ['required', 'uuid'],
            'timings.*.t0' => ['required', 'numeric', 'min:0'],
            'timings.*.t3' => ['required', 'numeric', 'min:0'],
            'timings.*.calc_ms' => ['nullable', 'numeric', 'min:0', 'max:600000'],
            'timings.*.connect_ms' => ['nullable', 'numeric', 'min:0', 'max:600000'],
            'timings.*.offset_ms' => ['nullable', 'numeric'],
        ])->validate();

        $window = now()->subMinutes((int) config('performance-tracking.client_report_window_minutes', 10));
        $byId = collect($validated['timings'])->keyBy('id');

        $records = PerformanceTiming::query()
            ->whereIn('id', $byId->keys())
            ->whereNull('client_reported_at')
            ->where('created_at', '>=', $window)
            ->get();

        foreach ($records as $record) {
            $this->apply($record, $byId[$record->id]);
        }

        return response()->json(['accepted' => $records->count()]);
    }

    private function apply(PerformanceTiming $record, array $client): void
    {
        $t0 = (float) $client['t0'];
        $t3 = (float) $client['t3'];

        // The client cannot have waited less than the server worked, nor
        // more than ten minutes for one response.
        if ($t3 - $t0 < $record->server_ms - 1 || $t3 - $t0 > 600000) {
            return;
        }

        $offset = isset($client['offset_ms']) ? (float) $client['offset_ms'] : null;
        $split = NetworkSplit::compute($t0, $record->server_received_ms, $record->server_sent_ms, $t3, $offset);

        $calc = isset($client['calc_ms']) ? (float) $client['calc_ms'] : null;
        $connect = isset($client['connect_ms']) ? (float) $client['connect_ms'] : null;

        $record->forceFill([
            'calc_ms' => $calc,
            'connect_ms' => $connect,
            'network_out_ms' => $split->outMs,
            'network_return_ms' => $split->returnMs,
            'clock_offset_ms' => $offset,
            'network_split' => $split->method,
            'response_ms' => ($calc ?? 0) + ($connect ?? 0) + $split->outMs + $record->server_ms + $split->returnMs,
            'client_reported_at' => now(),
        ])->save();
    }
}
