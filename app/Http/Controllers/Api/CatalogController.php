<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\EnforcesPlanLimits;
use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
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
        return response()->json($this->payload($request->user()->company));
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

        return response()->json($this->payload($company));
    }

    /** Gera um novo link, invalidando o anterior (útil se o link vazou). */
    public function regenerate(Request $request)
    {
        $this->requirePro($request, 'Catálogo público');

        $company = $request->user()->company;
        $company->update(['catalog_token' => $this->generateToken()]);

        return response()->json($this->payload($company));
    }

    /** Logo exibida no cabeçalho do catálogo público — opcional. */
    public function uploadLogo(Request $request)
    {
        $this->requirePro($request, 'Catálogo público');

        $request->validate([
            'logo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        $company = $request->user()->company;

        if ($company->logo_path) {
            Storage::disk('public')->delete($company->logo_path);
        }

        $path = $request->file('logo')->store('logos', 'public');
        $company->update(['logo_path' => $path]);

        return response()->json($this->payload($company->fresh()));
    }

    public function destroyLogo(Request $request)
    {
        $company = $request->user()->company;

        if ($company->logo_path) {
            Storage::disk('public')->delete($company->logo_path);
            $company->update(['logo_path' => null]);
        }

        return response()->json($this->payload($company->fresh()));
    }

    private function payload(Company $company): array
    {
        return [
            'enabled' => $company->catalog_enabled,
            'token' => $company->catalog_token,
            'logo_url' => $company->logo_url,
        ];
    }

    private function generateToken(): string
    {
        return Str::random(32);
    }
}
