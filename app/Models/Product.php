<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'category',
        'sku',
        'status',
        'stock',
        'image',
        'description',
    ];

    public function detections(): HasMany
    {
        return $this->hasMany(Detection::class);
    }
}
