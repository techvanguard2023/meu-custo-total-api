<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Display;
use App\Services\DisplayQr;
use Illuminate\Http\Request;

/**
 * Rotas públicas do QR Code da placa do expositor (sem login): a página /q/… do frontend chama aqui pra
 * registrar o escaneamento e saber pra onde mandar o cliente; os botões da página registram o clique.
 */
class DisplayQrController extends Controller
{
    public function __construct(private DisplayQr $qr) {}

    public function show(Request $request, string $slug, string $code)
    {
        [$company, $display] = $this->resolve($slug, $code);

        $this->qr->record($display, DisplayQr::TYPE_SCAN, $display->qr_page_enabled ? 'page' : $display->qr_destination, $request);

        $whatsapp = $this->qr->whatsappUrl($display);
        $instagram = trim((string) $company->catalog_instagram_url) ?: null;

        return response()->json([
            'store' => [
                'name' => $company->name,
                'logo_url' => $company->logo_url,
                'accent_color' => $company->catalog_accent_color,
            ],
            'display' => ['name' => $display->name, 'code' => $display->code],
            'page_enabled' => $display->qr_page_enabled,
            // Sem WhatsApp configurado no catálogo, o destino cai pro catálogo
            'destination' => $display->qr_destination === Display::QR_WHATSAPP && $whatsapp ? 'whatsapp' : 'catalog',
            'links' => array_filter([
                'whatsapp' => $whatsapp,
                'catalog' => $company->hasCatalogActive() ? $this->qr->catalogUrl($display) : null,
                'instagram' => $instagram,
            ]),
        ]);
    }

    public function click(Request $request, string $slug, string $code)
    {
        [, $display] = $this->resolve($slug, $code);
        $data = $request->validate(['destination' => ['required', 'in:whatsapp,catalog,instagram']]);

        $this->qr->record($display, DisplayQr::TYPE_CLICK, $data['destination'], $request);

        return response()->noContent();
    }

    /** @return array{0: Company, 1: Display} */
    private function resolve(string $slug, string $code): array
    {
        $company = Company::where('slug', $slug)->first();
        abort_unless($company && $company->isPro(), 404, 'QR Code não encontrado.');

        $display = $this->qr->resolve($company, $code);
        abort_unless($display, 404, 'QR Code não encontrado.');

        return [$company, $display];
    }
}
