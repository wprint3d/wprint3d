<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

use Jenssegers\Mongodb\Eloquent\Builder;
use Jenssegers\Mongodb\Eloquent\Model;

class Camera extends Model
{
    use HasFactory;

    protected $fillable = [
        'connected',
        'enabled',
        'node',
        'label',
        'mode',
        'format',
        'availableFormats',
        'requiresLibCamera'
    ];

    public function scopeConnected(Builder $query): void {
        $query->where('connected', true);
    }

    public function getSnapshotURL() {
        if (!$this->connected || !$this->url) return null;

        return $this->url . '?action=snapshot';
    }
}
