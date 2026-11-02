<?php

namespace App\Services;

use App\Models\Company;
use App\Models\OfferDelivery;
use App\Models\OfferPost;
use App\Models\OfferSeries;
use App\Models\Product;
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

    /** Coloca o produto na fila de todas as séries ativas. Devolve quantas entregas foram criadas. */
    public function queue(Company $company, Product $product, string $origin = 'manual'): int
    {
        $series = OfferSeries::where('company_id', $company->id)->where('active', true)->with('groups')->get();

        $targets = $series->flatMap(fn (OfferSeries $s) => $s->isChannel()
            ? [['series' => $s, 'group' => null, 'jid' => $s->channel_jid, 'label' => "{$s->name} (canal)"]]
            : $s->groups->map(fn ($g) => ['series' => $s, 'group' => $g, 'jid' => $g->jid, 'label' => $s->groupName($g->number)]));

        if ($targets->isEmpty()) {
            return 0;
        }

        $post = OfferPost::create([
            'company_id' => $company->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'origin' => $origin,
        ]);

        foreach ($targets as $target) {
            $post->deliveries()->create([
                'company_id' => $company->id,
                'offer_series_id' => $target['series']->id,
                'offer_group_id' => $target['group']?->id,
                'target_label' => $target['label'],
                'target_jid' => $target['jid'],
            ]);
        }

        $product->forceFill(['offers_last_sent_at' => now()])->saveQuietly();

        return $targets->count();
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

        $product = $delivery->post?->product_id ? Product::with(['category.parent', 'images', 'variations'])->find($delivery->post->product_id) : null;
        if (! $product) {
            $delivery->update(['status' => OfferDelivery::STATUS_FAILED, 'error' => 'Produto excluído']);

            return $delivery;
        }

        try {
            $caption = $this->message($company, $product);
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

    /** Texto da oferta: nome, preço (com o "de/por" da promoção) e o link do produto no catálogo. */
    public function message(Company $company, Product $product): string
    {
        [$regular, $promo, $from] = $this->prices($company, $product);
        $money = fn (float $v) => 'R$ '.number_format($v, 2, ',', '.');
        $prefix = $from ? 'a partir de ' : '';

        $lines = ["🔥 *{$product->name}*"];
        $lines[] = $promo !== null
            ? "De ~{$money($regular)}~ por *{$prefix}{$money($promo)}*"
            : "*{$prefix}{$money($regular)}*";

        $description = trim(preg_replace('/\s+/', ' ', strip_tags((string) $product->description)));
        if ($description !== '') {
            $lines[] = '';
            $lines[] = Str::limit($description, 160);
        }

        $lines[] = '';
        $lines[] = "👉 Veja e peça: {$this->productUrl($company, $product)}";

        return implode("\n", $lines);
    }

    /**
     * Preço como o catálogo mostra: digitado ou custo + margem; com variações, o da mais barata
     * ("a partir de" quando elas têm preços diferentes); promoção do produto aplicada por cima.
     *
     * @return array{0: float, 1: float|null, 2: bool}  [preço cheio, preço promocional, "a partir de"]
     */
    private function prices(Company $company, Product $product): array
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
        $path = $product->images->sortBy('position')->first()?->path ?? $product->image_path;
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
