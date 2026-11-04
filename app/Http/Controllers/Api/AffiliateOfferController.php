<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\EnforcesPlanLimits;
use App\Http\Controllers\Controller;
use App\Models\AffiliateOffer;
use App\Models\OfferPost;
use App\Models\OfferSeries;
use App\Services\AffiliateLinkReader;
use App\Services\OfferMetrics;
use App\Services\OfferPublisher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Ofertas → Achadinhos (Pro): produtos de outras lojas divulgados com o link de afiliado da pessoa, só nos
 * grupos/canais do tipo "achadinhos". A mensagem leva o link rastreado (…/r/CODIGO), que conta o clique e
 * redireciona pro link de afiliado — a comissão continua sendo marcada pela loja de origem.
 */
class AffiliateOfferController extends Controller
{
    use EnforcesPlanLimits;

    private const FEATURE = 'Achadinhos (links de afiliado)';

    private const MAX_OFFERS = 1000;

    public function __construct(private OfferPublisher $publisher, private AffiliateLinkReader $reader) {}

    public function index(Request $request)
    {
        $this->requirePro($request, self::FEATURE);
        $company = $request->user()->company;

        $offers = AffiliateOffer::where('company_id', $company->id)
            ->orderByDesc('active')
            ->latest('id')
            ->get();

        // Cliques de todas as vezes que cada achadinho saiu (uma consulta só)
        $clicks = DB::table('offer_clicks')
            ->join('offer_posts', 'offer_posts.id', '=', 'offer_clicks.offer_post_id')
            ->where('offer_posts.company_id', $company->id)
            ->whereNotNull('offer_posts.affiliate_offer_id')
            ->selectRaw('offer_posts.affiliate_offer_id AS offer_id, COUNT(*) AS total, COUNT(DISTINCT offer_clicks.visitor_hash) AS people')
            ->groupBy('offer_posts.affiliate_offer_id')
            ->get()
            ->keyBy('offer_id');
        $sends = OfferPost::where('company_id', $company->id)->whereNotNull('affiliate_offer_id')
            ->selectRaw('affiliate_offer_id, COUNT(*) AS total')->groupBy('affiliate_offer_id')->pluck('total', 'affiliate_offer_id');

        return response()->json([
            'offers' => $offers->map(fn (AffiliateOffer $o) => [
                ...$o->toArray(),
                'times_sent' => (int) ($sends[$o->id] ?? 0),
                'clicks' => (int) ($clicks[$o->id]->total ?? 0),
                'unique_clicks' => (int) ($clicks[$o->id]->people ?? 0),
            ])->values(),
            'marketplaces' => AffiliateOffer::MARKETPLACES,
            // Grupos/canais de achadinhos ativos (sem nenhum, "Enviar agora" não tem pra onde ir)
            'targets' => $this->publisher->targets($company, OfferSeries::PURPOSE_AFFILIATE)->pluck('label')->values(),
        ]);
    }

    /** Lê o link colado e devolve o que deu pra preencher (título, foto, loja; preço quase nunca). */
    public function readLink(Request $request)
    {
        $this->requirePro($request, self::FEATURE);
        $data = $request->validate(['url' => ['required', 'string', 'max:2000']], ['url.required' => 'Cole o link do produto.']);

        return response()->json($this->reader->read($data['url']));
    }

    /** Como o achadinho sai no WhatsApp, com os dados ainda não salvos (o link aparece como o de afiliado). */
    public function previewMessage(Request $request)
    {
        $this->requirePro($request, self::FEATURE);
        $data = $this->validated($request, partial: true);
        $offer = new AffiliateOffer([
            ...$data,
            'title' => $data['title'] ?? 'Nome do produto',
            'url' => $data['url'] ?? 'https://…',
            'marketplace' => $data['marketplace'] ?? AffiliateOffer::detectMarketplace($data['url'] ?? ''),
        ]);
        $code = 'exemplo';

        return response()->json(['message' => $this->publisher->affiliateMessage($request->user()->company, $offer, $code, (bool) ($data['disclosure'] ?? true))]);
    }

    public function store(Request $request)
    {
        $this->requirePro($request, self::FEATURE);
        $company = $request->user()->company;
        abort_if(AffiliateOffer::where('company_id', $company->id)->count() >= self::MAX_OFFERS, 422, 'Limite de '.self::MAX_OFFERS.' achadinhos. Exclua os antigos para cadastrar novos.');

        $data = $this->validated($request);
        $data['marketplace'] ??= AffiliateOffer::detectMarketplace($data['url']);

        $offer = new AffiliateOffer([...collect($data)->except(['image', 'image_url', 'disclosure'])->all(), 'company_id' => $company->id]);
        $warning = $this->applyImage($request, $offer, $data);
        $offer->save();

        return response()->json(['offer' => $offer->fresh(), 'warning' => $warning], 201);
    }

