<?php

namespace Tests\Unit;

use App\Queue\PrinterPoolTopologyResolver;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PrinterPoolTopologyResolverTest extends TestCase
{
    public function test_it_calculates_every_pool_minimum_independently(): void
    {
        $topology = PrinterPoolTopologyResolver::topologyFor(
            'default:1,recordings:1,broadcasts:2,prints:1,previews,snapshots',
            0,
        );

        $this->assertSame([1, 1, 2, 1, 0, 0], array_column($topology, 'workers'));
        $this->assertSame(
            ['default', 'recordings', 'broadcasts', 'prints', 'previews', 'snapshots'],
            array_column($topology, 'name'),
        );
    }

    public function test_active_prints_raise_each_pool_without_cross_pool_state(): void
    {
        $topology = PrinterPoolTopologyResolver::topologyFor('broadcasts:2,previews,snapshots', 3);

        $this->assertSame([3, 3, 3], array_column($topology, 'workers'));
        $this->assertSame(['redis', 'redis', 'redis'], array_column($topology, 'connection'));
    }

    #[DataProvider('invalidDefinitions')]
    public function test_it_rejects_invalid_definitions_atomically(string $definition): void
    {
        $this->expectException(InvalidArgumentException::class);

        PrinterPoolTopologyResolver::topologyFor($definition, 1);
    }

    public static function invalidDefinitions(): array
    {
        return [
            'empty' => [''],
            'duplicate' => ['prints:1,prints:2'],
            'invalid minimum' => ['prints:nope'],
            'negative minimum' => ['prints:-1'],
        ];
    }
}
