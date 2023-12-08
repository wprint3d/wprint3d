<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

use MongoDB\Laravel\Eloquent\Builder;
use MongoDB\Laravel\Eloquent\Model;

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
