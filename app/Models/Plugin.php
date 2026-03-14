<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use MongoDB\Laravel\Eloquent\Model;

class Plugin extends Model
{
    use HasFactory;

    protected $fillable = [
        'plugin_id',
        'name',
        'description',
        'author',
        'current_version',
        'enabled',
        'trust_level',
        'install_source',
        'manifest',
        'permissions',
        'hooks',
        'actions',
        'ui_extensions',
        'versions',
        'warnings',
        'dependency_state',
        'last_error',
        'last_healthcheck_at',
    ];

    protected $casts = [
        'enabled' => 'boolean',
    ];

    public function scopeEnabled($query)
    {
        return $query->where('enabled', true);
    }

    public function getVersionRecord(?string $version = null): ?array
    {
        $version ??= $this->current_version;

        return $this->versions[$version] ?? null;
    }

    public function getCurrentRuntimePath(): ?string
    {
        return $this->getVersionRecord()['path'] ?? null;
    }
}
