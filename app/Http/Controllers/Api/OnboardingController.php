<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/** Primeiros passos do Dashboard: o que a empresa já configurou (cada passo se marca sozinho). */
class OnboardingController extends Controller
{
    public function show(Request $request)
    {
        $company = $request->user()->company;
        $setting = $company->setting;

        return response()->json([
            // Mão de obra ou margem preenchidas = o dono já passou pelos parâmetros de custo
            'costs' => $setting && ((float) $setting->labor_hour_rate > 0 || (float) $setting->default_markup > 0),
            'materials' => $company->materials()->exists(),
            'printers' => $company->printers()->exists(),
            'products' => $company->products()->exists(),
            'quotes' => $company->quotes()->exists(),
            'catalog' => (bool) $company->catalog_enabled,
            'whatsapp' => $company->whatsapp_session_name !== null,
        ]);
    }
}
