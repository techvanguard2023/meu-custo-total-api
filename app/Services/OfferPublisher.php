<?php

namespace App\Services;

use App\Models\AffiliateOffer;
use App\Models\Company;
use App\Models\Coupon;
use App\Models\OfferDelivery;
use App\Models\OfferPost;
use App\Models\OfferSeries;
use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Divulga produtos nos grupos e canais de ofertas. Cada oferta vira uma entrega por grupo/canal, e o
 * offers:send manda uma por minuto por loja — espaçado, pra não parecer disparo em massa pro WhatsApp.
 */
class OfferPublisher
{
    public function __construct(private WahaClient $waha) {}

    /**
     * Grupos (todos os #N) e canais das séries ativas do tipo pedido — pra onde cada oferta vai. Produto e cupom
     * da loja vão só pras séries "store"; achadinho (afiliado) só pras "affiliate": cliente da loja não recebe
     * link da Shopee, e o grupo de achadinhos não recebe o catálogo.
     */
    public function targets(Company $company, string $purpose = OfferSeries::PURPOSE_STORE): \Illuminate\Support\Collection
    {
        $series = OfferSeries::where('company_id', $company->id)->where('active', true)->where('purpose', $purpose)->with('groups')->get();

        return $series->flatMap(fn (OfferSeries $s) => $s->isChannel()
            ? [['series' => $s, 'group' => null, 'jid' => $s->channel_jid, 'label' => "{$s->name} (canal)"]]
            : $s->groups->map(fn ($g) => ['series' => $s, 'group' => $g, 'jid' => $g->jid, 'label' => $s->groupName($g->number)]));
    }

    /** Coloca o produto na fila de todas as séries ativas. Devolve quantas entregas foram criadas. */
    public function queue(Company $company, Product $product, string $origin = 'manual'): int
    {
        $targets = $this->targets($company);
        if ($targets->isEmpty()) {
            return 0;
        }

        $post = OfferPost::create([
            'company_id' => $company->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'origin' => $origin,
            'code' => $this->uniqueCode(),
        ]);

        $this->createDeliveries($company, $post, $targets);

        $product->forceFill(['offers_last_sent_at' => now()])->saveQuietly();

        return $targets->count();
    }

    /** Achadinho: entra na mesma fila (um envio por minuto), só pros grupos e canais de achadinhos. */
    /** $origin: affiliate (Enviar agora) ou affiliate_schedule (agenda dos achadinhos). */
    public function queueAffiliate(Company $company, AffiliateOffer $offer, string $origin = 'manual'): int
    {
        $targets = $this->targets($company, OfferSeries::PURPOSE_AFFILIATE);
        if ($targets->isEmpty()) {
            return 0;
        }

        $post = OfferPost::create([
            'company_id' => $company->id,
            'affiliate_offer_id' => $offer->id,
            'product_name' => $offer->title,
            'origin' => $origin === 'schedule' ? 'affiliate_schedule' : 'affiliate',
            'code' => $this->uniqueCode(),
        ]);

        $this->createDeliveries($company, $post, $targets);
        $offer->forceFill(['last_sent_at' => now()])->saveQuietly();

        return $targets->count();
    }

    /** "Divulgar agora" do cupom: entra na mesma fila (um envio por minuto) pra todos os grupos e canais da loja. */
    public function queueCoupon(Company $company, Coupon $coupon): int
    {
        $targets = $this->targets($company);
        if ($targets->isEmpty()) {
            return 0;
        }

        $post = OfferPost::create([
            'company_id' => $company->id,
            'coupon_id' => $coupon->id,
            'product_name' => "Cupom {$coupon->code}",
            'origin' => 'coupon',
            'code' => $this->uniqueCode(),
        ]);

        $this->createDeliveries($company, $post, $targets);
        $coupon->forceFill(['broadcast_at' => now()])->save();

        return $targets->count();
    }

