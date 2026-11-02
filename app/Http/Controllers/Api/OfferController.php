<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\EnforcesPlanLimits;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\OfferGroup;
use App\Models\OfferPost;
use App\Models\OfferSeries;
use App\Models\Product;
use App\Services\OfferPublisher;
use App\Services\OfferSeriesManager;
use App\Services\WahaClient;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Grupos e canais de ofertas no WhatsApp (Pro, só o dono — ver TeamAccess). */
class OfferController extends Controller
{
    use EnforcesPlanLimits;

    public function __construct(private OfferSeriesManager $manager, private OfferPublisher $publisher) {}

    public function index(Request $request)
    {
        $this->requirePro($request, 'Grupos de ofertas');
        $company = $request->user()->company;

        return response()->json([
            'series' => OfferSeries::where('company_id', $company->id)->with('groups')->latest('id')->get()
                ->map(fn (OfferSeries $s) => $this->seriesPayload($s))->values(),
            'shared_products' => $company->products()->where('share_in_offers', true)->count(),
            'posts' => OfferPost::where('company_id', $company->id)->with('deliveries')->latest('id')->limit(20)->get()
                ->map(fn (OfferPost $p) => [
                    'id' => $p->id,
                    'product_name' => $p->product_name,
                    'created_at' => $p->created_at?->toIso8601String(),
                    'deliveries' => $p->deliveries->map(fn ($d) => [
                        'target' => $d->target_label,
                        'status' => $d->status,
                        'error' => $d->error,
                        'sent_at' => $d->sent_at?->toIso8601String(),
                    ])->values(),
                ])->values(),
        ]);
    }

    /** Canais que a loja já administra no WhatsApp (pra usar um existente em vez de criar outro). */
    public function channels(Request $request, WahaClient $waha)
    {
        $this->requirePro($request, 'Grupos de ofertas');
        $session = $this->manager->ensureConnected($request->user()->company);

        return response()->json($waha->listOwnChannels($session));
    }

    public function store(Request $request)
    {
        $this->requirePro($request, 'Grupos de ofertas');

        $data = $request->validate([
            'kind' => ['required', Rule::in([OfferSeries::KIND_GROUP, OfferSeries::KIND_CHANNEL])],
            'name' => ['required', 'string', 'min:3', 'max:60'],
            'description' => ['nullable', 'string', 'max:500'],
            'capacity' => ['nullable', 'integer', 'min:50', 'max:1000'],
            'backup_admin_phone' => ['nullable', 'string', 'max:30', 'regex:/^[\d\s()+\-]{10,}$/'],
            'channel_jid' => ['nullable', 'string', 'max:100'],
        ], [
            'backup_admin_phone.regex' => 'Informe o WhatsApp do segundo administrador com DDD.',
            'capacity.max' => 'O WhatsApp aceita no máximo 1.024 pessoas por grupo — use até 1.000.',
        ]);

        $series = $this->manager->create($request->user()->company, $data);

        return response()->json($this->seriesPayload($series), 201);
    }

    /** Muda o que vale daqui pra frente: descrição e capacidade dos próximos grupos, segundo admin, pausa. */
    public function update(Request $request, OfferSeries $series)
    {
        $this->requirePro($request, 'Grupos de ofertas');
        $this->authorizeSeries($request, $series);

        $data = $request->validate([
            'capacity' => ['sometimes', 'integer', 'min:50', 'max:1000'],
            'backup_admin_phone' => ['sometimes', 'nullable', 'string', 'max:30', 'regex:/^[\d\s()+\-]{10,}$/'],
            'active' => ['sometimes', 'boolean'],
        ]);

        $series->update($data);

        // Segundo admin informado/trocado: o número novo entra em todos os grupos (o anterior, se havia,
        // continua admin no WhatsApp até alguém tirar pelo celular)
        if ($series->wasChanged('backup_admin_phone') && $series->backup_admin_phone) {
            $series->groups()->update(['backup_admin_added' => false]);
            $this->manager->addBackupAdminToGroups($series->load('company'));
        }

        return response()->json($this->seriesPayload($series->fresh('groups')));
    }

