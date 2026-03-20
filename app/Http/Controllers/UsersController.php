<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;

use App\Models\User;

use Illuminate\Database\Eloquent\Collection;

use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Martbock\Diceware\Facades\Diceware;
use MongoDB\BSON\ObjectId;

class UsersController extends Controller
{

    private ?User $me;

    public function __construct() {
        $this->middleware(function (Request $request, $next) {
            $this->me = auth()->user();

            return $next($request);
        });
    }

    public function get(string $userId): User {
        if (!$this->me->role === UserRole::ADMINISTRATOR) {
            throw ValidationException::withMessages([ 'role' => __('server.users.permission_denied') ]);
        }

        $user = User::find($userId);

        if (!$user) {
            throw ValidationException::withMessages([ 'userId' => __('server.users.not_found') ]);
        }

        return $user;
    }

    public function create(Request $request): array {
        $request->validate([
            'username'  => 'required|string',
            'email'     => 'required|email',
            'role'      => 'required|integer|in:'.implode(',', UserRole::asArray()),
        ]);

        if (!$this->me->role === UserRole::ADMINISTRATOR) {
            throw ValidationException::withMessages([ 'role' => __('server.users.permission_denied') ]);
        }

        $name     = $request->get('username');
        $email    = $request->get('email');
        $role     = (int) $request->get('role');
        $password = Diceware::generate();

        if (User::where('name', $name)->exists()) {
            throw ValidationException::withMessages([ 'username' => __('server.users.name_in_use') ]);
        }

        if (User::where('email', $email)->exists()) {
            throw ValidationException::withMessages([ 'email' => __('server.users.email_in_use') ]);
        }

        $user = new User();

        $user->name     = $name;
        $user->email    = $email;
        $user->role     = $role;
        $user->password = Hash::make($password);
        $user->save();

        return [
            'user'     => $user,
            'password' => $password,
        ];
    }

    public function update(Request $request, string $userId): User {
        $request->validate([
            'username'  => 'required|string',
            'email'     => 'required|email',
            'role'      => 'required|integer|in:'.implode(',', UserRole::asArray()),
        ]);

        if (!$this->me->role === UserRole::ADMINISTRATOR) {
            throw ValidationException::withMessages([ 'role' => __('server.users.permission_denied') ]);
        }

        if (!User::find($userId)) {
            throw ValidationException::withMessages([ 'userId' => __('server.users.not_found') ]);
        }

        $userId = new ObjectId($userId);

        $nextUsername = $request->get('username');
        $nextEmail    = $request->get('email');
        $nextRole     = $request->get('role');

        if (User::where('name', $nextUsername)->where('_id', '!=', $userId)->exists()) {
            throw ValidationException::withMessages([ 'name' => __('server.users.name_in_use') ]);
        }

        if (User::where('email', $nextEmail)->where('_id', '!=', $userId)->exists()) {
            throw ValidationException::withMessages([ 'email' => __('server.users.email_in_use') ]);
        }

        $user = User::find($userId);

        if (($user->deletable ?? true) === false && $nextRole !== $user->role) {
            throw ValidationException::withMessages([ 'role' => __('server.users.role_change_forbidden') ]);
        }

        $user->name  = $nextUsername;
        $user->email = $nextEmail;
        $user->role  = (int) $nextRole;
        $user->save();

        return $user;
    }

    public function resetPassword(Request $request, string $userId): array {
        if (!$this->me->role === UserRole::ADMINISTRATOR) {
            throw ValidationException::withMessages([ 'role' => __('server.users.permission_denied') ]);
        }

        if (!User::find($userId)) {
            throw ValidationException::withMessages([ 'userId' => __('server.users.not_found') ]);
        }

        $password = Diceware::generate();

        $user = User::find($userId);

        $user->password = Hash::make($password);
        $user->save();

        return [
            'user'     => $user,
            'password' => $password
        ];
    }

    public function delete(string $userId): void {
        if (!$this->me->role === UserRole::ADMINISTRATOR) {
            throw ValidationException::withMessages([ 'role' => __('server.users.permission_denied') ]);
        }

        if (!User::find($userId)) {
            throw ValidationException::withMessages([ 'userId' => __('server.users.not_found') ]);
        }

        $user = User::find($userId);

        if (($user->deletable ?? true) === false) {
            throw ValidationException::withMessages([ 'userId' => __('server.users.delete_forbidden') ]);
        }

        $user->delete();
    }

    public function index(): Collection {
        if (!$this->me->role === UserRole::ADMINISTRATOR) {
            throw ValidationException::withMessages([ 'role' => __('server.users.permission_denied') ]);
        }

        return User::all();
    }

}