    private function createDeliveries(Company $company, OfferPost $post, \Illuminate\Support\Collection $targets): void
    {
        foreach ($targets as $target) {
            $post->deliveries()->create([
                'company_id' => $company->id,
                'offer_series_id' => $target['series']->id,
                'offer_group_id' => $target['group']?->id,
                'target_label' => $target['label'],
                'target_jid' => $target['jid'],
            ]);
        }
    }

    /** Manda a entrega pendente mais antiga da loja. Sem WhatsApp conectado, espera (até 24h). */
    public function sendNext(Company $company): ?OfferDelivery
    {
        $delivery = OfferDelivery::where('company_id', $company->id)
            ->where('status', OfferDelivery::STATUS_PENDING)
            ->orderBy('id')
            ->first();

        if (! $delivery) {
            return null;
        }

        $session = $company->whatsapp_session_name;
        $connected = $company->isPro() && $session && $this->waha->isConfigured()
            && (($this->waha->getSession($session)['status'] ?? null) === 'WORKING');

        if (! $connected) {
            if ($delivery->created_at->lt(now()->subDay())) {
                OfferDelivery::where('company_id', $company->id)->where('status', OfferDelivery::STATUS_PENDING)
                    ->where('created_at', '<', now()->subDay())
                    ->update(['status' => OfferDelivery::STATUS_FAILED, 'error' => 'WhatsApp desconectado']);
            }

            return null;
        }

        // Achadinho: confere de novo na hora de enviar (pode ter vencido, sido desligado ou excluído na fila)
        if (str_starts_with((string) $delivery->post?->origin, 'affiliate')) {
            return $this->sendAffiliate($company, $session, $delivery);
        }

        // Post de cupom: confere de novo na hora de enviar (pode ter vencido ou esgotado na fila)
        if ($delivery->post?->coupon_id || $delivery->post?->origin === 'coupon') {
            $coupon = $delivery->post->coupon_id ? Coupon::find($delivery->post->coupon_id) : null;
            $reason = $coupon ? app(Coupons::class)->broadcastReason($coupon) : 'Cupom excluído';
            if ($reason) {
                $delivery->update(['status' => OfferDelivery::STATUS_FAILED, 'error' => Str::limit($reason, 250)]);

                return $delivery;
            }

            try {
                $this->waha->sendText($session, $delivery->target_jid, $this->couponMessage($company, $coupon, $delivery->post->code), true);
                $delivery->update(['status' => OfferDelivery::STATUS_SENT, 'sent_at' => now(), 'error' => null]);
            } catch (Throwable $e) {
                Log::warning("Ofertas: envio do cupom {$delivery->id} falhou (empresa {$company->id}): {$e->getMessage()}");
                $delivery->update(['status' => OfferDelivery::STATUS_FAILED, 'error' => Str::limit($e->getMessage(), 250)]);
            }

            return $delivery;
        }

        $product = $delivery->post?->product_id ? Product::with(['category.parent', 'images', 'variations'])->find($delivery->post->product_id) : null;
        if (! $product) {
            $delivery->update(['status' => OfferDelivery::STATUS_FAILED, 'error' => 'Produto excluído']);

            return $delivery;
        }

        try {
            $caption = $this->message($company, $product, $delivery->post->code, null, $delivery->series?->coupon);
            $image = $this->imageBytes($product);

            if ($image) {
                $this->waha->sendImage($session, $delivery->target_jid, $image['bytes'], $image['mimetype'], 'oferta.'.$image['ext'], $caption);
            } else {
                $this->waha->sendText($session, $delivery->target_jid, $caption, true);
            }

            $delivery->update(['status' => OfferDelivery::STATUS_SENT, 'sent_at' => now(), 'error' => null]);
        } catch (Throwable $e) {
            Log::warning("Ofertas: envio {$delivery->id} falhou (empresa {$company->id}): {$e->getMessage()}");
            $delivery->update(['status' => OfferDelivery::STATUS_FAILED, 'error' => Str::limit($e->getMessage(), 250)]);
        }

        return $delivery;
    }