    /** Para de usar a série no sistema. Os grupos/canal continuam existindo no WhatsApp. */
    public function destroy(Request $request, OfferSeries $series)
    {
        $this->requirePro($request, 'Grupos de ofertas');
        $this->authorizeSeries($request, $series);
        $series->delete();

        return response()->json(['message' => 'Removido do sistema. Os grupos/canal continuam no WhatsApp.']);
    }

    /** Botão "Atualizar": confere a lotação agora (e abre o próximo grupo, se precisar). */
    public function check(Request $request, OfferSeries $series)
    {
        $this->requirePro($request, 'Grupos de ofertas');
        $this->authorizeSeries($request, $series);
        $this->manager->checkGroups($series->load(['company', 'groups']));

        return response()->json($this->seriesPayload($series->fresh('groups')));
    }

    /** "Enviar agora": o produto entra na fila de todos os grupos/canais ativos. */
    public function post(Request $request)
    {
        $this->requirePro($request, 'Grupos de ofertas');
        $data = $request->validate(['product_id' => ['required', 'integer']]);
        $company = $request->user()->company;
        $product = $company->products()->findOrFail($data['product_id']);

        abort_unless($product->active && $product->show_in_catalog, 422, 'Só dá pra divulgar produto ativo e visível no catálogo (o link da oferta leva pra ele).');

        $count = $this->publisher->queue($company, $product);
        abort_if($count === 0, 422, 'Crie um grupo ou canal de ofertas antes de divulgar.');

        return response()->json([
            'deliveries' => $count,
            'message' => $count === 1 ? 'Oferta na fila — sai em até 1 minuto.' : "Oferta na fila — sai um grupo/canal por minuto ({$count} no total).",
        ]);
    }

    /** Liga/desliga o produto na divulgação das ofertas. */
    public function toggleProduct(Request $request, Product $product)
    {
        $this->requirePro($request, 'Grupos de ofertas');
        abort_unless($product->company_id === $request->user()->company_id, 404);
        $data = $request->validate(['share' => ['required', 'boolean']]);

        abort_if(
            $data['share'] && ! ($product->active && $product->show_in_catalog),
            422,
            'Só dá pra divulgar produto ativo e visível no catálogo (o link da oferta leva pra ele).'
        );

        $product->forceFill(['share_in_offers' => $data['share']])->saveQuietly();

        return response()->json(['share_in_offers' => $product->share_in_offers]);
    }

    /** Link fixo público (/ofertas/{slug}): leva ao grupo com vaga, ou ao canal. */
    public function publicShow(string $slug)
    {
        $series = OfferSeries::where('slug', $slug)->where('active', true)
            ->whereHas('company', fn ($q) => $q->active()->where('plan', Company::PLAN_PRO))
            ->with('company')
            ->first();
        abort_unless($series, 404, 'Este link de ofertas não está mais disponível.');

        return response()->json([
            'name' => $series->name,
            'store' => $series->company->name,
            'logo_url' => $series->company->logo_url,
            'kind' => $series->kind,
            'url' => $series->isChannel() ? $series->channel_invite : $series->currentGroup()?->invite_url,
        ]);
    }

    private function seriesPayload(OfferSeries $series): array
    {
        return [
            'id' => $series->id,
            'kind' => $series->kind,
            'name' => $series->name,
            'description' => $series->description,
            'capacity' => $series->capacity,
            'backup_admin_phone' => $series->backup_admin_phone,
            'active' => $series->active,
            'public_url' => rtrim(config('services.frontend_url'), '/').'/ofertas/'.$series->slug,
            'channel_invite' => $series->channel_invite,
            'groups' => $series->groups->map(fn (OfferGroup $g) => [
                'id' => $g->id,
                'number' => $g->number,
                'name' => $series->groupName($g->number),
                'invite_url' => $g->invite_url,
                'participants_count' => $g->participants_count,
                'full' => $g->full_at !== null,
                'backup_admin_added' => $g->backup_admin_added,
                'checked_at' => $g->participants_checked_at?->toIso8601String(),
            ])->values(),
        ];
    }

    private function authorizeSeries(Request $request, OfferSeries $series): void
    {
        abort_unless($series->company_id === $request->user()->company_id, 404);
    }
}
