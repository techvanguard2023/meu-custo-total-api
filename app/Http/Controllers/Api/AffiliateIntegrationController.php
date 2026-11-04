<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\EnforcesPlanLimits;
use App\Http\Controllers\Controller;
use App\Models\AffiliateAccount;
use App\Models\AffiliateOffer;
use App\Services\AffiliateLinkReader;
use App\Services\ShopeeAffiliate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/**
 * Achadinhos → contas de afiliado com API. Só a Shopee tem API oficial pra afiliados: conectar (AppId e Secret
 * da própria pessoa), buscar produtos com comissão, adicionar como achadinho já com o link de afiliado e ver as
 * comissões. Mercado Livre não tem API de afiliado; a da Amazon (Creators API) exige 10 vendas nos últimos 30 dias.
 */
class AffiliateIntegrationController extends Controller
{
    use EnforcesPlanLimits;

    private const FEATURE = 'Achadinhos (links de afiliado)';

    private const MAX_IMPORT = 20;

    public function show(Request $request)
    {
        $this->requirePro($request, self::FEATURE);

        return response()->json(['shopee' => $this->accountPayload(AffiliateAccount::shopeeFor($request->user()->company_id))]);
    }

    /** Conecta (ou troca) a conta da Shopee: só salva se a Shopee aceitar as credenciais. */
    public function connectShopee(Request $request)
    {
        $this->requirePro($request, self::FEATURE);
        $data = $request->validate([
            'app_id' => ['required', 'string', 'max:64', 'regex:/^\d+$/'],
            'secret' => ['required', 'string', 'max:255'],
        ], [
            'app_id.required' => 'Informe o AppId.',
            'app_id.regex' => 'O AppId da Shopee é só número.',
            'secret.required' => 'Informe o Secret.',
        ]);

        $account = AffiliateAccount::firstOrNew(['company_id' => $request->user()->company_id, 'provider' => AffiliateAccount::PROVIDER_SHOPEE]);
        $account->fill(['app_id' => trim($data['app_id']), 'secret' => trim($data['secret'])]);

        ShopeeAffiliate::for($account)->verify(); // erro → 422 com a mensagem da Shopee em português

        $account->fill(['verified_at' => now(), 'last_error' => null])->save();
        $this->forgetCommissions($account->company_id);

        return response()->json(['shopee' => $this->accountPayload($account)]);
    }

    public function disconnectShopee(Request $request)
    {
        $this->requirePro($request, self::FEATURE);
        AffiliateAccount::where('company_id', $request->user()->company_id)->where('provider', AffiliateAccount::PROVIDER_SHOPEE)->delete();
        $this->forgetCommissions($request->user()->company_id);

        return response()->noContent();
    }

