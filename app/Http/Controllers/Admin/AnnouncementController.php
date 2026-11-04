<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AdminAudit;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Comunicados da plataforma: faixa dentro do app (todas as lojas ou só um plano), com início e fim. */
class AnnouncementController extends Controller
{
    public function index()
    {
        return DB::table('platform_announcements')->latest('id')->get()->map(fn ($row) => $this->present($row));
    }

    public function store(Request $request)
    {
        $id = DB::table('platform_announcements')->insertGetId($this->validated($request) + ['created_at' => now(), 'updated_at' => now()]);
        AdminAudit::log($request, 'announcement.create', null, null, ['id' => $id, 'title' => $request->input('title')]);

        return response()->json($this->present(DB::table('platform_announcements')->find($id)), 201);
    }

    public function update(Request $request, int $announcement)
    {
        abort_unless(DB::table('platform_announcements')->where('id', $announcement)->exists(), 404);
        DB::table('platform_announcements')->where('id', $announcement)->update($this->validated($request) + ['updated_at' => now()]);
        AdminAudit::log($request, 'announcement.update', null, null, ['id' => $announcement, 'title' => $request->input('title')]);

        return response()->json($this->present(DB::table('platform_announcements')->find($announcement)));
    }

    public function destroy(Request $request, int $announcement)
    {
        DB::table('platform_announcements')->where('id', $announcement)->delete();
        AdminAudit::log($request, 'announcement.delete', null, null, ['id' => $announcement]);

        return response()->noContent();
    }

    /** Pro app (qualquer pessoa logada): os comunicados valendo agora pro plano da loja. */
    public function active(Request $request)
    {
        $plan = $request->user()->company?->plan ?? 'free';

        return DB::table('platform_announcements')
            ->where('active', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
            ->whereIn('audience', ['all', $plan])
            ->latest('id')
            ->get(['id', 'title', 'message', 'level', 'link_url', 'link_label']);
    }

    /** Datas com fuso (o banco guarda em UTC, sem fuso): sem isso a tela mostraria o horário deslocado. */
    private function present(object $row): object
    {
        foreach (['starts_at', 'ends_at', 'created_at', 'updated_at'] as $column) {
            $row->{$column} = $row->{$column} ? Carbon::parse($row->{$column}, 'UTC')->toIso8601String() : null;
        }

        return $row;
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'message' => ['nullable', 'string', 'max:1000'],
            'level' => ['required', Rule::in(['info', 'warning', 'success'])],
            'audience' => ['required', Rule::in(['all', 'free', 'essential', 'pro'])],
            // Endereço completo (https://…) ou uma tela do próprio app (/suporte, /divulgacao…)
            'link_url' => ['nullable', 'string', 'max:500', 'regex:~^(https?://[^\s]+|/(?!/)[^\s]*)$~i'],
            'link_label' => ['nullable', 'string', 'max:40'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'active' => ['required', 'boolean'],
        ], [
            'ends_at.after_or_equal' => 'O fim precisa ser depois do início.',
            'link_url.regex' => 'Use um endereço completo (https://…) ou uma tela do app começando com / (ex: /suporte).',
        ]);

        // A tela manda ISO com fuso ("2026-11-04T18:00:00.000Z"); o MySQL só aceita "2026-11-04 18:00:00" (UTC)
        foreach (['starts_at', 'ends_at'] as $column) {
            $data[$column] = ! empty($data[$column]) ? Carbon::parse($data[$column])->utc()->format('Y-m-d H:i:s') : null;
        }

        return $data;
    }
}
