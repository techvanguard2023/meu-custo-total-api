<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Tour do app (react-joyride), cadastrado na área admin. Ver App\Services\Tours. */
class Tour extends Model
{
    public const PLANS = ['free', 'essential', 'pro'];

    public const PLACEMENTS = ['auto', 'top', 'bottom', 'left', 'right', 'center'];

    protected $fillable = ['slug', 'title', 'plan', 'version', 'route', 'steps', 'preview_title', 'preview_content', 'active', 'sort_order'];

    protected function casts(): array
    {
        return [
            'steps' => 'array',
            'version' => 'integer',
            'active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
