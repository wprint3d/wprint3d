<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\PersonalAccessToken;
use App\Models\Printer;
use App\Models\User;
use App\Services\ApiTokenService;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ApiTokenManagementTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        PersonalAccessToken::truncate();
        Printer::truncate();
        User::truncate();
    }

    public function test_password_confirmation_is_required_and_secret_is_only_returned_at_creation(): void
    {
        $user = $this->user(UserRole::USER);
        $this->printer('token-printer');
        $this->withHeader('Origin', 'http://localhost');
        $this->actingAs($user);

        $payload = [
            'name' => 'Cura laptop',
            'printerUuid' => 'token-printer',
            'expiresInDays' => 365,
        ];

        $this->postJson('/api/users/'.$user->_id.'/tokens', $payload)->assertForbidden();

        $this->postJson('/api/user/confirm-password', ['password' => 'password'])
            ->assertOk()
            ->assertJsonStructure(['confirmedUntil']);

        $created = $this->postJson('/api/users/'.$user->_id.'/tokens', $payload)
            ->assertCreated()
            ->assertJsonStructure(['token' => ['id', 'printerUuid', 'expiresAt'], 'plainTextToken']);

        $created->assertJsonMissingPath('token.plainTextToken');

        $this->getJson('/api/users/'.$user->_id.'/tokens')
            ->assertOk()
            ->assertJsonCount(1, 'tokens')
            ->assertJsonMissing(['plainTextToken' => $created->json('plainTextToken')]);

        $this->deleteJson('/api/users/'.$user->_id.'/tokens/'.$created->json('token.id'))
            ->assertNoContent();
        $this->getJson('/api/users/'.$user->_id.'/tokens')
            ->assertOk()
            ->assertJsonCount(0, 'tokens');

        $neverExpires = $this->postJson('/api/users/'.$user->_id.'/tokens', [
            'name' => 'Permanent integration',
            'printerUuid' => 'token-printer',
            'expiresInDays' => null,
        ])->assertCreated();
        $neverExpires->assertJsonPath('token.expiresAt', null);
    }

    public function test_users_manage_their_own_tokens_while_only_admins_manage_other_users(): void
    {
        $user = $this->user(UserRole::USER);
        $other = $this->user(UserRole::USER);
        $this->printer('token-printer');
        $this->withHeader('Origin', 'http://localhost');
        $this->actingAs($user);

        $this->getJson('/api/users/'.$other->_id.'/tokens')->assertForbidden();
        $this->getJson('/api/users')->assertStatus(423);

        $administrator = $this->user(UserRole::ADMINISTRATOR);
        $this->flushSession();
        $this->actingAs($administrator);

        $this->getJson('/api/users/'.$other->_id.'/tokens')->assertOk();
        $this->getJson('/api/users')->assertOk();
    }

    public function test_octoprint_tokens_cannot_expand_into_the_native_api(): void
    {
        $user = $this->user(UserRole::USER);
        $this->printer('isolated-printer');
        $token = app(ApiTokenService::class)->create($user, 'Cura', 'isolated-printer', 365);

        $this->withToken($token->plainTextToken)
            ->getJson('/api/printers')
            ->assertUnauthorized();
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

    private function printer(string $uuid): Printer
    {
        $printer = new Printer;
        $printer->connected = true;
        $printer->machine = ['uuid' => $uuid, 'machineType' => 'Test printer'];
        $printer->save();

        return $printer;
    }
}
