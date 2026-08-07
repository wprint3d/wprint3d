<?php

namespace Tests\Unit\Gcode;

use App\Gcode\AdaptiveEta;
use PHPUnit\Framework\TestCase;

class AdaptiveEtaTest extends TestCase
{
    public function test_it_is_conservative_early_then_converges_from_observed_active_progress(): void
    {
        $eta = new AdaptiveEta(1000);
        $eta->sample(0, 100, true, 0);
        $early = $eta->sample(5, 100, true, 25);
        $stable = $eta->sample(10, 100, true, 100);

        $this->assertFalse($early['stable']);
        $this->assertSame('estimate', $early['printTimeLeftOrigin']);
        $this->assertTrue($stable['stable']);
        $this->assertSame('mixed-analysis', $stable['printTimeLeftOrigin']);
        $this->assertGreaterThanOrEqual(30, $stable['printTimeLeft']);
    }

    public function test_pause_freezes_active_time_and_resume_never_returns_negative_or_premature_seconds(): void
    {
        $eta = new AdaptiveEta(10);
        $eta->sample(0, 100, true, 0);
        $paused = $eta->sample(20, 100, false, 10);
        $stillPaused = $eta->sample(20, 100, false, 1000);
        $resumed = $eta->sample(50, 100, true, 1010);

        $this->assertNull($paused['printTimeLeft']);
        $this->assertSame($paused['printTime'], $stillPaused['printTime']);
        $this->assertGreaterThanOrEqual(30, $resumed['printTimeLeft']);
        $this->assertSame(0, $eta->sample(100, 100, true, 1020)['printTimeLeft']);
    }
}
