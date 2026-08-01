<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Preorder extends Model
{
    use HasFactory;

    protected $fillable = ['fullname', 'email', 'phone', 'product_version', 'early_access', 'status', 'notes'];

    protected $casts = [
        'early_access' => 'boolean',
    ];
}
