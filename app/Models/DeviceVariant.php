<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

use MongoDB\Laravel\Eloquent\Model;

class DeviceVariant extends Model
{
    use HasFactory;

    protected $fillable = [
        'brand',
        'publicName',
        'codename',
        'model'
    ];
}
