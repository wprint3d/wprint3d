<?php

namespace App\Models;

use App\Models\Concerns\SerializesLegacyMongoId;

use Illuminate\Database\Eloquent\Factories\HasFactory;

use MongoDB\Laravel\Eloquent\Model;

class Meta extends Model
{
    use HasFactory, SerializesLegacyMongoId;

    public function scopePendingUpdate($query) {
        return $query->where('key', 'pending_update');
    }
    
}