    /**
     * Texto da oferta: nome, preço (com o "de/por" da promoção), o texto da oferta (o próprio do produto,
     * ou um trecho da descrição) e o link do produto no catálogo — com o código da oferta (?o=), que o
     * catálogo usa pra contar o clique. Preço e link sempre montados aqui, nunca pela IA.
     *
     * Cupom do grupo: entra só quando vale agora para o produto (ligado, na validade, com uso sobrando, e
     * produto coberto) — o link leva `cupom=` e o catálogo já deixa o cupom no carrinho.
     */
    public function message(Company $company, Product $product, ?string $code = null, ?string $textOverride = null, ?Coupon $coupon = null): string
    {
        $coupon = $coupon && $this->couponFits($coupon, $product) ? $coupon : null;
        [$regular, $promo, $from] = $this->prices($company, $product);
        $money = fn (float $v) => 'R$ '.number_format($v, 2, ',', '.');
        $prefix = $from ? 'a partir de ' : '';

        $lines = ["🔥 *{$product->name}*"];
        $lines[] = $promo !== null
            ? "De ~{$money($regular)}~ por *{$prefix}{$money($promo)}*"
            : "*{$prefix}{$money($regular)}*";

        $custom = trim((string) ($textOverride ?? $product->offer_text));
        $description = trim(preg_replace('/\s+/', ' ', strip_tags((string) $product->description)));
        if ($custom !== '') {
            $lines[] = '';
            $lines[] = $custom;
        } elseif ($description !== '') {
            $lines[] = '';
            $lines[] = Str::limit($description, 160);
        }

        if ($coupon) {
            $lines[] = '';
            $lines[] = "🎟️ Cupom *{$coupon->code}*: {$coupon->label()} de desconto"
                .((float) $coupon->min_order > 0 ? ' em pedidos a partir de '.$money((float) $coupon->min_order) : '');
        }

        $query = http_build_query(array_filter(['o' => $code, 'cupom' => $coupon?->code]));
        $lines[] = '';
        $lines[] = "👉 Veja e peça: {$this->productUrl($company, $product)}".($query !== '' ? "?{$query}" : '');

        return implode("\n", $lines);
    }

    private function sendAffiliate(Company $company, string $session, OfferDelivery $delivery): OfferDelivery
    {
        $offer = $delivery->post->affiliate_offer_id ? AffiliateOffer::find($delivery->post->affiliate_offer_id) : null;
        $reason = $offer ? $offer->unavailableReason() : 'Achadinho excluído';
        if ($reason) {
            $delivery->update(['status' => OfferDelivery::STATUS_FAILED, 'error' => $reason]);

            return $delivery;
        }

        try {
            $caption = $this->affiliateMessage($company, $offer, $delivery->post->code, $delivery->series?->affiliate_disclosure ?? true);
            $image = $this->imageFromPath($offer->image_path);

            if ($image) {
                $this->waha->sendImage($session, $delivery->target_jid, $image['bytes'], $image['mimetype'], 'oferta.'.$image['ext'], $caption);
            } else {
                $this->waha->sendText($session, $delivery->target_jid, $caption, true);
            }

            $delivery->update(['status' => OfferDelivery::STATUS_SENT, 'sent_at' => now(), 'error' => null]);
        } catch (Throwable $e) {
            Log::warning("Achadinhos: envio {$delivery->id} falhou (empresa {$company->id}): {$e->getMessage()}");
            $delivery->update(['status' => OfferDelivery::STATUS_FAILED, 'error' => Str::limit($e->getMessage(), 250)]);
        }

        return $delivery;
    }

