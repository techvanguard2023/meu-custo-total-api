<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function show(Request $request)
    {
        return $request->user()->company->setting;
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'electricity_rate_kwh' => ['required', 'numeric', 'min:0'],
            'labor_hour_rate' => ['required', 'numeric', 'min:0'],
            'default_failure_rate' => ['required', 'numeric', 'min:0'],
            'default_markup' => ['required', 'numeric', 'min:0'],
            'minimum_order_price' => ['required', 'numeric', 'min:0'],
        ]);

        $setting = $request->user()->company->setting;
        $setting->update($data);

        return $setting;
    }
}
