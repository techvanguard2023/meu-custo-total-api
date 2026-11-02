<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Uma oferta divulgada (um produto), com uma entrega por grupo/canal. */
class OfferPost extends Model
{
    protected $fillable = ['company_id', 'product_id', 'product_name', 'origin'];

    public function deliveries(): HasMany
    {
        return $this->hasMany(OfferDelivery::class);
    }
}
