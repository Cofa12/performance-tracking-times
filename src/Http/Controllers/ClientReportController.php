<?php

namespace Cofa\PerformanceTracking\Http\Controllers;

use Cofa\PerformanceTracking\ClientReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Receives the client side of each exchange: {"timings": [<entry>, ...]}.
 * See ClientReport for the entry format.
 */
class ClientReportController extends Controller
{
    public function __invoke(Request $request, ClientReport $report): JsonResponse
    {
        // sendBeacon posts text/plain to avoid a CORS preflight.
        $decoded = $request->isJson() ? $request->json()->all() : json_decode($request->getContent(), true);
        $payload = is_array($decoded) ? $decoded : [];

        $validator = validator($payload, [
            'timings' => ['required', 'array', 'max:'.ClientReport::MAX_ENTRIES],
        ]);

        // Always JSON: a beacon sends no Accept header, and a redirect back
        // is meaningless to it.
        if ($validator->fails()) {
            return response()->json(['message' => 'Invalid report.', 'errors' => $validator->errors()], 422);
        }

        return response()->json($report->apply($payload['timings']));
    }
}
