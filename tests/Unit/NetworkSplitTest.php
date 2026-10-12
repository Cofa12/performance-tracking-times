<?php

namespace Cofa\PerformanceTracking\Tests\Unit;

use Cofa\PerformanceTracking\Format;
use Cofa\PerformanceTracking\NetworkSplit;
use PHPUnit\Framework\TestCase;

class NetworkSplitTest extends TestCase
{
    public function test_it_uses_the_clock_offset_to_split_asymmetric_legs(): void
    {
        // Server clock is 1000 ms ahead. Out takes 30 ms, server works 50 ms, return takes 10 ms.
        $t0 = 10_000.0;
        $t1 = $t0 + 30 + 1000;
        $t2 = $t1 + 50;
        $t3 = $t0 + 30 + 50 + 10;

        $split = NetworkSplit::compute($t0, $t1, $t2, $t3, 1000.0);

        $this->assertEqualsWithDelta(30, $split->outMs, 0.001);
        $this->assertEqualsWithDelta(10, $split->returnMs, 0.001);
        $this->assertSame('offset', $split->method);
    }

    public function test_it_splits_evenly_without_an_offset(): void
    {
        $split = NetworkSplit::compute(0, 5000, 5050, 90, null);

        $this->assertEqualsWithDelta(20, $split->outMs, 0.001);
        $this->assertEqualsWithDelta(20, $split->returnMs, 0.001);
        $this->assertSame('symmetric', $split->method);
    }

    public function test_a_drifted_offset_never_produces_negative_legs(): void
    {
        $split = NetworkSplit::compute(0, 100, 150, 90, 500.0);

        $this->assertGreaterThanOrEqual(0, $split->outMs);
        $this->assertGreaterThanOrEqual(0, $split->returnMs);
        $this->assertEqualsWithDelta(40, $split->outMs + $split->returnMs, 0.001);
    }

    public function test_ticks_use_round_steps(): void
    {
        $this->assertSame([0.0, 50.0, 100.0, 150.0, 200.0], Format::ticks(230));
        $this->assertSame([0.0, 0.2, 0.4, 0.6, 0.8], Format::ticks(0.9));
    }
}
