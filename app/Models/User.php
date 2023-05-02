<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;

use App\Contracts\Auth\AuthenticatableUser;

use Illuminate\Database\Eloquent\Factories\HasFactory;

use Illuminate\Foundation\Auth\User as Authenticatable;

use Illuminate\Notifications\Notifiable;

use Illuminate\Support\Facades\Cache;

use Laravel\Sanctum\HasApiTokens;

class User extends AuthenticatableUser
{
    use HasApiTokens, HasFactory, Notifiable;

    const CACHE_CURRENT_DIRECTORY_SUFFIX = '_cdir';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'settings',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
    ];

    public function getCurrentFolder() {
        return Cache::get(
            key:     session()->getId() . self::CACHE_CURRENT_DIRECTORY_SUFFIX,
            default: env('BASE_FILES_DIR')
        );
    }

    public function setCurrentFolder($path) {
        if ($path === null) {
            return Cache::forget( session()->getId() . self::CACHE_CURRENT_DIRECTORY_SUFFIX );
        }

        return Cache::put(
            key:     session()->getId() . self::CACHE_CURRENT_DIRECTORY_SUFFIX,
            value:   env('BASE_FILES_DIR') . $path
        );
    }
}
