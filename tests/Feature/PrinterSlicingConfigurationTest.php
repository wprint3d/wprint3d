<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Plugin;
use App\Models\Printer;
use App\Models\User;
use App\Plugins\Contracts\PluginManager;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PrinterSlicingConfigurationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Plugin::truncate();
        Printer::truncate();
        User::truncate();

        Plugin::query()->create([
            'plugin_id' => 'cura-web-ui',
            'name' => 'Cura Web UI',
            'current_version' => '0.1.0-rc.9',
            'enabled' => true,
            'load_status' => 'ready',
            'permissions' => ['ui.custom_bundle', 'printer.read', 'storage.write'],
            'manifest' => [
                'id' => 'cura-web-ui',
                'name' => 'Cura Web UI',
                'version' => '0.1.0-rc.9',
                'runtime' => ['type' => 'bridge', 'baseUrl' => 'http://cura-gateway:9311'],
                'permissions' => ['ui.custom_bundle', 'printer.read', 'storage.write'],
                'uiExtensions' => [],
                'hooks' => [],
                'actions' => [],
            ],
            'dependency_state' => [
                'runtime' => ['baseUrl' => 'http://cura-gateway:9311'],
            ],
            'versions' => [
                '0.1.0-rc.9' => ['path' => base_path()],
            ],
        ]);
    }

    public function test_catalog_discovery_returns_a_scored_server_owned_proposal(): void
    {
        $user = $this->user(UserRole::USER);
        $printer = $this->printer();
        $this->fakeGateway();
        $this->withHeader('Origin', 'http://localhost')->actingAs($user);

        $this->getJson('/api/printer/'.$printer->_id.'/slicing')
            ->assertOk()
            ->assertJsonPath('status', 'unconfirmed')
            ->assertJsonPath('revision', 0)
            ->assertJsonPath('proposal.definitionId', 'ultimaker_s5')
            ->assertJsonPath('proposal.snapshot.buildVolume.width', 330);

        $this->getJson('/api/printer/'.$printer->_id.'/slicing/candidates?q=Ultimaker')
            ->assertOk()
            ->assertJsonPath('items.0.definitionId', 'ultimaker_s5')
            ->assertJsonPath('items.0.score', 100)
            ->assertJsonPath('resourceVersion', '5.12.1');

        Http::assertSent(fn (Request $request): bool => (
            str_contains($request->url(), '/api/v2/machines/catalog')
            && $request->hasHeader('x-wprint-plugin-id', 'cura-web-ui')
            && $request->hasHeader('x-wprint-user-id', (string) $user->_id)
        ));
    }

    public function test_only_an_administrator_can_confirm_a_canonical_snapshot(): void
    {
        $user = $this->user(UserRole::USER);
        $administrator = $this->user(UserRole::ADMINISTRATOR);
        $printer = $this->printer();
        $this->fakeGateway();
        $payload = [
            'expectedRevision' => 0,
            'definitionId' => 'ultimaker_s5',
            'overrides' => [
                'heatedBed' => true,
                'startGcode' => '',
                'endGcode' => '',
                'extruders' => [[
                    'nozzleDiameter' => 0.4,
                    'filamentDiameter' => 2.85,
                    'startGcode' => '',
                    'endGcode' => '',
                ]],
            ],
            'snapshot' => ['buildVolume' => ['width' => 1]],
        ];

        $this->withHeader('Origin', 'http://localhost')->actingAs($user);
        $this->putJson('/api/printer/'.$printer->_id.'/slicing', $payload)->assertStatus(423);

        $this->flushSession();
        $this->withHeader('Origin', 'http://localhost')->actingAs($administrator);
        $this->putJson('/api/printer/'.$printer->_id.'/slicing', $payload)
            ->assertOk()
            ->assertJsonPath('status', 'configured')
            ->assertJsonPath('revision', 1)
            ->assertJsonPath('configuration.provider', 'cura')
            ->assertJsonPath('configuration.resourceVersion', '5.12.1')
            ->assertJsonPath('configuration.snapshot.buildVolume.width', 330)
            ->assertJsonPath('configuration.confirmedBy', (string) $administrator->_id);

        $printer->refresh();
        $this->assertSame(1, $printer->slicing['revision']);
        $this->assertSame(330, $printer->slicing['snapshot']['buildVolume']['width']);
        $this->assertArrayNotHasKey('snapshot', $printer->slicing['overrides']);
        $this->assertSame('', $printer->slicing['overrides']['startGcode']);
        $this->assertSame('', $printer->slicing['overrides']['endGcode']);
        $this->assertSame('', $printer->slicing['overrides']['extruders'][0]['startGcode']);
        $this->assertSame('', $printer->slicing['overrides']['extruders'][0]['endGcode']);
    }

    public function test_confirmation_uses_optimistic_concurrency(): void
    {
        $administrator = $this->user(UserRole::ADMINISTRATOR);
        $printer = $this->printer();
        $this->fakeGateway();
        $this->withHeader('Origin', 'http://localhost')->actingAs($administrator);
        $endpoint = '/api/printer/'.$printer->_id.'/slicing';
        $payload = ['expectedRevision' => 0, 'definitionId' => 'ultimaker_s5'];

        $this->putJson($endpoint, $payload)->assertOk()->assertJsonPath('revision', 1);
        Http::assertSent(fn (Request $request): bool => (
            str_contains($request->url(), '/api/v2/machines/resolve')
            && str_contains($request->body(), '"overrides":{}')
        ));
        $this->putJson($endpoint, $payload)
            ->assertConflict()
            ->assertJsonPath('error.code', 'slicing_revision_conflict')
            ->assertJsonPath('error.details.expectedRevision', 0)
            ->assertJsonPath('error.details.currentRevision', 1);
    }

    public function test_embedded_host_context_contains_the_reactive_printer_contract(): void
    {
        $user = $this->user(UserRole::USER);
        $printer = $this->printer();
        $printer->slicing = [
            'schemaVersion' => 1,
            'provider' => 'cura',
            'resourceVersion' => '5.12.1',
            'definitionId' => 'ultimaker_s5',
            'revision' => 3,
            'confirmedAt' => now()->toAtomString(),
            'confirmedBy' => 'administrator',
            'overrides' => [],
            'snapshot' => [
                'definitionId' => 'ultimaker_s5',
                'displayName' => 'Ultimaker S5',
                'buildVolume' => ['shape' => 'rectangular', 'width' => 330, 'depth' => 240, 'height' => 300],
                'extruders' => [['nozzleDiameter' => 0.4, 'filamentDiameter' => 2.85]],
            ],
        ];
        $printer->save();

        $this->startSession();
        $this->assertTrue($user->setActivePrinterId((string) $printer->_id));
        $context = app(PluginManager::class)->hostContext('cura-web-ui', $user, 'es_AR');

        $this->assertSame('2.0', $context['apiVersion']);
        $this->assertSame('split-pane', $context['presentation']);
        $this->assertSame((string) $printer->_id, $context['currentPrinterId']);
        $this->assertSame((string) $printer->_id, $context['currentPrinter']['id']);
        $this->assertSame('configured', $context['currentPrinter']['slicingStatus']);
        $this->assertSame(3, $context['currentPrinter']['slicingRevision']);
        $this->assertSame('ultimaker_s5', $context['currentPrinter']['machineSnapshot']['definitionId']);
        $this->assertSame('printer.slicing.open', $context['hostActions'][0]['id']);
    }

    private function fakeGateway(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/api/v2/machines/catalog')) {
                return Http::response([
                    'items' => [[
                        'definitionId' => 'ultimaker_s5',
                        'displayName' => 'Ultimaker S5',
                        'manufacturer' => 'Ultimaker',
                        'machineType' => 'Ultimaker S5',
                        'extruderCount' => 2,
                        'resourceVersion' => '5.12.1',
                    ]],
                    'resourceVersion' => '5.12.1',
                ]);
            }

            if (str_contains($request->url(), '/api/v2/machines/resolve')) {
                return Http::response([
                    'snapshot' => [
                        'definitionId' => 'ultimaker_s5',
                        'displayName' => 'Ultimaker S5',
                        'buildVolume' => [
                            'shape' => 'rectangular',
                            'width' => 330,
                            'depth' => 240,
                            'height' => 300,
                            'origin' => [0, 0, 0],
                            'excludedAreas' => [],
                        ],
                        'heatedBed' => true,
                        'heatedBuildVolume' => false,
                        'gcodeFlavor' => 'Griffin',
                        'printheadBounds' => null,
                        'gantryHeight' => 55,
                        'startGcode' => '',
                        'endGcode' => '',
                        'extruders' => [
                            ['nozzleDiameter' => 0.4, 'filamentDiameter' => 2.85, 'offsets' => [0, 0], 'coolingFan' => true, 'startGcode' => '', 'endGcode' => ''],
                            ['nozzleDiameter' => 0.4, 'filamentDiameter' => 2.85, 'offsets' => [18, 0], 'coolingFan' => true, 'startGcode' => '', 'endGcode' => ''],
                        ],
                    ],
                    'snapshotHash' => str_repeat('a', 64),
                    'diagnostics' => [],
                ]);
            }

            return Http::response([], 404);
        });
    }

    private function user(int $role): User
    {
        $user = new User;
        $user->name = 'user-'.str()->random(8);
        $user->email = str()->random(8).'@example.test';
        $user->password = Hash::make('password');
        $user->role = $role;
        $user->firstLogin = false;
        $user->save();

        return $user;
    }

    private function printer(): Printer
    {
        $printer = new Printer;
        $printer->node = '/dev/fake';
        $printer->connected = true;
        $printer->machine = [
            'uuid' => 'printer-'.str()->random(8),
            'manufacturer' => 'Ultimaker',
            'model' => 'S5',
            'machineType' => 'Ultimaker S5',
            'extruderCount' => 2,
        ];
        $printer->save();

        return $printer;
    }
}
