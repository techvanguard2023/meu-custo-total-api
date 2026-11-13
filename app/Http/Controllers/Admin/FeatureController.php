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
        $data = $request->validate(['alexa_visible' => ['required', 'boolean']]);

        PlatformSetting::put(PlatformSetting::ALEXA_VISIBLE, (bool) $data['alexa_visible']);
        AdminAudit::log($request, 'features.alexa', null, null, ['visible' => (bool) $data['alexa_visible']]);

        return response()->json($this->payload());
    }

    private function payload(): array
    {
        return [
            'alexa_visible' => (bool) PlatformSetting::get(PlatformSetting::ALEXA_VISIBLE),
            // Sem o Client ID da ligação de conta no .env, o card não aparece nem ligado
            'alexa_configured' => (bool) config('services.alexa.client_id'),
        ];
    }
}
