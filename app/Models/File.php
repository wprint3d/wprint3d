<?php

namespace App\Models;

use App\Models\Concerns\SerializesLegacyMongoId;

use Illuminate\Database\Eloquent\Factories\HasFactory;

use MongoDB\Laravel\Eloquent\Model;

class File extends Model
{
    use HasFactory, SerializesLegacyMongoId;
}