    public function update(Request $request, AffiliateOffer $offer)
    {
        $this->requirePro($request, self::FEATURE);
        $this->authorizeOffer($request, $offer);

        $data = $this->validated($request, partial: true);
        $offer->fill(collect($data)->except(['image', 'image_url', 'remove_image', 'disclosure'])->all());
        if (array_key_exists('url', $data) && ! array_key_exists('marketplace', $data)) {
            $offer->marketplace = AffiliateOffer::detectMarketplace($data['url']);
        }

        $warning = null;
        if (! empty($data['remove_image']) && $offer->image_path) {
            Storage::disk('public')->delete($offer->image_path);
            $offer->image_path = null;
        }
        if ($request->hasFile('image') || ! empty($data['image_url'])) {
            $old = $offer->image_path;
            $warning = $this->applyImage($request, $offer, $data);
            if ($old && $offer->image_path !== $old) {
                Storage::disk('public')->delete($old);
            }
        }
        $offer->save();

        return response()->json(['offer' => $offer->fresh(), 'warning' => $warning]);
    }

    public function destroy(Request $request, AffiliateOffer $offer)
    {
        $this->requirePro($request, self::FEATURE);
        $this->authorizeOffer($request, $offer);

        if ($offer->image_path) {
            Storage::disk('public')->delete($offer->image_path);
        }
        // Mensagens já enviadas: o link rastreado para de redirecionar (o post fica sem o achadinho)
        $offer->delete();

        return response()->noContent();
    }

    /** "Enviar agora": entra na fila dos grupos/canais de achadinhos (um envio por minuto). */
    public function send(Request $request, AffiliateOffer $offer)
    {
        $this->requirePro($request, self::FEATURE);
        $this->authorizeOffer($request, $offer);

        if ($reason = $offer->unavailableReason()) {
            abort(422, $reason === 'Oferta vencida' ? 'Essa oferta já venceu. Atualize o preço e a validade antes de enviar.' : 'Ligue o achadinho antes de enviar.');
        }

        $count = $this->publisher->queueAffiliate($request->user()->company, $offer);
        abort_if($count === 0, 422, 'Crie um grupo ou canal de achadinhos primeiro (Novo grupo ou canal → Achadinhos).');

        return response()->json(['deliveries' => $count]);
    }

    /**
     * Link rastreado (público): conta o clique e devolve o link de afiliado pra tela redirecionar. Oferta vencida
     * ainda redireciona (a pessoa decide na loja de origem); só achadinho excluído não tem mais pra onde ir.
     */
    public function publicRedirect(Request $request, string $code, OfferMetrics $metrics)
    {
        $post = OfferPost::where('code', $code)->whereNotNull('affiliate_offer_id')->first();
        $offer = $post ? AffiliateOffer::find($post->affiliate_offer_id) : null;
        abort_unless($offer, 404, 'Esta oferta não está mais disponível.');

        if (! $request->headers->has('X-No-Track')) {
            $metrics->recordClick($code, $request);
        }

        return response()->json(['url' => $offer->url]);
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'url' => [$req, 'string', 'max:2000', 'regex:~^https?://~i'],
            'marketplace' => ['sometimes', Rule::in(array_keys(AffiliateOffer::MARKETPLACES))],
            'title' => [$req, 'string', 'min:3', 'max:200'],
            'price' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'original_price' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'store_coupon' => ['nullable', 'string', 'max:60'],
            'offer_text' => ['nullable', 'string', 'max:1000'],
            'expires_at' => ['nullable', 'date'],
            'active' => ['sometimes', 'boolean'],
            // Foto: enviada pela pessoa, ou a da prévia do link (baixada pro sistema)
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'image_url' => ['nullable', 'string', 'max:2000'],
            'remove_image' => ['sometimes', 'boolean'],
            // Só pra prévia: a linha "Link de afiliado" (vem da série)
            'disclosure' => ['sometimes', 'boolean'],
        ], [
            'url.required' => 'Cole o link de afiliado.',
            'url.regex' => 'O link precisa começar com https://.',
            'title.required' => 'Escreva o nome do produto.',
            'title.min' => 'O nome precisa de pelo menos 3 letras.',
            'image.image' => 'A foto precisa ser JPG, PNG ou WebP.',
            'image.max' => 'A foto pode ter até 5 MB.',
        ]);
    }

    /** Foto enviada tem prioridade; senão baixa a da prévia do link. Devolve um aviso quando a foto não veio. */
    private function applyImage(Request $request, AffiliateOffer $offer, array $data): ?string
    {
        $companyId = $request->user()->company_id;

        if ($request->hasFile('image')) {
            $path = $this->reader->storeUpload($request->file('image'), $companyId);
            if ($path) {
                $offer->image_path = $path;

                return null;
            }

            return 'Não foi possível usar essa foto. Envie um JPG, PNG ou WebP.';
        }

        if (! empty($data['image_url'])) {
            $path = $this->reader->downloadImage($data['image_url'], $companyId);
            if ($path) {
                $offer->image_path = $path;

                return null;
            }

            return 'Não deu pra baixar a foto da loja. O achadinho foi salvo sem foto — envie uma pelo botão de foto.';
        }

        return null;
    }

    private function authorizeOffer(Request $request, AffiliateOffer $offer): void
    {
        abort_unless($offer->company_id === $request->user()->company_id, 404);
    }
}
