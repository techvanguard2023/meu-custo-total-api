<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\EnforcesPlanLimits;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\OfferGroup;
use App\Models\OfferPost;
use App\Models\OfferSeries;
use App\Models\Product;
use App\Services\OfferMetrics;
use App\Services\OfferPublisher;
use App\Services\OfferTextAi;
use App\Services\OfferScheduler;
use App\Services\OfferSeriesManager;
use App\Services\WahaClient;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Grupos e canais de ofertas no WhatsApp (Pro, só o dono — ver TeamAccess). */
class OfferController extends Controller
{
    use EnforcesPlanLimits;

    public function __construct(private OfferSeriesManager $manager, private OfferPublisher $publisher) {}

    public function index(Request $request, OfferMetrics $metrics)
    {
        $this->requirePro($request, 'Grupos de ofertas');
        $company = $request->user()->company;

        $posts = OfferPost::where('company_id', $company->id)->with('deliveries')->latest('id')->limit(20)->get();
        $numbers = $metrics->forPosts($company, $posts);

        return response()->json([
            'series' => OfferSeries::where('company_id', $company->id)->with('groups')->latest('id')->get()
                ->map(fn (OfferSeries $s) => $this->seriesPayload($s))->values(),
            'shared_products' => $company->products()->where('share_in_offers', true)->count(),
            'ai_enabled' => app(OfferTextAi::class)->isConfigured(),
            'summary' => $metrics->summary($company),
            'sales_window_hours' => OfferMetrics::SALES_WINDOW_HOURS,
            'posts' => $posts->map(fn (OfferPost $p) => [
                    'id' => $p->id,
                    'product_name' => $p->product_name,
                    'origin' => $p->origin,
                    'created_at' => $p->created_at?->toIso8601String(),
                    'metrics' => $numbers[$p->id] ?? null,
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

    /** Texto próprio da oferta do produto (em branco = usa um trecho da descrição). */
    public function updateProductText(Request $request, Product $product)
    {
        $this->requirePro($request, 'Grupos de ofertas');
        abort_unless($product->company_id === $request->user()->company_id, 404);
        $data = $request->validate(['offer_text' => ['nullable', 'string', 'max:700']]);

        $product->forceFill(['offer_text' => trim((string) ($data['offer_text'] ?? '')) ?: null])->saveQuietly();

        return response()->json(['offer_text' => $product->offer_text]);
    }

    /** Como a oferta vai sair no WhatsApp (com o texto ainda não salvo, pra revisar). */
    public function preview(Request $request)
    {
        $this->requirePro($request, 'Grupos de ofertas');
        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'offer_text' => ['nullable', 'string', 'max:700'],
        ]);
        $company = $request->user()->company;
        $product = $company->products()->findOrFail($data['product_id']);

        return response()->json([
            'message' => $this->publisher->message($company, $product, null, array_key_exists('offer_text', $data) ? (string) $data['offer_text'] : null),
        ]);
    }

    /** Gera um texto de oferta pela IA (n8n). Não salva: o lojista revisa e salva. */
    public function aiText(Request $request, OfferTextAi $ai)
    {
        $this->requirePro($request, 'Grupos de ofertas');
        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'instructions' => ['nullable', 'string', 'max:300'],
        ]);
        $company = $request->user()->company;
        $product = $company->products()->findOrFail($data['product_id']);

        return response()->json(['text' => $ai->generate($company, $product, $data['instructions'] ?? null)]);
    }

    /** O catálogo aberto pelo link de uma oferta (?o=CODIGO) avisa o clique. */
    public function publicClick(Request $request, string $code, OfferMetrics $metrics)
    {
        if (! $request->header('X-No-Track')) {
            $metrics->recordClick($code, $request);
        }

        return response()->noContent();
    }

    /** Agenda do envio automático, com o próximo horário e os produtos que vão nele. */
    public function schedule(Request $request, OfferScheduler $scheduler)
    {
        $this->requirePro($request, 'Grupos de ofertas');

        return response()->json($this->schedulePayload($request->user()->company, $scheduler));
    }

    public function updateSchedule(Request $request, OfferScheduler $scheduler)
    {
        $this->requirePro($request, 'Grupos de ofertas');

        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'days' => ['required', 'array', 'min:1', 'max:7'],
            'days.*' => ['integer', 'between:1,7', 'distinct'],
            'times' => ['required', 'array', 'min:1', 'max:6'],
            'times.*' => ['string', 'date_format:H:i', 'distinct'],
            'products_per_run' => ['required', 'integer', 'min:1', 'max:10'],
            'only_in_stock' => ['required', 'boolean'],
        ], [
            'days.min' => 'Escolha pelo menos um dia.',
            'times.min' => 'Informe pelo menos um horário.',
            'times.max' => 'No máximo 6 horários por dia.',
            'times.*.distinct' => 'Há horários repetidos.',
        ]);

        $company = $request->user()->company;
        $schedule = $scheduler->forCompany($company);

        $data['days'] = collect($data['days'])->map(fn ($d) => (int) $d)->sort()->values()->all();
        $data['times'] = collect($data['times'])->sort()->values()->all();
        $schedule->fill($data);

        // Salvar a agenda nunca dispara um horário que acabou de passar (só os próximos)
        $schedule->last_slot = $scheduler->dueSlot($schedule, now($company->timezone ?: 'America/Sao_Paulo')) ?? $schedule->last_slot;

        $schedule->save();

        return response()->json($this->schedulePayload($company, $scheduler));
    }

    private function schedulePayload(Company $company, OfferScheduler $scheduler): array
    {
        $schedule = $scheduler->forCompany($company);
        $eligible = $scheduler->eligibleProducts($company, $schedule);
        $next = $scheduler->nextRun($schedule, now($company->timezone ?: 'America/Sao_Paulo'));

        return [
            'enabled' => $schedule->enabled,
            'days' => $schedule->days,
            'times' => $schedule->times,
            'products_per_run' => $schedule->products_per_run,
            'only_in_stock' => $schedule->only_in_stock,
            'next_run_at' => $next?->toIso8601String(),
            'eligible_count' => $eligible->count(),
            // Quem vai no próximo envio (rodízio: nunca divulgados primeiro, depois os mais antigos)
            'next_products' => $eligible->take($schedule->products_per_run)->map(fn (Product $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'last_sent_at' => $p->offers_last_sent_at?->toIso8601String(),
            ])->values(),
        ];
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
