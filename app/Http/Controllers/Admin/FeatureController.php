<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PlatformSetting;
use App\Services\AdminAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Área admin → Sistema → Recursos: o que aparece (ou não) para os lojistas, sem deploy. */
class FeatureController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json($this->payload());
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'alexa_visible' => ['sometimes', 'boolean'],
            'mercadopago_visible' => ['sometimes', 'boolean'],
            'pagbank_visible' => ['sometimes', 'boolean'],
        ]);
        abort_if($data === [], 422, 'Nada para alterar.');

        // Uma chave por vez na tela; cada uma vira um registro na auditoria (features.alexa, features.pagbank...)
        foreach ($data as $key => $visible) {
            PlatformSetting::put($key, (bool) $visible);
            AdminAudit::log($request, 'features.'.str_replace('_visible', '', $key), null, null, ['visible' => (bool) $visible]);
        }

        return response()->json($this->payload());
    }

    private function payload(): array
    {
        return [
            'alexa_visible' => (bool) PlatformSetting::get(PlatformSetting::ALEXA_VISIBLE),
            // Sem o Client ID da ligação de conta no .env, o card não aparece nem ligado
            'alexa_configured' => (bool) config('services.alexa.client_id'),
            'mercadopago_visible' => (bool) PlatformSetting::get(PlatformSetting::MERCADOPAGO_VISIBLE),
            'pagbank_visible' => (bool) PlatformSetting::get(PlatformSetting::PAGBANK_VISIBLE),
        ];
    }
}
