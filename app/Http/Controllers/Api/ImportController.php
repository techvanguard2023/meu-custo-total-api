<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DataImport;
use App\Services\ProductPhotoImport;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Importar planilha: POST /products/import, /materials/import e /customers/import, com as linhas já ligadas aos
 * campos ({campo: texto, _line: nº da linha}). dry_run = só simula (a tela mostra o resultado antes de gravar).
 * Produtos e insumos: só o dono (TeamAccess); clientes: dono e vendas. Todos os planos — o Grátis importa até o
 * limite dele. Fotos por link (produtos) são baixadas depois — GET /products/import/photos/{lote} mostra o andamento.
 */
class ImportController extends Controller
{
    public function __construct(private DataImport $import) {}

    public function products(Request $request)
    {
        return $this->run($request, 'products');
    }

    public function materials(Request $request)
    {
        return $this->run($request, 'materials');
    }

    public function customers(Request $request)
    {
        return $this->run($request, 'customers');
    }

    /** Andamento das fotos por link de uma importação de produtos (a tela consulta enquanto baixa). */
    public function photos(Request $request, string $batch, ProductPhotoImport $photos)
    {
        abort_unless(Str::isUuid($batch), 404);
        $status = $photos->status($request->user()->company_id, $batch);
        abort_unless($status, 404, 'Essa importação de fotos não existe mais.');

        return response()->json($status);
    }

    private function run(Request $request, string $type)
    {
        $data = $request->validate([
            'rows' => ['required', 'array', 'min:1', 'max:'.DataImport::MAX_ROWS],
            'rows.*' => ['array'],
            'mode' => ['required', Rule::in(DataImport::MODES)],
            'dry_run' => ['sometimes', 'boolean'],
        ], [
            'rows.max' => 'A planilha pode ter até '.DataImport::MAX_ROWS.' linhas por vez. Divida em partes.',
            'rows.required' => 'A planilha não tem linhas para importar.',
        ]);

        return response()->json(
            $this->import->run($request->user()->company, $type, $data['rows'], $data['mode'], (bool) ($data['dry_run'] ?? false))
        );
    }
}
