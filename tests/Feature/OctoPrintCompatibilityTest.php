<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Camera;
use App\Models\File;
use App\Models\PersonalAccessToken;
use App\Models\Printer;
use App\Models\User;
use App\Services\ApiTokenService;
use Illuminate\Http\UploadedFile;
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
        $printer->save();

        $this->withHeader('X-Api-Key', $token->plainTextToken)
            ->post('/octoprint-api/files/local', [
                'file' => UploadedFile::fake()->create('unsafe name.gcode', 2),
            ])->assertConflict()->assertJsonPath('reason', 'file_active');

        $this->withHeader('X-Api-Key', $token->plainTextToken)
            ->deleteJson('/octoprint-api/files/local/unsafe_name.gcode')
            ->assertConflict();

        $this->withHeader('X-Api-Key', $token->plainTextToken)
            ->getJson('/octoprint-api/files/local/%252e%252e%252Fsecret.gcode')
            ->assertBadRequest();
    }

    public function test_job_controls_and_conflicts_use_octoprint_status_codes(): void
    {
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
