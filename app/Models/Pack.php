<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A product suite (e.g. GestCom): a customer may pick at most one product (tier) per pack. */
class Pack extends Model
{
    protected $fillable = ['name', 'code', 'description'];

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
