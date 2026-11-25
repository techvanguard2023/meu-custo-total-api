<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Uma leitura do QR (scan) ou abertura do link curto (link). */
class QrCodeEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['company_id', 'qr_code_id', 'kind', 'amount', 'visitor_hash', 'device', 'city', 'region', 'created_at'];
}
