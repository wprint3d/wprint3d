<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

use MongoDB\Laravel\Eloquent\Model;

class Meta extends Model
{
    use HasFactory;

    public function scopePendingUpdate($query) {
        return $query->where('key', 'pending_update');
    }
    
}
