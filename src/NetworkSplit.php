<?php

namespace Cofa\PerformanceTracking;

/**
 * Splits the network part of a request into its outbound and return legs.
 *
 * Inputs use two clocks: t0/t3 are the client's send/receive times, t1/t2
 * the server's receive/send times (all epoch milliseconds). The total time
 * spent on the wire, (t3 - t0) - (t2 - t1), does not depend on the clocks
 * agreeing. Splitting it into the two legs does: with a clock offset (server
 * minus client) estimated from the client's fastest exchange we can place t1
 * and t2 on the client clock; without one we fall back to an even split.
 */
final class NetworkSplit
{
    public function __construct(
        public readonly float $outMs,
        public readonly float $returnMs,
        public readonly string $method,
    ) {
    }

    public static function compute(float $t0, float $t1, float $t2, float $t3, ?float $offsetMs): self
    {
        $network = max(0.0, ($t3 - $t0) - ($t2 - $t1));

        if ($offsetMs === null) {
            return new self($network / 2, $network / 2, 'symmetric');
        }

        $out = ($t1 - $offsetMs) - $t0;

        // Offset drift can push one leg below zero; keep the total intact.
        $out = min(max($out, 0.0), $network);

        return new self($out, $network - $out, 'offset');
    }
}
