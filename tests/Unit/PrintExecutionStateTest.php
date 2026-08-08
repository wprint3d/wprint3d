<?php

namespace Tests\Unit;

use App\Models\Printer;
use App\Models\User;
use App\Services\PrintExecutionState;
use App\Services\PrintStateTracker;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PrintExecutionStateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.print_execution_store' => 'array']);
        Cache::store('array')->flush();
    }

    public function test_it_checkpoints_commands_and_fences_superseded_workers(): void
    {
        $printer = new class extends Printer
        {
            public string $_id = 'checkpoint-printer';

            public mixed $activePrintExecution = null;

            public array $machine = ['uuid' => 'FAKE-UUID/canonical-fingerprint'];

            public bool $saved = false;

            public function __construct() {}

            public function save(array $options = []): bool
            {
                $this->saved = true;

                return true;
            }
        };
        $owner = new class extends User
        {
            public string $_id = 'checkpoint-owner';

            public function __construct() {}
        };
        $state = new PrintExecutionState(new PrintStateTracker);

        $state->begin($printer, $owner, 'print-uid', 'token-1');
        $initialCheckpoint = $state->checkpoint($printer->_id);

        $this->assertSame(
            'FAKE-UUID/canonical-fingerprint',
            $printer->activePrintExecution['machineFingerprint']
        );
        $this->assertSame(
            'FAKE-UUID/canonical-fingerprint',
            $initialCheckpoint['machineFingerprint']
        );

        $state->markReady(
            $printer->_id,
            'token-1',
            ['x' => 10, 'y' => 20, 'z' => 0.2, 'e' => 4],
            [
                'extruders' => [0 => ['temperature' => 205, 'target' => 210]],
                'bed' => ['temperature' => 60, 'target' => 60],
            ]
        );

        $state->preparePending($printer->_id, 'token-1', 'M83', true);
        $state->commitPending($printer->_id, 'token-1');
        $state->preparePending($printer->_id, 'token-1', 'G1 X11 E1.5', true);
        $checkpoint = $state->commitPending($printer->_id, 'token-1');

        $this->assertSame(2, $checkpoint['sourceCommandIndex']);
        $this->assertSame(3, $checkpoint['displayedLine']);
        $this->assertSame(11.0, $checkpoint['state']['position']['x']);
        $this->assertSame(5.5, $checkpoint['state']['position']['e']);
        $this->assertSame('M83', $checkpoint['state']['modal']['extruder']);
        $this->assertTrue($state->isCurrent($printer->_id, 'token-1'));

        $rotated = $state->rotate($printer);

        $this->assertNotNull($rotated);
        $this->assertNotSame('token-1', $rotated['token']);
        $this->assertFalse($state->isCurrent($printer->_id, 'token-1'));
        $this->assertTrue($state->isCurrent($printer->_id, $rotated['token']));
    }

    public function test_temperature_targets_are_not_overwritten_by_stale_statistics(): void
    {
        $tracker = new PrintStateTracker;
        $state = $tracker->initial(
            ['x' => 0, 'y' => 0, 'z' => 0, 'e' => 0],
            ['extruders' => [0 => ['temperature' => 200, 'target' => 200]]]
        );
        $predicted = $tracker->predict($state, 'M104 S215')['state'];
        $merged = $tracker->withThermalSnapshot(
            $predicted,
            ['extruders' => [0 => ['temperature' => 202, 'target' => 200]]]
        );

        $this->assertSame(215.0, $merged['thermal']['extruders'][0]['target']);
        $this->assertSame(202.0, $merged['thermal']['extruders'][0]['temperature']);
    }

    public function test_color_swap_tracks_the_physical_park_position_and_preserves_the_return_position(): void
    {
        $tracker = new PrintStateTracker;
        $state = $tracker->initial(
            ['x' => 10, 'y' => 20, 'z' => 0.2, 'e' => 4],
            []
        );
        $parked = $tracker->predict($state, 'G0 X0 Y0 Z50 ;WP3DNOPOSCHG')['state'];

        $this->assertSame($state['position'], $parked['returnPosition']);
        $this->assertSame(
            ['x' => 0.0, 'y' => 0.0, 'z' => 50.0, 'e' => 4.0],
            $parked['position']
        );
    }

    public function test_unpredictable_motion_is_committed_from_an_observed_position(): void
    {
        $printer = new class extends Printer
        {
            public string $_id = 'observed-position-printer';

            public mixed $activePrintExecution = null;

            public function __construct() {}

            public function save(array $options = []): bool
            {
                return true;
            }
        };
        $owner = new class extends User
        {
            public string $_id = 'observed-position-owner';

            public function __construct() {}
        };
        $executionState = new PrintExecutionState(new PrintStateTracker);
        $executionState->begin($printer, $owner, 'observed-position-uid', 'observed-position-token');
        $executionState->markReady(
            $printer->_id,
            'observed-position-token',
            ['x' => 10, 'y' => 20, 'z' => 0.2, 'e' => 4],
            []
        );
        $pending = $executionState->preparePending(
            $printer->_id,
            'observed-position-token',
            'G28 X Y',
            true
        );
        $checkpoint = $executionState->commitPending(
            $printer->_id,
            'observed-position-token',
            [],
            ['x' => 0, 'y' => 0, 'z' => 0.2, 'e' => 4]
        );

        $this->assertTrue($pending['requiresPositionRefresh']);
        $this->assertSame(
            ['x' => 0.0, 'y' => 0.0, 'z' => 0.2, 'e' => 4.0],
            $checkpoint['state']['position']
        );
    }
}
