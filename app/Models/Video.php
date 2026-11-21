<?php

namespace App\Models;

use App\Models\Concerns\SerializesLegacyMongoId;

use Illuminate\Database\Eloquent\Factories\HasFactory;

use MongoDB\Laravel\Eloquent\Model;
use MongoDB\Laravel\Relations\BelongsTo;

class Video extends Model
{
    use HasFactory, SerializesLegacyMongoId;

    public function owner(): BelongsTo {
        return $this->belongsTo(User::class);
    }

    public function printer(): BelongsTo {
        return $this->belongsTo(Printer::class);
    }
}