    /**
     * Texto do achadinho: nome, preço ("de/por" quando há o preço antigo) e onde comprar, cupom da loja de
     * origem, o texto da oferta e o link — o rastreado (…/r/CODIGO: conta o clique e leva ao link de
     * afiliado) quando há código; sem código (prévia), o próprio link de afiliado.
     */
    public function affiliateMessage(Company $company, AffiliateOffer $offer, ?string $code = null, bool $disclosure = true): string
    {
        $money = fn (float $v) => 'R$ '.number_format($v, 2, ',', '.');
        $where = $offer->whereToBuy();
        $price = $offer->price !== null ? (float) $offer->price : null;
        $original = $offer->original_price !== null ? (float) $offer->original_price : null;

        $lines = $offer->flash ? ['⚡ *OFERTA RELÂMPAGO*', "*{$offer->title}*"] : ["🔥 *{$offer->title}*"];
        if ($price !== null && $original !== null && $original > $price) {
            $lines[] = "De ~{$money($original)}~ por *{$money($price)}*".($where ? " {$where}" : '');
        } elseif ($price !== null) {
            $lines[] = "*{$money($price)}*".($where ? " {$where}" : '');
        } elseif ($where) {
            $lines[] = '🛒 '.Str::ucfirst($where);
        }

        if ($offer->store_coupon) {
            $lines[] = "🎟️ Cupom: *{$offer->store_coupon}*";
        }

        // Validade perto do fim: "Termina hoje às 18:00" / "Termina amanhã às 23:59" (na hora da loja)
        if ($offer->expires_at && $offer->expires_at->isFuture()) {
            $tz = $company->timezone ?: 'America/Sao_Paulo';
            $ends = $offer->expires_at->copy()->setTimezone($tz);
            $today = now($tz)->startOfDay();
            $time = $ends->format('H:i') === '23:59' ? 'o fim do dia' : 'as '.$ends->format('H:i');
            if ($ends->isSameDay($today)) {
                $lines[] = "⏰ Só hoje! Termina até {$time}";
            } elseif ($ends->isSameDay($today->copy()->addDay())) {
                $lines[] = "⏰ Termina amanhã, até {$time}";
            }
        }

        $text = trim((string) $offer->offer_text);
        if ($text !== '') {
            $lines[] = '';
            $lines[] = $text;
        }

        $lines[] = '';
        $lines[] = '👉 '.($code ? $this->affiliateLink($company, $code) : $offer->url);

        if ($disclosure) {
            $lines[] = '';
            $lines[] = '_Link de afiliado. Preço e estoque podem mudar._';
        }

        return implode("\n", $lines);
    }

    /** Link curto rastreado do achadinho, no domínio das ofertas (ou do catálogo, ou do app). */
    public function affiliateLink(Company $company, string $code): string
    {
        return $company->offersOrigin().'/r/'.$code;
    }

    /**
     * Mensagem do cupom divulgado: desconto, validade (com "a partir de" quando ainda vai começar), regras
     * principais e o link que já coloca o cupom no carrinho (com o código da oferta, pra contar o clique).
     */
    public function couponMessage(Company $company, Coupon $coupon, ?string $code = null): string
    {
        $tz = $company->timezone ?: 'America/Sao_Paulo';
        $today = now($tz)->startOfDay();
        $money = fn (float $v) => 'R$ '.number_format($v, 2, ',', '.');

        $lines = ["🎟️ *CUPOM DE DESCONTO: {$coupon->code}*"];
        $lines[] = "{$coupon->label()} de desconto"
            .($coupon->type === Coupon::TYPE_PERCENT && $coupon->max_discount !== null ? ' (até '.$money((float) $coupon->max_discount).')' : '')
            .((float) $coupon->min_order > 0 ? ' em pedidos a partir de '.$money((float) $coupon->min_order) : '');

        $starts = $coupon->starts_on ? Carbon::parse($coupon->starts_on->toDateString(), $tz) : null;
        $until = $coupon->ends_on ? ' até '.$coupon->ends_on->format('d/m') : '';
        if ($starts && $starts->gt($today)) {
            $days = (int) $today->diffInDays($starts);
            $lines[] = '⏳ Válido a partir de '.$starts->format('d/m').' ('.($days === 1 ? 'amanhã' : "daqui a {$days} dias").")$until";
        } elseif ($until !== '') {
            $lines[] = "📅 Válido{$until}";
        }

        $rules = [];
        if (! empty($coupon->product_ids)) {
            $names = Product::whereIn('id', $coupon->product_ids)->orderBy('name')->pluck('name');
            $rules[] = 'Vale para: '.$names->take(3)->implode(', ').($names->count() > 3 ? ' e mais '.($names->count() - 3) : '');
        } elseif (! empty($coupon->category_ids)) {
            $names = ProductCategory::whereIn('id', $coupon->category_ids)->orderBy('name')->pluck('name');
            $rules[] = 'Vale para: '.$names->take(3)->implode(', ').($names->count() > 3 ? ' e mais '.($names->count() - 3) : '');
        }
        if ($coupon->first_purchase_only) {
            $rules[] = 'só na primeira compra';
        } elseif ($coupon->per_customer_limit === 1) {
            $rules[] = '1 vez por cliente';
        }
        if (! $coupon->allow_promotions) {
            $rules[] = 'não vale para itens em promoção';
        }
        if ($rules) {
            $lines[] = 'ℹ️ '.Str::ucfirst(implode(' · ', $rules));
        }

        $lines[] = '';
        if ($company->hasCatalogActive()) {
            $query = http_build_query(array_filter(['o' => $code, 'cupom' => $coupon->code]));
            $lines[] = "👉 Use no carrinho do catálogo: {$company->catalogUrl()}?{$query}";
        } else {
            $lines[] = '👉 Informe o cupom ao fazer o seu pedido.';
        }

        return implode("\n", $lines);
    }

