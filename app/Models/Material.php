<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

use Jenssegers\Mongodb\Eloquent\Model;

class Material extends Model
{
    use HasFactory;

    protected $fillable = [ 'name', 'temperatures', 'temperatures.hotend', 'temperatures.bed' ];

    public function user() {
        return $this->belongsTo( User::class );
    }
}
