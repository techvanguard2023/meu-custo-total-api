<?php

namespace App\Services;

use App\Models\AffiliateAccount;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * API oficial de afiliados da Shopee (GraphQL), com o AppId/Secret da própria pessoa (affiliate.shopee.com.br →
 * Open API). Assinatura: SHA256(AppId + Timestamp + corpo + Secret), em hexadecimal; o corpo assinado é exatamente
 * o enviado. Erros vêm com HTTP 200 e uma lista "errors" (10020 credencial, 10030 limite, 10035 sem acesso...).
 *
 * Testado contra o endpoint real em 2026-11-04 só com credencial falsa (erro 10035, no formato esperado) — a
 * consulta com credencial válida ainda precisa ser confirmada com uma conta de verdade.
 */
class ShopeeAffiliate
{
    public const ENDPOINT = 'https://open-api.affiliate.shopee.com.br/graphql';

    /** Sub id dos links gerados aqui: separa, no relatório de comissões, o que veio dos grupos de achadinhos. */
    public const SUB_ID = 'mctgrupos';

    /** Ordenação da busca (productOfferV2.sortType). */
    public const SORTS = ['relevance' => 1, 'sales' => 2, 'price_desc' => 3, 'price_asc' => 4, 'commission' => 5];

    private const OFFER_FIELDS = 'itemId productName productLink offerLink imageUrl shopId shopName commissionRate commission sales priceMin priceMax priceDiscountRate ratingStar';

    public function __construct(private AffiliateAccount $account) {}

    public static function for(AffiliateAccount $account): self
    {
        return new self($account);
    }

    /** Confere as credenciais com uma busca mínima. Lança ValidationException com a mensagem em português. */
    public function verify(): void
    {
        $this->query('{productOfferV2(keyword:"fone", limit:1){nodes{itemId}}}');
    }

    /**
     * Busca produtos com comissão.
     *
     * @return array{items: array<int, array>, has_next: bool}
     */
    public function search(string $keyword, string $sort = 'relevance', int $page = 1, int $limit = 20): array
    {
        $args = sprintf(
            'keyword:%s, sortType:%d, page:%d, limit:%d',
            json_encode($keyword, JSON_UNESCAPED_UNICODE),
            self::SORTS[$sort] ?? 1,
            max(1, $page),
            min(50, max(1, $limit)),
        );
        $data = $this->query('{productOfferV2('.$args.'){nodes{'.self::OFFER_FIELDS.'} pageInfo{hasNextPage}}}');

        return [
            'items' => array_map(fn (array $node) => $this->normalize($node), $data['productOfferV2']['nodes'] ?? []),
            'has_next' => (bool) ($data['productOfferV2']['pageInfo']['hasNextPage'] ?? false),
        ];
    }

    /** Dados atuais de um produto (loja + item), ou null se a Shopee não devolver a oferta. */
    public function item(int|string $shopId, int|string $itemId): ?array
    {
        $data = $this->query(sprintf('{productOfferV2(shopId:%d, itemId:%d, limit:1){nodes{%s}}}', (int) $shopId, (int) $itemId, self::OFFER_FIELDS));
        $node = $data['productOfferV2']['nodes'][0] ?? null;

        return $node ? $this->normalize($node) : null;
    }

    /** Link curto de afiliado da pessoa pra um endereço da Shopee (com o sub id dos grupos). */
    public function shortLink(string $originUrl): string
    {
        $data = $this->query(sprintf(
            'mutation{generateShortLink(input:{originUrl:%s, subIds:[%s]}){shortLink}}',
            json_encode($originUrl, JSON_UNESCAPED_SLASHES),
            json_encode(self::SUB_ID),
        ));
        $link = (string) ($data['generateShortLink']['shortLink'] ?? '');
        if (! str_starts_with($link, 'http')) {
            throw ValidationException::withMessages(['shopee' => 'A Shopee não devolveu o link de afiliado. Tente de novo.']);
        }

        return $link;
    }

