<?php

namespace Cofa\PerformanceTracking\Tests\Unit;

use Cofa\PerformanceTracking\RequestStartHeader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RequestStartHeaderTest extends TestCase
{
    public static function formats(): array
    {
        return [
            'nginx seconds' => ['t=1728712345.123', 1728712345.123],
            'apache microseconds' => ['t=1728712345123456', 1728712345.123456],
            'bare milliseconds' => ['1728712345123', 1728712345.123],
            'bare seconds' => ['1728712345', 1728712345.0],
        ];
    }

    #[DataProvider('formats')]
    public function test_it_parses_common_formats(string $header, float $expected): void
    {
        $this->assertEqualsWithDelta($expected, RequestStartHeader::parse($header), 0.000001);
    }

    public function test_it_rejects_garbage(): void
    {
        $this->assertNull(RequestStartHeader::parse(null));
        $this->assertNull(RequestStartHeader::parse(''));
        $this->assertNull(RequestStartHeader::parse('t=abc'));
        $this->assertNull(RequestStartHeader::parse('t=-5'));
    }
}
