<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Tours;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Tours do app: GET diz o que a pessoa ainda precisa ver (full / preview / nada); POST marca um como visto.
 * Vale para todo mundo da conta (dono e equipe): cada pessoa tem a sua marcação.
 */
class ToursController extends Controller
{
    public function index(Request $request)
    {
        return response()->json(['tours' => Tours::status($request->user())]);
    }

    public function seen(Request $request, string $tour)
    {
        $definition = Tours::definitions()[$tour] ?? abort(404, 'Tour não encontrado.');
        $data = $request->validate(['kind' => ['required', Rule::in([Tours::FULL, Tours::PREVIEW])]]);

        // Tour completo só conta quando a loja tem o plano; a prévia só quando não tem. Senão a marca errada
        // impediria o tour de aparecer na hora certa (ex.: prévia marcada com o plano já ativo).
        $eligible = Tours::eligible($request->user()->company, $definition['plan']);
        abort_unless(($data['kind'] === Tours::FULL) === $eligible, 422, 'Este tour não corresponde ao plano da loja.');

        Tours::markSeen($request->user(), $tour, $definition['version'], $data['kind']);

        return response()->json(['ok' => true]);
    }
}