    /** O cupom vale agora para este produto? (promoção só se o cupom permitir, como no pedido) */
    private function couponFits(Coupon $coupon, Product $product): bool
    {
        return $coupon->coversProduct($product)
            && ($coupon->allow_promotions || $product->discount_percent === null)
            && app(Coupons::class)->unavailableReason($coupon, null, customerKnown: false) === null;
    }

    /**
     * Preço como o catálogo mostra: digitado ou custo + margem; com variações, o da mais barata
     * ("a partir de" quando elas têm preços diferentes); promoção do produto aplicada por cima.
     *
     * @return array{0: float, 1: float|null, 2: bool}  [preço cheio, preço promocional, "a partir de"]
     */
    public function prices(Company $company, Product $product): array
    {
        $markup = (float) ($company->setting?->default_markup ?? 0);
        $auto = fn ($cost) => round((float) $cost * (1 + $markup / 100), 2);

        $regular = $product->sale_price !== null ? (float) $product->sale_price : $auto($product->cost);
        $from = false;

        $variationPrices = $product->variations->where('active', true)
            ->map(fn ($v) => $v->effectivePrice($product) ?? $auto($v->effectiveCost($product)))
            ->values();
        if ($variationPrices->isNotEmpty()) {
            $regular = (float) $variationPrices->min();
            $from = $variationPrices->unique()->count() > 1;
        }

        $promo = $product->discount_percent !== null ? round($regular * (1 - (float) $product->discount_percent / 100), 2) : null;

        return [$regular, $promo, $from];
    }

    private function uniqueCode(): string
    {
        do {
            $code = Str::lower(Str::random(8));
        } while (OfferPost::where('code', $code)->exists());

        return $code;
    }

    /** Mesmo endereço que o catálogo monta pro produto (categoria/subcategoria/slug). */
    private function productUrl(Company $company, Product $product): string
    {
        return implode('/', array_filter([
            $company->catalogUrl(),
            $product->category?->parent?->slug ?? $product->category?->slug,
            $product->category?->parent ? $product->category->slug : null,
            $product->slug ?: $product->id,
        ]));
    }

    /** Primeira foto do produto (do disco do sistema). Sem foto, a oferta vai com a prévia do link. */
    private function imageBytes(Product $product): ?array
    {
        return $this->imageFromPath($product->images->sortBy('position')->first()?->path ?? $product->image_path);
    }

    private function imageFromPath(?string $path): ?array
    {
        if (! $path || ! Storage::disk('public')->exists($path)) {
            return null;
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION)) ?: 'jpg';

        return [
            'bytes' => Storage::disk('public')->get($path),
            'mimetype' => match ($ext) {
                'png' => 'image/png',
                'webp' => 'image/webp',
                default => 'image/jpeg',
            },
            'ext' => $ext,
        ];
    }
}
