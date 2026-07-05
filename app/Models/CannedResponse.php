<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CannedResponse extends Model
{
    use HasFactory;

    protected $fillable = ['title', 'body', 'tags'];

    protected function casts(): array
    {
        return ['tags' => 'array'];
    }
}
