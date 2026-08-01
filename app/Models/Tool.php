<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Tool extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'slug', 'type', 'version', 'description', 'download_url',
        'file_size', 'sha256', 'changelog', 'is_active', 'is_latest',
        'download_count', 'released_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_latest' => 'boolean',
        'download_count' => 'integer',
        'released_at' => 'datetime',
    ];
}
