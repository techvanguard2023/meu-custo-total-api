<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\EnforcesPlanLimits;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\DeliveryCalendar;
use App\Support\Delivery;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Datas de entrega disponíveis (Agenda, Fase 3):
 *  - availability: o app mostra, ao marcar a data, se o dia está fechado, bloqueado ou cheio (só aviso — o
 *    lojista pode marcar mesmo assim);
 *  - publicDates: o carrinho do catálogo oferece as próximas datas livres (quando a loja liga "o cliente escolhe");
 *  - botDates: o mesmo para o bot do WhatsApp (token de integração).
 */
class DeliveryDatesController extends Controller
{
    use EnforcesPlanLimits;

    public function __construct(private DeliveryCalendar $calendar) {}

    public function availability(Request $request)
    {
        $this->requirePro($request, 'Agenda');
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'quote_id' => ['sometimes', 'nullable', 'integer'],
            'products' => ['sometimes', 'array', 'max:50'],
            'products.*' => ['integer'],
        ]);
        $from = Carbon::parse($data['from'], Delivery::TZ);
        $to = Carbon::parse($data['to'], Delivery::TZ);
        abort_if($from->diffInDays($to) > DeliveryCalendar::MAX_RANGE_DAYS, 422, 'Período muito longo.');
        $company = $request->user()->company;

        return [
            'rules' => DeliveryCalendar::rules($company),
            'days' => $this->calendar->days($company, $from, $to, $data['products'] ?? [], $data['quote_id'] ?? null),
        ];
    }

    public function publicDates(Request $request, string $token)
    {
        $company = Company::active()->where(fn ($q) => $q->where('slug', $token)->orWhere('catalog_token', $token))->first();
        abort_unless($company && $company->hasCatalogActive(), 404, 'Catálogo não encontrado.');

        return $this->offer($company, $this->productIds($request));
    }

    public function botDates(Request $request)
    {
        return $this->offer($request->user()->company, $this->productIds($request));
    }

    private function offer(Company $company, array $productIds): array
    {
        if (! DeliveryCalendar::customerChooses($company)) {
            return ['enabled' => false, 'dates' => [], 'methods' => []];
        }
        $rules = DeliveryCalendar::rules($company);
        $dates = $this->calendar->nextAvailable($company, $productIds, 14);

        return [
            'enabled' => true,
            'dates' => array_map(fn ($d) => ['date' => $d, 'label' => Delivery::dateLabel($d)], $dates),
            'methods' => array_map(fn ($m) => ['value' => $m, 'label' => Delivery::METHOD_LABELS[$m]], array_values(array_intersect(Delivery::METHODS, $rules['methods']))),
        ];
    }

    /** ?products=1,2,3 ou ?products[]=1 — ids dos produtos do pedido (pro prazo de produção). */
    private function productIds(Request $request): array
    {
        $raw = $request->input('products', []);
        $list = is_array($raw) ? $raw : explode(',', (string) $raw);

        return array_slice(array_values(array_filter(array_map('intval', $list))), 0, 50);
    }
}
