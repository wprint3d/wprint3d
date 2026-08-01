<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\PersonalAccessToken;
use App\Models\Printer;
use App\Models\User;
use Laravel\Sanctum\NewAccessToken;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ApiTokenService
{
    public const KIND = 'octoprint';

    public function create(
        User $user,
        string $name,
        string $printerUuid,
        ?int $expiresInDays = 365,
    ): NewAccessToken {
        if (! Printer::where('machine.uuid', $printerUuid)->exists()) {
            throw new HttpException(422, 'The selected printer does not exist.');
        }

        $abilities = ((int) $user->role === UserRole::SPECTATOR)
            ? ['read']
            : ['read', 'files', 'print', 'control'];

        $newToken = $user->createToken(
            $name,
            $abilities,
            $expiresInDays === null ? null : now()->addDays($expiresInDays),
        );

        $newToken->accessToken->forceFill([
            'kind' => self::KIND,
            'printer_uuid' => $printerUuid,
        ])->save();

        return $newToken;
    }

    public function resource(PersonalAccessToken $token): array
    {
        return [
            'id' => (string) $token->_id,
            'name' => $token->name,
            'printerUuid' => $token->printer_uuid,
            'abilities' => $token->abilities,
            'createdAt' => $token->created_at?->toIso8601String(),
            'expiresAt' => $token->expires_at?->toIso8601String(),
            'lastUsedAt' => $token->last_used_at?->toIso8601String(),
            'expired' => $token->expires_at?->isPast() ?? false,
        ];
    }
}
