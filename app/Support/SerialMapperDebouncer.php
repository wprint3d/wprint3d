<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class SerialMapperDebouncer
{
    private const TOKEN_TTL_SECS = 600;

    private Closure $sleep;

    public function __construct(?Closure $sleep = null)
    {
        $this->sleep = $sleep ?? static fn (int $seconds) => sleep($seconds);
    }

    public function wait(int $seconds): array
    {
        if ($seconds === 0) {
            return [true, null];
        }

        $token = (string) Str::uuid();

        Cache::put(
            key: config('cache.serial_mapper_debounce_key'),
            value: $token,
            ttl: self::TOKEN_TTL_SECS
        );

        ($this->sleep)($seconds);

        return [$this->isCurrent($token), $token];
    }

    public function isCurrent(?string $token): bool
    {
        return $token === null
            || Cache::get(config('cache.serial_mapper_debounce_key')) === $token;
    }
}
