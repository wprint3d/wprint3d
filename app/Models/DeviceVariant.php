<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

use Jenssegers\Mongodb\Eloquent\Model;

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
