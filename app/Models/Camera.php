<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

use Jenssegers\Mongodb\Eloquent\Model;

class Camera extends Model
{
    use HasFactory;

    protected $fillable = [
        'node',
        'label',
        'mode',
        'format',
        'availableFormats',
        'requiresLibCamera'
    ];
}
