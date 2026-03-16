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
        'settings',
        'state',
        'logs',
        'load_status',
        'last_error',
        'load_error_at',
        'last_healthcheck_at',
        'automatic_update_enabled',
        'update_available',
        'latest_version',
        'last_update_checked_at',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'settings' => 'array',
        'state' => 'array',
        'logs' => 'array',
        'automatic_update_enabled' => 'boolean',
        'update_available' => 'boolean',
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
