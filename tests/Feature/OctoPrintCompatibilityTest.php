<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Events\CommandQueued;
use App\Events\SystemMessage;
use App\Models\Camera;
use App\Models\File;
use App\Models\PersonalAccessToken;
use App\Models\Printer;
use App\Models\User;
use App\Services\ApiTokenService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OctoPrintCompatibilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Camera::truncate();
        PersonalAccessToken::truncate();
        File::truncate();
        Printer::truncate();
        User::truncate();
    }

    public function test_active_and_passive_login_use_an_octoprint_compatible_session(): void
    {
        $user = $this->user();

        $this->postJson('/octoprint-api/login', [
            'user' => $user->name,
            'pass' => 'password',
        ])->assertOk()->assertJsonPath('name', $user->name);

        $this->postJson('/octoprint-api/login', ['passive' => true])
            ->assertOk()
            ->assertJsonPath('active', true);

        $this->postJson('/octoprint-api/logout')->assertNoContent();
        $this->postJson('/octoprint-api/login', ['passive' => true])->assertForbidden();
    }

    public function test_api_keys_work_in_all_supported_locations_and_revocation_is_immediate(): void
    {
        $user = $this->user();
        $printer = $this->printer('printer-one');
        $newToken = app(ApiTokenService::class)->create($user, 'Cura', 'printer-one', 365);
        $this->assertNotSame($newToken->plainTextToken, $newToken->accessToken->token);

        $this->withHeader('X-Api-Key', $newToken->plainTextToken)
            ->getJson('/octoprint-api/version')
            ->assertOk();

        $this->withToken($newToken->plainTextToken)
            ->getJson('/octoprint-api/version')
            ->assertOk();

        $this->getJson('/octoprint-api/version?apikey='.urlencode($newToken->plainTextToken))
            ->assertOk();

        $newToken->accessToken->delete();

        $this->withHeader('X-Api-Key', $newToken->plainTextToken)
            ->getJson('/octoprint-api/version')
            ->assertForbidden();

        $printer->delete();
    }

    public function test_expired_tokens_and_cross_printer_headers_are_rejected(): void
    {
        $user = $this->user();
        $this->printer('printer-one');
        $this->printer('printer-two');

        $boundToken = app(ApiTokenService::class)->create($user, 'Bound', 'printer-one', 365);

        $this->withHeader('X-Api-Key', $boundToken->plainTextToken)
            ->getJson('/octoprint-api/wprint3d/printers')
            ->assertOk()
            ->assertJsonCount(1, 'printers')
            ->assertJsonPath('printers.0.uuid', 'printer-one');

        $this->withHeaders([
            'X-Api-Key' => $boundToken->plainTextToken,
            'X-WPrint3D-Printer-UUID' => 'printer-two',
        ])->getJson('/octoprint-api/printer')->assertForbidden();

        $expiredToken = app(ApiTokenService::class)->create($user, 'Expired', 'printer-one', 30);
        $expiredToken->accessToken->expires_at = now()->subMinute();
        $expiredToken->accessToken->save();

        $this->withHeader('X-Api-Key', $expiredToken->plainTextToken)
            ->getJson('/octoprint-api/version')
            ->assertForbidden();
    }

    public function test_spectator_tokens_are_read_only(): void
    {
        $spectator = $this->user(UserRole::SPECTATOR);
        $this->printer('readonly-printer');
        $token = app(ApiTokenService::class)->create($spectator, 'Read only', 'readonly-printer', 365);

        $this->withHeader('X-Api-Key', $token->plainTextToken)
            ->getJson('/octoprint-api/printer')
            ->assertOk();

        $this->withHeader('X-Api-Key', $token->plainTextToken)
            ->postJson('/octoprint-api/job', ['command' => 'cancel'])
            ->assertForbidden();

        $this->withHeader('X-Api-Key', $token->plainTextToken)
            ->getJson('/octoprint-api/printer/tool')
            ->assertOk();

        $this->withHeader('X-Api-Key', $token->plainTextToken)
            ->postJson('/octoprint-api/printer/printhead', [
                'command' => 'jog',
                'x' => 1,
            ])->assertForbidden();
    }

    public function test_empty_temperature_responses_use_octoprint_objects(): void
    {
        $user = $this->user();
        $printer = $this->printer('temperature-shape-printer');
        $token = app(ApiTokenService::class)->create($user, 'Temperature shape', 'temperature-shape-printer', 365);
        $headers = ['X-Api-Key' => $token->plainTextToken];

        Cache::forget($printer->_id.Printer::CACHE_STATISTICS_SUFFIX);

        $printerResponse = $this->withHeaders($headers)
            ->getJson('/octoprint-api/printer')
            ->assertOk();
        $printerPayload = json_decode($printerResponse->getContent());

        $this->assertIsObject($printerPayload->temperature);
        $this->assertSame([], get_object_vars($printerPayload->temperature));

        $toolResponse = $this->withHeaders($headers)
            ->getJson('/octoprint-api/printer/tool')
            ->assertOk();
        $toolPayload = json_decode($toolResponse->getContent());

        $this->assertIsObject($toolPayload);
        $this->assertSame([], get_object_vars($toolPayload));
    }

    public function test_camera_extension_returns_only_enabled_cameras_linked_to_the_selected_printer(): void
    {
        $user = $this->user();
        $printer = $this->printer('camera-printer');
        $linked = $this->camera('Front camera', '/video/host/uvc/0');
        $external = $this->camera('External camera', 'https://camera.example/stream');
        $disabled = $this->camera('Disabled camera', '/video/host/uvc/1', false);
        $this->camera('Unlinked camera', '/video/host/uvc/2');

        $printer->cameras = [(string) $linked->_id, (string) $external->_id, (string) $disabled->_id];
        $printer->save();

        $token = app(ApiTokenService::class)->create($user, 'Camera monitor', 'camera-printer', 365);

        $this->withHeader('X-Api-Key', $token->plainTextToken)
            ->getJson('/octoprint-api/wprint3d/cameras')
            ->assertOk()
            ->assertJsonCount(2, 'cameras')
            ->assertJsonPath('cameras.0.name', 'Front camera')
            ->assertJsonPath('cameras.0.connected', true)
            ->assertJsonPath('cameras.0.streamUrl', '/video/host/uvc/0?action=stream')
            ->assertJsonPath('cameras.0.snapshotUrl', '/video/host/uvc/0?action=snapshot')
            ->assertJsonPath('cameras.0.streamsMjpeg', true)
            ->assertJsonPath('cameras.1.name', 'External camera')
            ->assertJsonPath('cameras.1.streamUrl', null)
            ->assertJsonPath('cameras.1.snapshotUrl', null);
    }

    public function test_terminal_extension_returns_bounded_history_with_read_access(): void
    {
        $spectator = $this->user(UserRole::SPECTATOR);
        $printer = $this->printer('terminal-history-printer');
        $token = app(ApiTokenService::class)->create($spectator, 'Terminal reader', 'terminal-history-printer', 365);
        $longLine = str_repeat('x', 1100);
        $printer->setConsole("first\nsecond\n{$longLine}\n");

        $this->withHeader('X-Api-Key', $token->plainTextToken)
            ->getJson('/octoprint-api/wprint3d/terminal?limit=2')
            ->assertOk()
            ->assertJsonCount(2, 'lines')
            ->assertJsonPath('lines.0', 'second')
            ->assertJsonPath('total', 3)
            ->assertJsonPath('truncated', true)
            ->assertJsonStructure(['cursor']);

        $this->assertLessThanOrEqual(1001, mb_strlen(
            $this->withHeader('X-Api-Key', $token->plainTextToken)
                ->getJson('/octoprint-api/wprint3d/terminal?limit=1')
                ->json('lines.0'),
        ));
    }

    public function test_password_sessions_can_regenerate_personal_keys_for_the_holder_or_as_admin(): void
    {
        $spectator = $this->user(UserRole::SPECTATOR);
        $printer = $this->printer('personal-key-printer');

        $this->postJson('/octoprint-api/login', [
            'user' => $spectator->name,
            'pass' => 'password',
        ])->assertOk();

        $created = $this->postJson('/octoprint-api/access/users/'.$spectator->name.'/apikey')
            ->assertOk()
            ->assertJsonStructure(['apikey']);

        $personal = PersonalAccessToken::findToken($created->json('apikey'));
        $this->assertSame((string) $spectator->_id, (string) $personal->tokenable_id);
        $this->assertSame(['read'], $personal->abilities);
        $this->assertSame('personal-key-printer', $personal->printer_uuid);

        $this->deleteJson('/octoprint-api/access/users/'.$spectator->name.'/apikey')->assertNoContent();
        $this->assertNull(PersonalAccessToken::findToken($created->json('apikey')));

        $administrator = $this->user(UserRole::ADMINISTRATOR);
        $target = $this->user(UserRole::USER);
        $this->postJson('/octoprint-api/login', [
            'user' => $administrator->name,
            'pass' => 'password',
        ])->assertOk();

        $adminCreated = $this->postJson('/octoprint-api/access/users/'.$target->name.'/apikey')
            ->assertOk();
        $managedToken = PersonalAccessToken::findToken($adminCreated->json('apikey'));

        $this->assertSame((string) $target->_id, (string) $managedToken->tokenable_id);
        $printer->delete();
    }

    public function test_uploads_sanitize_names_and_protect_active_files_and_paths(): void
    {
        Storage::fake('gcode');
        $user = $this->user();
        $printer = $this->printer('upload-printer');
        $token = app(ApiTokenService::class)->create($user, 'Uploader', 'upload-printer', 365);

        $this->withHeader('X-Api-Key', $token->plainTextToken)
            ->post('/octoprint-api/files/local', [
                'file' => UploadedFile::fake()->create('../unsafe name.gcode', 2),
            ])->assertCreated()->assertJsonPath('files.local.path', 'unsafe_name.gcode');

        $this->withHeader('X-Api-Key', $token->plainTextToken)
            ->post('/octoprint-api/files/local', [
                'file' => UploadedFile::fake()->create('unsafe name.gcode', 2),
            ])->assertCreated();

        $printer->activeFile = 'unsafe_name.gcode';
        $printer->hasActiveJob = true;
        $printer->save();

        $this->withHeader('X-Api-Key', $token->plainTextToken)
            ->post('/octoprint-api/files/local', [
                'file' => UploadedFile::fake()->create('unsafe name.gcode', 2),
            ])->assertConflict()->assertJsonPath('reason', 'file_active');

        $this->withHeader('X-Api-Key', $token->plainTextToken)
            ->deleteJson('/octoprint-api/files/local/unsafe_name.gcode')
            ->assertConflict();

        $printer->hasActiveJob = false;
        $printer->lastJobHasFailed = true;
        $printer->save();

        $this->withHeader('X-Api-Key', $token->plainTextToken)
            ->post('/octoprint-api/files/local', [
                'file' => UploadedFile::fake()->create('unsafe name.gcode', 2),
            ])->assertConflict()->assertJsonPath('reason', 'recovery_pending');

        $this->withHeader('X-Api-Key', $token->plainTextToken)
            ->getJson('/octoprint-api/files/local/%252e%252e%252Fsecret.gcode')
            ->assertBadRequest();
    }

    public function test_job_controls_and_conflicts_use_octoprint_status_codes(): void
    {
        Event::fake([SystemMessage::class]);

        $user = $this->user();
        $printer = $this->printer('control-printer');
        $token = app(ApiTokenService::class)->create($user, 'Controller', 'control-printer', 365);
        $headers = ['X-Api-Key' => $token->plainTextToken];

        $printer->activeFile = 'active.gcode';
        $printer->hasActiveJob = true;
        $printer->save();

        $this->withHeaders($headers)->postJson('/octoprint-api/job', [
            'command' => 'pause',
            'action' => 'pause',
        ])->assertNoContent();
        $this->withHeaders($headers)->getJson('/octoprint-api/job')->assertJsonPath('state', 'Paused');

        $this->withHeaders($headers)->postJson('/octoprint-api/job', [
            'command' => 'pause',
            'action' => 'resume',
        ])->assertNoContent();
        $this->withHeaders($headers)->postJson('/octoprint-api/job', ['command' => 'cancel'])->assertNoContent();
        $this->withHeaders($headers)->postJson('/octoprint-api/job', ['command' => 'cancel'])
            ->assertConflict()
            ->assertJsonPath('reason', 'no_active_job');

        $printer->connected = false;
        $printer->save();
        $this->withHeaders($headers)->postJson('/octoprint-api/job', ['command' => 'cancel'])
            ->assertConflict()
            ->assertJsonPath('reason', 'offline');
    }

    public function test_printer_controls_use_octoprint_payloads_and_safe_ranges(): void
    {
        Event::fake([CommandQueued::class]);

        $user = $this->user();
        $printer = $this->printer('manual-control-printer');
        $token = app(ApiTokenService::class)->create($user, 'Manual controls', 'manual-control-printer', 365);
        $headers = ['X-Api-Key' => $token->plainTextToken];

        Cache::put($printer->_id.Printer::CACHE_STATISTICS_SUFFIX, [
            'extruders' => [[
                'temperature' => 205,
                'target' => 210,
            ]],
            'bed' => [
                'temperature' => 55,
                'target' => 60,
            ],
        ]);

        $this->withHeaders($headers)
            ->getJson('/octoprint-api/printer/tool')
            ->assertOk()
            ->assertJsonPath('tool0.actual', 205)
            ->assertJsonPath('tool0.target', 210);

        $this->withHeaders($headers)
            ->getJson('/octoprint-api/printer/bed')
            ->assertOk()
            ->assertJsonPath('bed.actual', 55)
            ->assertJsonPath('bed.target', 60);

        $this->withHeaders($headers)->postJson('/octoprint-api/printer/printhead', [
            'command' => 'jog',
            'x' => 10,
            'y' => -5,
            'absolute' => false,
            'speed' => 1500,
        ])->assertNoContent();
        $this->assertSame(['G91', 'G0 X10 Y-5 F1500', 'G90'], $printer->getResetQueuedCommands());

        $this->withHeaders($headers)->postJson('/octoprint-api/printer/printhead', [
            'command' => 'home',
            'axes' => ['x', 'y'],
        ])->assertNoContent();
        $this->assertSame(['G28 X Y'], $printer->getResetQueuedCommands());

        $this->withHeaders($headers)->postJson('/octoprint-api/printer/printhead', [
            'command' => 'feedrate',
            'factor' => 125,
        ])->assertNoContent();
        $this->assertSame(['M220 S125'], $printer->getResetQueuedCommands());

        $this->withHeaders($headers)->postJson('/octoprint-api/printer/tool', [
            'command' => 'target',
            'targets' => ['tool0' => 215],
        ])->assertNoContent();
        $this->assertSame(['M104 S215'], $printer->getResetQueuedCommands());

        $this->withHeaders($headers)->postJson('/octoprint-api/printer/bed', [
            'command' => 'target',
            'target' => 65,
        ])->assertNoContent();
        $this->assertSame(['M140 S65'], $printer->getResetQueuedCommands());

        $this->withHeaders($headers)->postJson('/octoprint-api/printer/tool', [
            'command' => 'flowrate',
            'factor' => 95,
        ])->assertNoContent();
        $this->assertSame(['M221 S95'], $printer->getResetQueuedCommands());

        $this->withHeaders($headers)->postJson('/octoprint-api/printer/tool', [
            'command' => 'extrude',
            'amount' => 5,
            'speed' => 300,
        ])->assertNoContent();
        $this->assertSame(['M83', 'T0', 'G1 E5 F300', 'M82'], $printer->getResetQueuedCommands());

        $this->withHeaders($headers)->postJson('/octoprint-api/printer/printhead', [
            'command' => 'jog',
            'x' => 101,
        ])->assertBadRequest()->assertJsonPath('reason', 'invalid_request');
        $this->assertSame([], $printer->getResetQueuedCommands());
    }

    public function test_manual_movement_and_cold_extrusion_return_conflicts(): void
    {
        Event::fake([CommandQueued::class]);

        $user = $this->user();
        $printer = $this->printer('safe-control-printer');
        $token = app(ApiTokenService::class)->create($user, 'Safe controls', 'safe-control-printer', 365);
        $headers = ['X-Api-Key' => $token->plainTextToken];

        $this->withHeaders($headers)->postJson('/octoprint-api/printer/tool', [
            'command' => 'extrude',
            'amount' => 5,
        ])->assertConflict()->assertJsonPath('reason', 'cold_extrusion');

        $printer->activeFile = 'active.gcode';
        $printer->hasActiveJob = true;
        $printer->save();

        $this->withHeaders($headers)->postJson('/octoprint-api/printer/printhead', [
            'command' => 'jog',
            'x' => 1,
        ])->assertConflict()->assertJsonPath('reason', 'job_active');

        $this->assertSame([], $printer->getResetQueuedCommands());

        $printer->hasActiveJob = false;
        $printer->lastJobHasFailed = true;
        $printer->save();

        $this->withHeaders($headers)->postJson('/octoprint-api/printer/printhead', [
            'command' => 'jog',
            'x' => 1,
        ])->assertNoContent();
        $this->assertSame(['G91', 'G0 X1 F1500', 'G90'], $printer->getResetQueuedCommands());
    }

    public function test_failed_print_is_reported_as_recovery_instead_of_printing_or_paused(): void
    {
        $user = $this->user();
        $printer = $this->printer('recovery-printer');
        $token = app(ApiTokenService::class)->create($user, 'Recovery status', 'recovery-printer', 365);
        $headers = ['X-Api-Key' => $token->plainTextToken];

        $printer->activeFile = 'failed.gcode';
        $printer->hasActiveJob = false;
        $printer->lastJobHasFailed = true;
        $printer->save();

        $this->withHeaders($headers)->getJson('/octoprint-api/job')
            ->assertOk()
            ->assertJsonPath('state', 'Recovery required')
            ->assertJsonPath('job.file.path', 'failed.gcode');

        $this->withHeaders($headers)->getJson('/octoprint-api/printer')
            ->assertOk()
            ->assertJsonPath('state.text', 'Recovery required')
            ->assertJsonPath('state.flags.operational', true)
            ->assertJsonPath('state.flags.printing', false)
            ->assertJsonPath('state.flags.paused', false)
            ->assertJsonPath('state.flags.error', true)
            ->assertJsonPath('state.flags.wprint3dRecoveryRequired', true);

        $this->withHeaders($headers)->getJson('/octoprint-api/wprint3d/printers')
            ->assertOk()
            ->assertJsonPath('printers.0.printing', false)
            ->assertJsonPath('printers.0.recoveryRequired', true);
    }

    public function test_arbitrary_printer_commands_use_the_octoprint_contract_and_control_permission(): void
    {
        Event::fake([CommandQueued::class]);

        $user = $this->user();
        $printer = $this->printer('terminal-command-printer');
        $token = app(ApiTokenService::class)->create($user, 'Terminal control', 'terminal-command-printer', 365);
        $headers = ['X-Api-Key' => $token->plainTextToken];

        $this->withHeaders($headers)->postJson('/octoprint-api/printer/command', [
            'command' => 'M115',
        ])->assertNoContent();
        $this->assertSame(['M115'], $printer->getResetQueuedCommands());

        $printer->activeFile = 'active.gcode';
        $printer->save();
        $this->withHeaders($headers)->postJson('/octoprint-api/printer/command', [
            'commands' => ['M114', 'M105'],
        ])->assertNoContent();
        $this->assertSame(['M114', 'M105'], $printer->getResetQueuedCommands());

        $spectator = $this->user(UserRole::SPECTATOR);
        $readOnlyToken = app(ApiTokenService::class)->create(
            $spectator,
            'Read-only terminal',
            'terminal-command-printer',
            365,
        );
        $this->withHeader('X-Api-Key', $readOnlyToken->plainTextToken)
            ->postJson('/octoprint-api/printer/command', ['command' => 'M115'])
            ->assertForbidden();
    }

    public function test_arbitrary_printer_commands_reject_ambiguous_or_multiline_payloads(): void
    {
        Event::fake([CommandQueued::class]);

        $user = $this->user();
        $printer = $this->printer('invalid-terminal-command-printer');
        $token = app(ApiTokenService::class)->create($user, 'Terminal validation', 'invalid-terminal-command-printer', 365);
        $headers = ['X-Api-Key' => $token->plainTextToken];

        foreach ([
            [],
            ['command' => 'M105', 'commands' => ['M114']],
            ['command' => "M105\nM114"],
            ['command' => ''],
            ['commands' => array_fill(0, 26, 'M105')],
        ] as $payload) {
            $this->withHeaders($headers)
                ->postJson('/octoprint-api/printer/command', $payload)
                ->assertBadRequest()
                ->assertJsonPath('reason', 'invalid_request');
        }

        $this->assertSame([], $printer->getResetQueuedCommands());
    }

    private function user(int $role = UserRole::USER): User
    {
        $user = new User;
        $user->name = 'user-'.str()->random(8);
        $user->email = str()->random(8).'@example.test';
        $user->password = Hash::make('password');
        $user->role = $role;
        $user->firstLogin = false;
        $user->settings = ['recording' => ['enabled' => false]];
        $user->save();

        return $user;
    }

    private function printer(string $uuid): Printer
    {
        $printer = new Printer;
        $printer->node = '0';
        $printer->baudRate = 115200;
        $printer->connected = true;
        $printer->machine = [
            'uuid' => $uuid,
            'machineType' => 'Test printer',
            'connectionType' => 'serial',
        ];
        $printer->activeFile = null;
        $printer->hasActiveJob = false;
        $printer->lastJobHasFailed = false;
        $printer->save();

        return $printer;
    }

    private function camera(string $name, string $url, bool $enabled = true): Camera
    {
        $camera = new Camera;
        $camera->label = $name;
        $camera->url = $url;
        $camera->connected = true;
        $camera->enabled = $enabled;
        $camera->streamsMjpeg = true;
        $camera->save();

        return $camera;
    }
}
