<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Raw key/value storage; read settings through App\Support\Settings. */
class Setting extends Model
{
    protected $fillable = ['key', 'value'];
}
