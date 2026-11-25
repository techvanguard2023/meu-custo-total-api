<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\EnforcesPlanLimits;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductCollection;
use App\Models\QrCode;
use App\Services\DisplayQrImage;
use App\Services\QrCodes;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Marketing → QR Codes: QR de divulgação com destino editável, contagem de acessos e vendas. */
class QrCodeController extends Controller
{
    use EnforcesPlanLimits;

    public function __construct(private QrCodes $qrCodes) {}

    public function index(Request $request)
    {
        $this->requireEssential($request, 'QR Codes');
        $company = $request->user()->company;
        $summary = $this->qrCodes->summary($company);

        $items = QrCode::where('company_id', $company->id)->latest('id')->get()
            ->each(fn (QrCode $qr) => $qr->setRelation('company', $company))
            ->map(fn (QrCode $qr) => $this->payload($qr, $summary[$qr->id] ?? null));

        return response()->json([
            'items' => $items,
            'limit' => $company->isPro() ? null : QrCode::ESSENTIAL_LIMIT,
            'has_logo' => (bool) $company->logo_path,
        ]);
    }

    public function store(Request $request)
    {
        $this->requireEssential($request, 'QR Codes');
        $company = $request->user()->company;

        if (! $company->isPro()) {
            $count = QrCode::where('company_id', $company->id)->count();
            abort_if($count >= QrCode::ESSENTIAL_LIMIT, 403, 'O plano Essencial tem até '.QrCode::ESSENTIAL_LIMIT.' QR Codes. No Pro eles são ilimitados.');
        }

        $data = $this->validated($request);
        $qr = QrCode::create($data + ['company_id' => $company->id]);
        $qr->setRelation('company', $company);

        return response()->json($this->payload($qr), 201);
    }

    public function update(Request $request, QrCode $qrCode)
    {
        $this->requireEssential($request, 'QR Codes');
        $this->authorizeOwn($request, $qrCode);

        $qrCode->update($this->validated($request, $qrCode));

        return response()->json($this->payload($qrCode->fresh('company')));
    }

    public function destroy(Request $request, QrCode $qrCode)
    {
        $this->authorizeOwn($request, $qrCode);
        $qrCode->delete();

        return response()->noContent();
    }

    public function stats(Request $request, QrCode $qrCode)
    {
        $this->requireEssential($request, 'QR Codes');
        $this->authorizeOwn($request, $qrCode);
        $data = $request->validate(['days' => ['sometimes', 'integer', 'in:7,30,90']]);

        return response()->json($this->qrCodes->stats($qrCode->loadMissing('company'), (int) ($data['days'] ?? 30)));
    }

    /** Imagem do QR: PNG (posts, banners digitais) ou SVG (gráfica), com a cor e a logo escolhidas. */
    public function image(Request $request, QrCode $qrCode, DisplayQrImage $image)
    {
        $this->requireEssential($request, 'QR Codes');
        $this->authorizeOwn($request, $qrCode);
        $qrCode->loadMissing('company');
        $format = $request->query('format') === 'svg' ? 'svg' : 'png';
        $url = $this->qrCodes->linkUrl($qrCode);
        $logo = $this->logoBytes($qrCode);
        $filename = 'qrcode-'.Str::slug($qrCode->name ?: $qrCode->code).".{$format}";

        if ($format === 'svg') {
            return response($image->svg($url, $qrCode->color, $logo ? $this->dataUri($logo) : null), 200, [
                'Content-Type' => 'image/svg+xml',
                'Content-Disposition' => "attachment; filename=\"{$filename}\"",
                'Cache-Control' => 'no-store',
            ]);
        }

        $png = $image->render($url, $qrCode->color, $logo);
        abort_unless($png, 503, 'O servidor não conseguiu gerar o QR Code.');

        return response($png, 200, [
            'Content-Type' => 'image/png',
            'Content-Disposition' => "inline; filename=\"{$filename}\"",
            'Cache-Control' => 'no-store',
        ]);
    }

    /** Arte pronta para imprimir (PDF A6 ou A5): logo, nome da loja, a chamada, o QR e o link. */
    public function printArt(Request $request, QrCode $qrCode, DisplayQrImage $image)
    {
        $this->requireEssential($request, 'QR Codes');
        $this->authorizeOwn($request, $qrCode);
        $qrCode->loadMissing('company');
        $size = $request->query('size') === 'a5' ? 'a5' : 'a6';
        $company = $qrCode->company;
        $link = $this->qrCodes->linkUrl($qrCode);
        $logo = $this->logoBytes($qrCode, force: true);

        $png = $image->render($link, $qrCode->color, $qrCode->with_logo ? $logo : null);
        abort_unless($png, 503, 'O servidor não conseguiu gerar o QR Code.');

        $pdf = Pdf::loadView('pdf.qr-print', [
            'storeName' => $company->name,
            'logo' => $logo ? $this->dataUri($logo) : null,
            'headline' => trim((string) $qrCode->headline) ?: $this->defaultHeadline($qrCode),
            'qr' => 'data:image/png;base64,'.base64_encode($png),
            'link' => preg_replace('#^https?://#', '', $link),
            'coupon' => $this->qrCodes->usableCoupon($qrCode)?->code,
            'accent' => DisplayQrImage::readableColor($qrCode->color) ? $qrCode->color : '#111827',
            'size' => $size,
        ])->setPaper($size);

        return $pdf->download('arte-'.Str::slug($qrCode->name ?: $qrCode->code)."-{$size}.pdf");
    }

