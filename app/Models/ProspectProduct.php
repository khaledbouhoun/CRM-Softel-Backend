<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class ProspectProduct extends Pivot
{
    protected $table = 'prospect_products';

    public $incrementing = true;
}
