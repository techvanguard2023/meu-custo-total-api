<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\EnforcesPlanLimits;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\MelhorEnvio;
use App\Services\MelhorEnvioAccounts;
use App\Services\ShippingQuotes;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Integrações → Melhor Envio (Essencial e Pro, só o dono): status, "Conectar com Melhor Envio" (OAuth), regras do
 * frete e uma simulação. A volta do Melhor Envio (callback) é pública e redireciona para o app com o resultado.
 * No catálogo, o cliente cota o frete em POST /public/catalog/{token}/shipping-quote.
 */
class MelhorEnvioController extends Controller
{
    use EnforcesPlanLimits;

    public function __construct(private MelhorEnvioAccounts $accounts, private ShippingQuotes $quotes) {}

    public function show(Request $request)
    {
        $company = $request->user()->company;
        $account = $this->accounts->account($company);
        $test = (bool) config('services.melhorenvio.test_access_token') && ! app()->isProduction();

        return response()->json([
            'available' => MelhorEnvio::configured() || $test,
            'essential' => $company->hasEssential(),
            'test_mode' => $test,
            'sandbox' => (bool) config('services.melhorenvio.sandbox'),
            'connected' => $this->accounts->ready($company),
            'rules' => ShippingQuotes::rules($company),
            'account' => $account ? [
                'connected_at' => $account->connected_at?->toIso8601String(),
                'revoked' => ! $account->isActive(),
                'error' => $account->revoked_at ? 'O acesso foi revogado ou venceu. Conecte de novo.' : null,
            ] : null,
        ]);
    }

    public function connect(Request $request)
    {
        $this->requireEssential($request, 'Frete pelo Melhor Envio');
        try {
            return response()->json(['url' => $this->accounts->connectUrl($request->user()->company, $request->user()->id)]);
        } catch (RuntimeException $e) {
            abort(422, $e->getMessage());
        }
    }

    public function disconnect(Request $request)
    {
        $this->accounts->disconnect($request->user()->company);

        return response()->json(['connected' => false]);
    }

    public function updateRules(Request $request)
    {
        $this->requireEssential($request, 'Frete pelo Melhor Envio');
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'origin_cep' => ['nullable', 'string', 'max:12'],
            'weight_g' => ['required', 'integer', 'min:1', 'max:30000'],
            'length_cm' => ['required', 'integer', 'min:1', 'max:105'],
            'width_cm' => ['required', 'integer', 'min:1', 'max:105'],
            'height_cm' => ['required', 'integer', 'min:1', 'max:105'],
            'handling_days' => ['required', 'integer', 'min:0', 'max:30'],
        ], ['origin_cep.max' => 'CEP inválido.']);

        $origin = ShippingQuotes::cleanCep($data['origin_cep'] ?? null);
        if (! empty($data['origin_cep']) && ! $origin) {
            abort(422, 'Informe o CEP de origem com 8 números.');
        }
        if ($data['enabled'] && ! $origin) {
            abort(422, 'Informe o CEP de origem para ligar o frete.');
        }

        $company = $request->user()->company;
        $company->setting()->updateOrCreate([], ['shipping_rules' => [
            'enabled' => (bool) $data['enabled'],
            'origin_cep' => $origin,
            'weight_g' => (int) $data['weight_g'],
            'length_cm' => (int) $data['length_cm'],
            'width_cm' => (int) $data['width_cm'],
            'height_cm' => (int) $data['height_cm'],
            'handling_days' => (int) $data['handling_days'],
        ]]);
        return response()->json(ShippingQuotes::rules($company->fresh('setting')));
    }

    /** Simulação do lojista: CEP de destino e os produtos do pedido. */
    public function simulate(Request $request)
    {
        $this->requireEssential($request, 'Frete pelo Melhor Envio');
        $data = $request->validate([
            'cep' => ['required', 'string', 'max:12'],
            'items' => ['required', 'array', 'min:1', 'max:30'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:9999'],
        ]);

        return $this->respond($request->user()->company, $data['cep'], $data['items']);
    }

    /** POST /public/catalog/{token}/shipping-quote — o cliente cota o frete do carrinho pelo CEP. */
    public function publicQuote(Request $request, string $token)
    {
        $company = Company::active()->where(fn ($q) => $q->where('slug', $token)->orWhere('catalog_token', $token))->first();
        abort_unless($company && $company->hasCatalogActive(), 404, 'Catálogo não encontrado.');
        abort_unless($this->quotes->available($company), 404, 'Esta loja não calcula frete pelo catálogo.');

        $data = $request->validate([
            'cep' => ['required', 'string', 'max:12'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:9999'],
        ]);

        return $this->respond($company, $data['cep'], $data['items']);
    }

    private function respond(Company $company, string $cep, array $items)
    {
        try {
            if ($items === []) {
                throw new RuntimeException('Escolha ao menos um produto para simular.');
            }
            $options = $this->quotes->quote($company, $cep, $items);
        } catch (RuntimeException $e) {
            abort(422, $e->getMessage());
        }

        return response()->json(['options' => $options]);
    }

    /** GET /melhorenvio/callback?code=...&state=... (o navegador do lojista volta do Melhor Envio). */
    public function callback(Request $request)
    {
        $target = rtrim((string) config('services.frontend_url'), '/').'/integrations';
        try {
            $this->accounts->finish($request->query('state'), $request->query('code'));

            return redirect()->away($target.'?melhorenvio=conectado#melhorenvio');
        } catch (RuntimeException $e) {
            return redirect()->away($target.'?'.http_build_query(['melhorenvio' => 'erro', 'motivo' => $e->getMessage()]).'#melhorenvio');
        }
    }
}