    /**
     * Comissões dos pedidos feitos entre $from e $to (Unix): soma por situação, e quanto veio dos links gerados
     * aqui (sub id dos grupos). Total e "dos grupos" sem os cancelados (a Shopee não paga). Pagina pelo scrollId (vale 30 s) até $maxPages páginas de 500.
     */
    public function commissions(int $from, int $to, int $maxPages = 6): array
    {
        $summary = ['orders' => 0, 'total' => 0.0, 'from_groups' => 0.0, 'orders_from_groups' => 0, 'by_status' => [], 'truncated' => false];
        $scrollId = null;

        for ($page = 0; $page < $maxPages; $page++) {
            $args = sprintf('purchaseTimeStart:%d, purchaseTimeEnd:%d, limit:500', $from, $to).($scrollId ? ', scrollId:'.json_encode($scrollId) : '');
            $data = $this->query('{conversionReport('.$args.'){nodes{purchaseTime totalCommission utmContent orders{orderId orderStatus items{itemTotalCommission displayItemStatus}}} pageInfo{scrollId hasNextPage}}}');
            $report = $data['conversionReport'] ?? [];

            foreach ($report['nodes'] ?? [] as $conversion) {
                $fromGroups = str_contains((string) ($conversion['utmContent'] ?? ''), self::SUB_ID);
                foreach ($conversion['orders'] ?? [] as $order) {
                    $value = array_sum(array_map(fn ($i) => (float) ($i['itemTotalCommission'] ?? 0), $order['items'] ?? []));
                    $status = strtoupper((string) ($order['orderStatus'] ?? 'PENDING'));
                    $summary['by_status'][$status] = round(($summary['by_status'][$status] ?? 0) + $value, 2);
                    // Cancelado não é pago: aparece na situação, mas fica fora do total e dos pedidos
                    if ($status === 'CANCELLED') {
                        continue;
                    }
                    $summary['orders']++;
                    $summary['total'] += $value;
                    if ($fromGroups) {
                        $summary['from_groups'] += $value;
                        $summary['orders_from_groups']++;
                    }
                }
            }

            $scrollId = $report['pageInfo']['scrollId'] ?? null;
            if (! ($report['pageInfo']['hasNextPage'] ?? false) || ! $scrollId) {
                break;
            }
            if ($page === $maxPages - 1) {
                $summary['truncated'] = true;
            }
        }

        $summary['total'] = round($summary['total'], 2);
        $summary['from_groups'] = round($summary['from_groups'], 2);

        return $summary;
    }

    /** Preço, desconto e comissão no formato do achadinho. */
    private function normalize(array $n): array
    {
        $price = isset($n['priceMin']) ? round((float) $n['priceMin'], 2) : null;
        $discount = (int) ($n['priceDiscountRate'] ?? 0);
        $original = $price !== null && $discount > 0 && $discount < 100 ? round($price / (1 - $discount / 100), 2) : null;

        return [
            'item_id' => (string) ($n['itemId'] ?? ''),
            'shop_id' => (string) ($n['shopId'] ?? ''),
            'title' => Str::limit(trim((string) ($n['productName'] ?? '')), 200, ''),
            'image_url' => $n['imageUrl'] ?? null,
            'product_url' => $n['productLink'] ?? null,
            'offer_url' => $n['offerLink'] ?? null,
            'shop_name' => $n['shopName'] ?? null,
            'price' => $price,
            'price_max' => isset($n['priceMax']) ? round((float) $n['priceMax'], 2) : null,
            'original_price' => $original,
            'discount_percent' => $discount ?: null,
            // "0.25" = 25%
            'commission_rate' => isset($n['commissionRate']) ? round((float) $n['commissionRate'] * 100, 2) : null,
            'commission' => isset($n['commission']) ? round((float) $n['commission'], 2) : null,
            'sales' => (int) ($n['sales'] ?? 0),
            'rating' => isset($n['ratingStar']) ? round((float) $n['ratingStar'], 1) : null,
        ];
    }

    /** Monta, assina e envia; devolve "data" ou lança ValidationException com a mensagem em português. */
    private function query(string $graphql): array
    {
        $body = json_encode(['query' => $graphql], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $timestamp = time();
        $signature = hash('sha256', $this->account->app_id.$timestamp.$body.$this->account->secret);

        try {
            $response = Http::withHeaders([
                'Authorization' => "SHA256 Credential={$this->account->app_id}, Timestamp={$timestamp}, Signature={$signature}",
            ])->withBody($body, 'application/json')->timeout(20)->post(config('services.shopee_affiliate.endpoint') ?: self::ENDPOINT);
        } catch (Throwable $e) {
            Log::info('Shopee afiliados: sem resposta: '.Str::limit($e->getMessage(), 200));
            throw ValidationException::withMessages(['shopee' => 'A Shopee não respondeu agora. Tente de novo em instantes.']);
        }

        $json = $response->json() ?? [];
        if (! empty($json['errors'])) {
            $code = (int) ($json['errors'][0]['extensions']['code'] ?? 0);
            throw ValidationException::withMessages(['shopee' => self::errorMessage($code, (string) ($json['errors'][0]['message'] ?? ''))]);
        }
        if (! $response->successful() || ! isset($json['data'])) {
            throw ValidationException::withMessages(['shopee' => 'A Shopee devolveu uma resposta inesperada. Tente de novo.']);
        }

        return $json['data'];
    }

    public static function errorMessage(int $code, string $original = ''): string
    {
        return match (true) {
            $code === 10020 => 'AppId ou Secret da Shopee não conferem (ou o relógio do pedido expirou). Confira e salve de novo.',
            $code === 10030 => 'A Shopee limitou as consultas por agora. Espere alguns minutos e tente de novo.',
            $code === 10035 => 'Sua conta ainda não tem acesso à API de afiliados da Shopee. Peça em affiliate.shopee.com.br → Open API.',
            in_array($code, [10031, 10032, 10033, 10034], true) => 'A Shopee recusou o acesso desta conta de afiliado (conta bloqueada, congelada ou inválida).',
            in_array($code, [11000, 11001, 11002], true) => 'A Shopee recusou a consulta: '.Str::limit(preg_replace('/^error \[\d+\]:\s*/', '', $original), 150),
            default => 'A Shopee devolveu um erro'.($code ? " ({$code})" : '').'. Tente de novo em instantes.',
        };
    }
}
