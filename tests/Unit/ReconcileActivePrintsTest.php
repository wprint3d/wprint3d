<?php

namespace Tests\Unit;

use App\Console\Services\Concurrent\ReconcileActivePrints;
use App\Events\PrintJobFailed;
use App\Jobs\PrintGcode;
use App\Models\Configuration;
use App\Models\Printer;
use App\Models\User;
use App\Plugins\PluginHookDispatcher;
use App\Services\PrintExecutionState;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ReconcileActivePrintsTest extends TestCase
{
    private array $printerIds = [];

    private array $userIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.print_execution_store' => 'array']);
        Cache::store('array')->flush();
        Configuration::query()->delete();

        foreach ([
            'runningTimeoutSecs' => 10,
            'commandTimeoutSecs' => 60,
            'lastSeenPollIntervalSecs' => 2,
            'jobBackupInterval' => 0,
            'streamMaxLengthBytes' => 256,
        ] as $key => $value) {
            Configuration::create(compact('key', 'value'));
        }

        app()->instance(PluginHookDispatcher::class, new class
        {
            public function dispatch(string $hook, array $context = []): array
            {
                return [];
            }
        });
    }

    protected function tearDown(): void
    {
        Printer::whereIn('_id', $this->printerIds)->delete();
        User::whereIn('_id', $this->userIds)->delete();
        Configuration::query()->delete();
        app()->forgetInstance(PluginHookDispatcher::class);

        parent::tearDown();
    }

    public function test_stale_heartbeat_rotates_the_fence_and_dispatches_a_continuation(): void
    {
        Queue::fake();
        [$owner, $printer] = $this->persistScenario('stale');
        $state = app(PrintExecutionState::class);
        $state->begin($printer, $owner, 'stale-job-uid', 'stale-token');
        $state->markReady(
            (string) $printer->_id,
            'stale-token',
            ['x' => 10, 'y' => 20, 'z' => 0.2, 'e' => 4],
            ['extruders' => [0 => ['temperature' => 205, 'target' => 210]]]
        );
        usleep(1000);

        $this->service()->run([$printer], 0);

        $printer->refresh();
        $rotatedToken = $printer->activePrintExecution['token'];

        $this->assertNotSame('stale-token', $rotatedToken);
        $this->assertFalse($state->isCurrent((string) $printer->_id, 'stale-token'));
        $this->assertTrue($state->isCurrent((string) $printer->_id, $rotatedToken));
        Queue::assertPushed(PrintGcode::class, function (PrintGcode $job) use ($rotatedToken): bool {
            return \Closure::bind(
                fn (): bool => $job->uid === 'stale-job-uid'
                    && $job->executionToken === $rotatedToken,
                null,
                PrintGcode::class
            )();
        });
    }

    public function test_missing_checkpoint_transitions_the_print_to_pending_recovery(): void
    {
        Queue::fake();
        Event::fake([PrintJobFailed::class]);
        [, $printer] = $this->persistScenario('missing');

        $this->service()->run([$printer], 0);

        $printer->refresh();

        $this->assertFalse($printer->hasActiveJob);
        $this->assertTrue($printer->lastJobHasFailed);
        $this->assertSame('reconcile-missing.gcode', $printer->activeFile);
        $this->assertNull($printer->activePrintExecution);
        Queue::assertNotPushed(PrintGcode::class);
        Event::assertDispatched(PrintJobFailed::class);
    }

    private function service(): object
    {
        return new class extends ReconcileActivePrints
        {
            public function run(iterable $printers, int $staleAfterSecs): void
            {
                $this->reconcilePrinters($printers, $staleAfterSecs);
            }
        };
    }

    private function persistScenario(string $suffix): array
    {
        $owner = new User;
        $owner->name = 'Reconcile Test';
        $owner->email = "reconcile-{$suffix}@example.test";
        $owner->password = 'test-password';
        $owner->settings = [
            'recording' => [
                'enabled' => false,
                'captureInterval' => 10,
            ],
        ];
        $owner->save();
        $this->userIds[] = $owner->_id;

        $printer = new Printer;
        $printer->node = 'RECONCILE_TEST';
        $printer->baudRate = 115200;
        $printer->machine = ['uuid' => 'FAKE-UUID/reconcile-test'];
        $printer->hasActiveJob = true;
        $printer->lastJobHasFailed = false;
        $printer->activeFile = "reconcile-{$suffix}.gcode";
        $printer->save();
        $this->printerIds[] = $printer->_id;

        return [$owner, $printer];
    }
}