    /** Busca na Shopee (com a % de comissão), marcando o que já foi cadastrado como achadinho. */
    public function searchShopee(Request $request)
    {
        $this->requirePro($request, self::FEATURE);
        $data = $request->validate([
            'keyword' => ['required', 'string', 'min:2', 'max:100'],
            'sort' => ['nullable', 'in:'.implode(',', array_keys(ShopeeAffiliate::SORTS))],
            'page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ], ['keyword.required' => 'Escreva o que procurar.', 'keyword.min' => 'Escreva pelo menos 2 letras.']);

        $result = $this->client($request)->search($data['keyword'], $data['sort'] ?? 'relevance', (int) ($data['page'] ?? 1));

        $keys = array_map(fn ($i) => "shopee:{$i['shop_id']}-{$i['item_id']}", $result['items']);
        $existing = AffiliateOffer::where('company_id', $request->user()->company_id)->whereIn('product_key', $keys)->pluck('id', 'product_key');

        return response()->json([
            'items' => array_map(fn ($i) => $i + ['existing_id' => $existing["shopee:{$i['shop_id']}-{$i['item_id']}"] ?? null], $result['items']),
            'has_next' => $result['has_next'],
        ]);
    }

    /**
     * Adiciona os escolhidos na busca como achadinhos: gera o link curto de afiliado de cada um (com o sub id dos
     * grupos, pra separar as comissões) e baixa a foto. Preço e comissão vêm da busca.
     */
    public function importShopee(Request $request, AffiliateLinkReader $reader)
    {
        $this->requirePro($request, self::FEATURE);
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:'.self::MAX_IMPORT],
            'items.*.item_id' => ['required', 'regex:/^\d+$/'],
            'items.*.shop_id' => ['required', 'regex:/^\d+$/'],
            'items.*.title' => ['required', 'string', 'min:3', 'max:200'],
            'items.*.product_url' => ['required', 'string', 'max:2000', 'regex:~^https://([a-z0-9-]+\.)*shopee\.com\.br/~i'],
            'items.*.offer_url' => ['nullable', 'string', 'max:2000', 'regex:~^https://~i'],
            'items.*.image_url' => ['nullable', 'string', 'max:2000'],
            'items.*.price' => ['nullable', 'numeric', 'min:0'],
            'items.*.original_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.commission_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ], ['items.max' => 'No máximo '.self::MAX_IMPORT.' produtos de uma vez.']);

        $client = $this->client($request);
        $companyId = $request->user()->company_id;
        $created = 0;
        $warnings = [];

        foreach ($data['items'] as $item) {
            try {
                $url = $client->shortLink($item['product_url']);
            } catch (ValidationException $e) {
                // Sem o link curto, usa o link de oferta que a busca já trouxe (também é de afiliado)
                $url = $item['offer_url'] ?? null;
                if (! $url) {
                    $warnings[] = "{$item['title']}: ".collect($e->errors())->flatten()->first();

                    continue;
                }
            }

            $offer = new AffiliateOffer([
                'company_id' => $companyId,
                'marketplace' => 'shopee',
                'url' => $url,
                'source_url' => $item['product_url'],
                'product_key' => "shopee:{$item['shop_id']}-{$item['item_id']}",
                'title' => $item['title'],
                'price' => $item['price'] ?? null,
                'original_price' => $item['original_price'] ?? null,
                'commission_rate' => $item['commission_rate'] ?? null,
                'price_checked_at' => now(),
                'active' => true,
            ]);
            if (! empty($item['image_url'])) {
                $offer->image_path = $reader->downloadImage($item['image_url'], $companyId);
            }
            $offer->save();
            $created++;
        }

        return response()->json(['created' => $created, 'warnings' => $warnings], $created ? 201 : 422);
    }

    /** Comissões da Shopee nos últimos N dias (guardado 15 min: a Shopee limita as consultas do relatório). */
    public function shopeeCommissions(Request $request)
    {
        $this->requirePro($request, self::FEATURE);
        $request->validate(['days' => ['nullable', 'integer', 'in:7,30,90']]);
        $days = (int) ($request->input('days') ?: 30);
        $client = $this->client($request);
        $companyId = $request->user()->company_id;

        $summary = Cache::remember($this->commissionsKey($companyId, $days), now()->addMinutes(15), fn () => [
            ...$client->commissions(now()->subDays($days)->startOfDay()->timestamp, now()->timestamp),
            'checked_at' => now()->toIso8601String(),
        ]);

        return response()->json(['days' => $days, ...$summary]);
    }

    private function client(Request $request): ShopeeAffiliate
    {
        $account = AffiliateAccount::shopeeFor($request->user()->company_id);
        abort_unless($account?->verified_at, 422, 'Conecte sua conta de afiliado da Shopee primeiro.');

        return ShopeeAffiliate::for($account);
    }

    private function commissionsKey(int $companyId, int $days = 30): string
    {
        return "shopee-commissions:{$companyId}:{$days}";
    }

    private function forgetCommissions(int $companyId): void
    {
        foreach ([7, 30, 90] as $days) {
            Cache::forget($this->commissionsKey($companyId, $days));
        }
    }

    private function accountPayload(?AffiliateAccount $account): ?array
    {
        return $account ? [
            'app_id' => $account->app_id,
            'connected' => $account->verified_at !== null,
            'verified_at' => $account->verified_at?->toIso8601String(),
            'last_error' => $account->last_error,
        ] : null;
    }
}
