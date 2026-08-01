<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Services\ApiTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class ApiTokenController extends Controller
{
    private const CONFIRMED_AT_KEY = 'api_tokens.password_confirmed_at';

    public function __construct(private readonly ApiTokenService $tokens) {}

    public function confirmPassword(Request $request): JsonResponse
    {
        $request->validate(['password' => 'required|string']);

        if (! Hash::check($request->string('password')->toString(), $request->user()->password)) {
            throw ValidationException::withMessages([
                'password' => [__('server.auth.invalid_credentials')],
            ]);
        }

        $request->session()->put(self::CONFIRMED_AT_KEY, now()->timestamp);

        return response()->json(['confirmedUntil' => now()->addMinutes(5)->toIso8601String()]);
    }

    public function index(Request $request, string $userId): JsonResponse
    {
        $user = $this->authorizedUser($request, $userId);
        $tokens = $user->tokens()
            ->where('kind', ApiTokenService::KIND)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (PersonalAccessToken $token) => $this->tokens->resource($token))
            ->values();

        return response()->json(['tokens' => $tokens]);
    }

    public function store(Request $request, string $userId): JsonResponse
    {
        $user = $this->authorizedUser($request, $userId);
        $this->ensureRecentlyConfirmed($request);

        $validated = $request->validate([
            'name' => 'required|string|max:80',
            'printerUuid' => 'required|string|max:255',
            'expiresInDays' => 'nullable|integer|in:30,90,365',
        ]);

        $expiresInDays = array_key_exists('expiresInDays', $validated)
            ? $validated['expiresInDays']
            : 365;

        $newToken = $this->tokens->create(
            $user,
            $validated['name'],
            $validated['printerUuid'],
            $expiresInDays,
        );

        return response()->json([
            'token' => $this->tokens->resource($newToken->accessToken),
            'plainTextToken' => $newToken->plainTextToken,
        ], Response::HTTP_CREATED);
    }

    public function destroy(Request $request, string $userId, string $tokenId): Response
    {
        $user = $this->authorizedUser($request, $userId);
        $this->ensureRecentlyConfirmed($request);

        $token = $user->tokens()
            ->where('kind', ApiTokenService::KIND)
            ->where('_id', $tokenId)
            ->first();

        if (! $token) {
            return response('', Response::HTTP_NOT_FOUND);
        }

        $token->delete();

        return response('', Response::HTTP_NO_CONTENT);
    }

    private function authorizedUser(Request $request, string $userId): User
    {
        $operator = $request->user();

        if ((string) $operator->_id !== $userId && (int) $operator->role !== UserRole::ADMINISTRATOR) {
            abort(403, 'Only administrators can manage tokens for other users.');
        }

        $user = User::find($userId);

        if (! $user) {
            abort(404, 'User not found.');
        }

        return $user;
    }

    private function ensureRecentlyConfirmed(Request $request): void
    {
        $confirmedAt = (int) $request->session()->get(self::CONFIRMED_AT_KEY, 0);

        if ($confirmedAt < now()->subMinutes(5)->timestamp) {
            abort(403, 'Confirm your current password before managing API tokens.');
        }
    }
}
