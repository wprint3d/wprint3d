<?php

namespace App\Http\Controllers\OctoPrint;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Printer;
use App\Models\User;
use App\Services\ApiTokenService;
use App\Services\OctoPrint\OctoPrintContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use MongoDB\BSON\Regex;
use Symfony\Component\HttpFoundation\Response;

class AuthController extends Controller
{
    private const PERSONAL_KEY_NAME = 'OctoPrint personal key';

    public function __construct(
        private readonly OctoPrintContext $context,
        private readonly ApiTokenService $tokens,
    ) {}

    public function login(Request $request): JsonResponse
    {
        if ($request->boolean('passive')) {
            return $request->user()
                ? response()->json($this->userResource($request->user()))
                : $this->forbidden();
        }

        $identifier = (string) ($request->input('user') ?: $request->input('username') ?: $request->input('email'));
        $password = (string) $request->input('pass', $request->input('password', ''));

        if ($identifier === '' || $password === '') {
            return $this->forbidden();
        }

        $escaped = preg_quote($identifier, '/');
        $user = User::whereRaw([
            '$or' => [
                ['name' => new Regex('^'.$escaped.'$', 'i')],
                ['email' => new Regex('^'.$escaped.'$', 'i')],
            ],
        ])->first();

        if (! $user || ! Hash::check($password, $user->password)) {
            return $this->forbidden();
        }

        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();
        $request->session()->put('octoprint.password_authenticated_at', now()->timestamp);
        $user->getSessionHash();

        $printer = Printer::whereNotNull('machine.uuid')->first();

        if ($printer) {
            $this->context->selectPrinter($request, data_get($printer, 'machine.uuid'));
            $user->setActivePrinterId((string) $printer->_id);
        }

        return response()->json($this->userResource($user));
    }

    public function logout(Request $request): Response
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response('', Response::HTTP_NO_CONTENT);
    }

    public function currentUser(Request $request): JsonResponse
    {
        return response()->json($this->userResource($request->user()));
    }

    public function createApiKey(Request $request, string $username): JsonResponse
    {
        $user = $this->managedUser($request, $username);

        if ($request->user()->currentAccessToken()) {
            return $this->forbidden('A password-authenticated session is required to regenerate an API key.');
        }

        $printer = $this->context->printer($request);
        $uuid = data_get($printer, 'machine.uuid');

        $user->tokens()
            ->where('kind', ApiTokenService::KIND)
            ->where('name', self::PERSONAL_KEY_NAME)
            ->where('printer_uuid', $uuid)
            ->delete();

        $newToken = $this->tokens->create($user, self::PERSONAL_KEY_NAME, $uuid, null);

        return response()->json(['apikey' => $newToken->plainTextToken]);
    }

    public function deleteApiKey(Request $request, string $username): Response
    {
        $user = $this->managedUser($request, $username);

        if ($request->user()->currentAccessToken()) {
            return $this->forbidden('A password-authenticated session is required to revoke an API key.');
        }

        $printer = $this->context->printer($request);

        $user->tokens()
            ->where('kind', ApiTokenService::KIND)
            ->where('name', self::PERSONAL_KEY_NAME)
            ->where('printer_uuid', data_get($printer, 'machine.uuid'))
            ->delete();

        return response('', Response::HTTP_NO_CONTENT);
    }

    public function userResource(User $user): array
    {
        $spectator = (int) $user->role === UserRole::SPECTATOR;
        $administrator = (int) $user->role === UserRole::ADMINISTRATOR;
        $permissions = [
            'STATUS' => true,
            'CONNECTION' => true,
            'FILES_LIST' => true,
            'FILES_UPLOAD' => ! $spectator,
            'FILES_DELETE' => ! $spectator,
            'PRINT' => ! $spectator,
            'CONTROL' => ! $spectator,
        ];

        return [
            'name' => $user->name,
            'active' => true,
            'admin' => $administrator,
            'user' => ! $spectator,
            'groups' => [$administrator ? 'admins' : ($spectator ? 'readonly' : 'users')],
            'permissions' => $permissions,
        ];
    }

    private function managedUser(Request $request, string $username): User
    {
        $operator = $request->user();

        if (strcasecmp($operator->name, $username) === 0) {
            return $operator;
        }

        if ((int) $operator->role !== UserRole::ADMINISTRATOR) {
            abort(403, 'You cannot manage this user API key.');
        }

        $escaped = preg_quote($username, '/');
        $user = User::where('name', new Regex('^'.$escaped.'$', 'i'))->first();

        if (! $user) {
            abort(404, 'User not found.');
        }

        return $user;
    }

    private function forbidden(string $message = 'Invalid credentials.'): JsonResponse
    {
        return response()->json(['error' => $message], 403);
    }
}
