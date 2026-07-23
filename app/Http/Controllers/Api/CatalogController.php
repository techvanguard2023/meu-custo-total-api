<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\EnforcesPlanLimits;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Configuração do catálogo público (recurso Pro) — liga/desliga e gera o
 * token compartilhável. A exibição do catálogo em si é feita pelo
 * PublicCatalogController, sem autenticação.
 */
class CatalogController extends Controller
{
    use EnforcesPlanLimits;

    public function show(Request $request)
    {
        $company = $request->user()->company;

        return response()->json([
            'enabled' => $company->catalog_enabled,
            'token' => $company->catalog_token,
        ]);
    }

    public function update(Request $request)
    {
        $this->requirePro($request, 'Catálogo público');

        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        $company = $request->user()->company;

        if ($data['enabled'] && ! $company->catalog_token) {
            $company->catalog_token = $this->generateToken();
        }

        $company->catalog_enabled = $data['enabled'];
        $company->save();

        return response()->json([
            'enabled' => $company->catalog_enabled,
            'token' => $company->catalog_token,
        ]);
    }

    /** Gera um novo link, invalidando o anterior (útil se o link vazou). */
    public function regenerate(Request $request)
    {
        $this->requirePro($request, 'Catálogo público');

        $company = $request->user()->company;
        $company->update(['catalog_token' => $this->generateToken()]);

        return response()->json([
            'enabled' => $company->catalog_enabled,
            'token' => $company->catalog_token,
        ]);
    }

    private function generateToken(): string
    {
        return Str::random(32);
    }
}
