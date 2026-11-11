<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Tour do app já visto por uma pessoa (ver App\Services\Tours). */
class UserTour extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_id', 'tour_id', 'version', 'kind', 'seen_at'];

    protected function casts(): array
    {
        return ['seen_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
