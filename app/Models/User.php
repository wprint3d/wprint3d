<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;

use App\Contracts\Auth\AuthenticatableUser;

use Illuminate\Database\Eloquent\Factories\HasFactory;

use Illuminate\Notifications\Notifiable;

use Illuminate\Support\Facades\Cache;

use Laravel\Sanctum\HasApiTokens;

class User extends AuthenticatableUser
{
    use HasApiTokens, HasFactory, Notifiable;

    const CACHE_CURRENT_DIRECTORY_SUFFIX = '_cdir';
    const CACHE_ACTIVE_PRINTER_SUFFIX    = '_aprinter';

    const HASH_KEY                       = '_uhash';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
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

    public function getActivePrinter() {
        return Cache::get(
            session()->getId() . self::CACHE_ACTIVE_PRINTER_SUFFIX
        );
    }
    
    /**
     * setActivePrinter
     *
     * @param  ?string $printerId
     * 
     * @return  bool Whether the user successfully set their printer
     */
    public function setActivePrinter(string $printerId): bool {
        if ($printerId === null) {
            return true;
        }

        /*
         * The user is trying to select a deleted printer, this doesn't make
         * sense, so we're gonna invalidate their session and instruct all
         * modules willing to handle the request to execute a redirection to
         * /login.
         */
        if (!Printer::find( $printerId )) {
            session()->invalidate();

            return false;
        }

        return Cache::put(
            key:     session()->getId() . self::CACHE_ACTIVE_PRINTER_SUFFIX,
            value:   $printerId
        );
    }

    /**
     * refreshHash
     *
     * @return string The generated hash
     */
    public function refreshHash() {
        $hash = sha1(
            serialize(
                $this->toArray()
            )
        );

        Cache::put(
            key:    (string) $this->_id . self::HASH_KEY,
            value:  $hash
        );

        return $hash;
    }

    /**
     * getCachedHash
     *
     * @return string
     */
    public function getCachedHash() {
        $hash = Cache::get(
            (string) $this->_id . self::HASH_KEY
        );

        if (!$hash) {
            return $this->refreshHash();
        }

        return $hash;
    }
    
    /**
     * getSessionHash
     * 
     * Get the hash related to this user as stored in the session.
     *
     * @return string
     */
    public function getSessionHash() {
        $hash = session()->get(
            (string) $this->_id . self::HASH_KEY
        );

        if (!$hash) {
            $hash = $this->getCachedHash();

            session()->put(
                key:    (string) $this->_id . self::HASH_KEY,
                value:  $hash
            );

            return $hash;
        }

        return $hash;
    }

    public function materials() {
        return $this->hasMany( Material::class );
    }
}
