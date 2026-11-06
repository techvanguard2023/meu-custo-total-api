<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Referrals;
use Illuminate\Http\Request;

/** Indique e ganhe (só o dono): link, lojas indicadas e recompensas. */
class ReferralController extends Controller
{
    public function show(Request $request, Referrals $referrals)
    {
        return $referrals->summary($request->user()->company);
    }
}