    /**
     * Link curto aberto (chamado pela função da Vercel, api/qr.js): devolve para onde mandar a pessoa e conta a
     * leitura. `via=link` é o link copiado (bio, stories); sem ele, foi o QR. Robôs de prévia não contam.
     */
    public function go(Request $request)
    {
        $data = $request->validate([
            'slug' => ['nullable', 'string', 'max:120'],
            'host' => ['nullable', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:40'],
            'via' => ['nullable', 'in:link,scan'],
        ]);

        $company = $this->qrCodes->resolveCompany($data['slug'] ?? null, $data['host'] ?? null);
        abort_unless($company, 404, 'Link não encontrado.');

        $qr = $this->qrCodes->find($company, $data['code']);
        $qr?->setRelation('company', $company);

        // Quem abriu: a função da Vercel repassa o IP e o navegador da pessoa (sem isso, seria o da Vercel)
        $ip = $this->visitorIp($request);
        $userAgent = $request->header('X-Visitor-Agent') ?: $request->userAgent();

        $url = $qr && $qr->active ? $this->qrCodes->destinationUrl($qr) : null;
        if ($qr && $qr->active && ! QrCodes::isBot($userAgent)) {
            $this->qrCodes->record($qr, $data['via'] ?? QrCode::KIND_SCAN, $ip, $userAgent);
        }

        return response()->json([
            'url' => $url ?: $this->qrCodes->fallbackUrl($company),
            'found' => (bool) $qr,
            'active' => (bool) $qr?->active,
        ]);
    }

    /** Carrinho do catálogo aberto pelo QR enviado pelo WhatsApp (chamado pelo navegador do cliente). */
    public function cart(Request $request, string $token)
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40'],
            'total' => ['required', 'numeric', 'min:0', 'max:9999999'],
        ]);

        $company = $this->qrCodes->companyByCatalogToken($token);
        $qr = $company ? $this->qrCodes->find($company, $data['code']) : null;
        if ($qr && ! QrCodes::isBot($request->userAgent())) {
            $this->qrCodes->recordCart($qr, (float) $data['total'], $request->ip(), $request->userAgent());
        }

        return response()->noContent();
    }

    private function visitorIp(Request $request): ?string
    {
        $ip = $request->header('X-Visitor-Ip') ?: $request->ip();

        return $ip ? Str::limit(trim(explode(',', $ip)[0]), 45, '') : null;
    }

    /** Logo da loja (bytes) quando o QR usa logo — ou sempre, para a arte de impressão (`force`). */
    private function logoBytes(QrCode $qr, bool $force = false): ?string
    {
        $path = $qr->company->logo_path;
        if (! $path || (! $qr->with_logo && ! $force) || ! Storage::disk('public')->exists($path)) {
            return null;
        }

        return Storage::disk('public')->get($path);
    }

    private function dataUri(string $bytes): string
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: 'image/png';

        return "data:{$mime};base64,".base64_encode($bytes);
    }

    private function defaultHeadline(QrCode $qr): string
    {
        return match ($qr->destination_type) {
            QrCode::DEST_WHATSAPP => 'Aponte a câmera e fale com a gente no WhatsApp',
            QrCode::DEST_INSTAGRAM => 'Aponte a câmera e siga a gente no Instagram',
            QrCode::DEST_PRODUCT => 'Aponte a câmera e veja este produto',
            QrCode::DEST_URL => 'Aponte a câmera do celular',
            default => 'Aponte a câmera e veja nosso catálogo',
        };
    }

    private function validated(Request $request, ?QrCode $current = null): array
    {
        $company = $request->user()->company;
        $companyId = $company->id;
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'code' => [
                'nullable', 'string', 'max:40', 'regex:/^[a-z0-9-]+$/i',
                Rule::unique('qr_codes', 'code')->where('company_id', $companyId)->ignore($current?->id),
            ],
            'channel' => ['required', Rule::in(QrCode::CHANNELS)],
            'destination_type' => ['required', Rule::in(QrCode::DESTINATIONS)],
            'destination_id' => ['nullable', 'integer'],
            'destination_url' => ['nullable', 'required_if:destination_type,url', 'url:http,https', 'max:2048'],
            'whatsapp_message' => ['nullable', 'string', 'max:500'],
            'active' => ['sometimes', 'boolean'],
            'coupon_id' => ['nullable', 'integer', Rule::exists('coupons', 'id')->where('company_id', $companyId)->whereNull('customer_id')],
            'color' => ['nullable', 'regex:/^#[0-9a-f]{6}$/i'],
            'with_logo' => ['sometimes', 'boolean'],
            'headline' => ['nullable', 'string', 'max:80'],
        ], [
            'code.unique' => 'Esse final de link já está em uso em outro QR Code da loja.',
            'code.regex' => 'Use só letras, números e hífen no final do link.',
            'destination_url.required_if' => 'Informe o link de destino.',
            'destination_url.url' => 'Informe um link válido, começando com https://',
            'coupon_id.exists' => 'Escolha um cupom da loja.',
            'color.regex' => 'Cor inválida.',
        ]);

        // O item escolhido tem que ser da loja
        $type = $data['destination_type'];
        $id = $data['destination_id'] ?? null;
        $exists = match ($type) {
            QrCode::DEST_PRODUCT => $id && Product::where('company_id', $companyId)->whereKey($id)->exists(),
            QrCode::DEST_CATEGORY => $id && ProductCategory::visibleTo($companyId)->whereKey($id)->exists(),
            QrCode::DEST_COLLECTION => $id && ProductCollection::where('company_id', $companyId)->whereKey($id)->exists(),
            default => true,
        };
        abort_unless($exists, 422, 'Escolha o item de destino do QR Code.');

        if (! in_array($type, [QrCode::DEST_PRODUCT, QrCode::DEST_CATEGORY, QrCode::DEST_COLLECTION], true)) {
            $data['destination_id'] = null;
        }
        if ($type !== QrCode::DEST_URL) {
            $data['destination_url'] = null;
        }
        if ($type !== QrCode::DEST_WHATSAPP) {
            $data['whatsapp_message'] = null;
        }

        // Cupom: só no catálogo (é onde o carrinho aplica) e só no Pro (cupons são do Pro)
        $catalogType = in_array($type, [QrCode::DEST_CATALOG, QrCode::DEST_PRODUCT, QrCode::DEST_CATEGORY, QrCode::DEST_COLLECTION], true);
        if (! empty($data['coupon_id'])) {
            abort_unless($company->isPro(), 403, 'Cupom no QR Code é um recurso do plano Pro.');
            abort_unless($catalogType, 422, 'O cupom só vale para QR Codes que levam ao catálogo.');
        }
        $data['coupon_id'] = $catalogType ? ($data['coupon_id'] ?? null) : null;

        // Cor clara some no papel e a câmera não lê
        if (! empty($data['color'])) {
            abort_unless(DisplayQrImage::readableColor($data['color']), 422, 'Escolha uma cor mais escura: QR claro a câmera não consegue ler.');
            $data['color'] = Str::lower($data['color']);
        }
        if (! empty($data['with_logo']) && ! $company->logo_path) {
            abort(422, 'Cadastre a logo da loja (Configurações → Catálogo) para colocá-la no QR Code.');
        }

        // Sem final escolhido: um curto a partir do nome ("Panfleto Natal" → "panfleto-natal"), sem repetir
        $code = Str::lower(trim((string) ($data['code'] ?? '')));
        if ($code === '') {
            $code = $current?->code ?: $this->uniqueCode($companyId, $data['name']);
        }
        $data['code'] = $code;

        return $data;
    }

    private function uniqueCode(int $companyId, string $name): string
    {
        $base = Str::limit(Str::slug($name), 30, '') ?: Str::lower(Str::random(6));
        $code = $base;
        $n = 2;
        while (QrCode::where('company_id', $companyId)->where('code', $code)->exists()) {
            $code = "{$base}-{$n}";
            $n++;
        }

        return $code;
    }

    private function payload(QrCode $qr, ?array $summary = null): array
    {
        return [
            'id' => $qr->id,
            'name' => $qr->name,
            'code' => $qr->code,
            'channel' => $qr->channel,
            'destination_type' => $qr->destination_type,
            'destination_id' => $qr->destination_id,
            'destination_url' => $qr->destination_url,
            'whatsapp_message' => $qr->whatsapp_message,
            'coupon_id' => $qr->coupon_id,
            'color' => $qr->color,
            'with_logo' => $qr->with_logo,
            'headline' => $qr->headline,
            'active' => $qr->active,
            'link' => $this->qrCodes->linkUrl($qr),
            'resolved_url' => $this->qrCodes->destinationUrl($qr),
            'scans_30' => $summary['last_30'] ?? 0,
            'scans_total' => $summary['total'] ?? 0,
            'orders_30' => $summary['orders_30'] ?? 0,
            'revenue_30' => $summary['revenue_30'] ?? 0,
            'created_at' => $qr->created_at?->toIso8601String(),
        ];
    }

    private function authorizeOwn(Request $request, QrCode $qrCode): void
    {
        abort_unless($qrCode->company_id === $request->user()->company_id, 404);
    }
}
