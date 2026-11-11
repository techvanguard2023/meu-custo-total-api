<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tour;
use App\Models\UserTour;
use App\Services\AdminAudit;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Tours do app (react-joyride) na área admin: passos, tela, plano e versão — sem deploy.
 * O alvo de cada passo é um seletor CSS de um elemento da tela (os elementos do app têm `id` estáveis).
 */
class TourController extends Controller
{
    public function index()
    {
        return Tour::orderBy('sort_order')->orderBy('id')->get()->map(fn (Tour $tour) => $this->present($tour));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $tour = Tour::create($data + ['version' => 1]);
        AdminAudit::log($request, 'tour.create', null, null, ['slug' => $tour->slug]);

        return response()->json($this->present($tour), 201);
    }

    /** `show_again`: sobe a versão — quem já viu (ou a prévia) vê de novo. */
    public function update(Request $request, Tour $tour)
    {
        $data = $this->validated($request, $tour);
        $showAgain = $request->boolean('show_again');
        $tour->fill($data);
        if ($showAgain) {
            $tour->version++;
        }
        $tour->save();
        AdminAudit::log($request, 'tour.update', null, null, ['slug' => $tour->slug, 'version' => $tour->version, 'show_again' => $showAgain]);

        return response()->json($this->present($tour));
    }

    public function destroy(Request $request, Tour $tour)
    {
        $slug = $tour->slug;
        $tour->delete();
        // Marcações de "visto" deste slug: sem o tour não servem pra nada (e não travam um tour novo com o mesmo slug)
        UserTour::where('tour_id', $slug)->delete();
        AdminAudit::log($request, 'tour.delete', null, null, ['slug' => $slug]);

        return response()->noContent();
    }

    private function validated(Request $request, ?Tour $tour = null): array
    {
        $data = $request->validate([
            // O slug identifica as marcações de "visto": não muda depois de criado
            'slug' => $tour ? ['prohibited'] : ['required', 'string', 'max:60', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/', Rule::unique('tours', 'slug')],
            'title' => ['required', 'string', 'max:120'],
            'plan' => ['required', Rule::in(Tour::PLANS)],
            // Caminho da tela; em tela com abas, a aba vem na âncora (/settings#precos)
            'route' => ['required', 'string', 'max:200', 'regex:~^/[^\s?#]*(#[a-z0-9-]+)?$~'],
            'steps' => ['required', 'array', 'min:1', 'max:15'],
            'steps.*.target' => ['required', 'string', 'max:300'],
            'steps.*.title' => ['required', 'string', 'max:120'],
            'steps.*.content' => ['required', 'string', 'max:600'],
            'steps.*.placement' => ['nullable', Rule::in(Tour::PLACEMENTS)],
            'preview_title' => ['required', 'string', 'max:120'],
            'preview_content' => ['required', 'string', 'max:600'],
            'active' => ['required', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:-9999', 'max:9999'],
        ], [
            'slug.regex' => 'O identificador usa só letras minúsculas, números e hífen (ex.: funcionarios-impostos).',
            'slug.unique' => 'Já existe um tour com esse identificador.',
            'slug.prohibited' => 'O identificador não muda depois de criado.',
            'route.regex' => 'A tela é o caminho do app, começando com / (ex.: /cashier). Em tela com abas, a aba vem depois do # (ex.: /settings#precos).',
            'steps.required' => 'Cadastre pelo menos um passo.',
            'steps.min' => 'Cadastre pelo menos um passo.',
            'steps.*.target.required' => 'Informe o alvo (seletor) de cada passo.',
            'steps.*.title.required' => 'Informe o título de cada passo.',
            'steps.*.content.required' => 'Informe o texto de cada passo.',
        ]);

        $data['steps'] = collect($data['steps'])->map(fn ($s) => array_filter([
            'target' => trim($s['target']),
            'title' => trim($s['title']),
            'content' => trim($s['content']),
            'placement' => $s['placement'] ?? null,
        ], fn ($v) => $v !== null && $v !== 'auto'))->values()->all();
        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);

        return $data;
    }

    private function present(Tour $tour): array
    {
        return [
            'id' => $tour->id,
            'slug' => $tour->slug,
            'title' => $tour->title,
            'plan' => $tour->plan,
            'version' => $tour->version,
            'route' => $tour->route,
            'steps' => $tour->steps ?? [],
            'preview_title' => $tour->preview_title,
            'preview_content' => $tour->preview_content,
            'active' => $tour->active,
            'sort_order' => $tour->sort_order,
            // Quantas pessoas já viram a versão atual (tour completo / prévia)
            'seen_full' => UserTour::where('tour_id', $tour->slug)->where('version', $tour->version)->where('kind', 'full')->count(),
            'seen_preview' => UserTour::where('tour_id', $tour->slug)->where('version', $tour->version)->where('kind', 'preview')->count(),
            'updated_at' => $tour->updated_at,
        ];
    }
}
