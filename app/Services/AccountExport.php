<?php

namespace App\Services;

use App\Models\Company;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use ZipArchive;

/**
 * Portabilidade dos dados (LGPD, art. 18): um ZIP com um CSV por tipo de dado da empresa.
 * CSV com ";" e BOM UTF-8 — abre direto no Excel em português, acentos certos.
 */
class AccountExport
{
    /** Colunas que nunca saem: senhas, tokens de acesso e identificadores internos de pagamento. */
    private const SECRET_COLUMN = '/password|remember_token|token|stripe_id|pm_type|pm_last_four|visitor_hash|phone_key/';

    /** Gera o ZIP num arquivo temporário e devolve o caminho (quem chama apaga depois de enviar). */
    public function build(Company $company): string
    {
        $path = tempnam(sys_get_temp_dir(), 'export');
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Não foi possível gerar o arquivo de exportação.');
        }

        $zip->addFromString('LEIA-ME.txt', $this->readme($company));

        foreach ($this->datasets($company->id) as $name => $query) {
            $zip->addFromString("{$name}.csv", $this->csv($query));
        }

        $zip->close();

        return $path;
    }

    /** @return array<string, Builder> */
    private function datasets(int $companyId): array
    {
        $byCompany = fn (string $table) => DB::table($table)->where('company_id', $companyId)->orderBy('id');
        $productIds = DB::table('products')->where('company_id', $companyId)->select('id');
        $quoteIds = DB::table('quotes')->where('company_id', $companyId)->select('id');
        $displayIds = DB::table('displays')->where('company_id', $companyId)->select('id');

        return [
            'empresa' => DB::table('companies')->where('id', $companyId),
            'usuarios' => $byCompany('users'),
            'configuracoes' => $byCompany('settings'),
            'clientes' => $byCompany('customers'),
            'produtos' => $byCompany('products'),
            'produtos_variacoes' => DB::table('product_variations')->whereIn('product_id', $productIds)->orderBy('id'),
            'produtos_fotos' => DB::table('product_images')->whereIn('product_id', $productIds)->orderBy('id'),
            'produtos_ficha_tecnica' => DB::table('product_materials')->whereIn('product_id', $productIds)->orderBy('id'),
            'categorias_de_produto' => $byCompany('product_categories'),
            'colecoes' => $byCompany('product_collections'),
            'insumos' => $byCompany('materials'),
            'insumos_movimentacoes' => $byCompany('material_movements'),
            'categorias_de_insumo' => $byCompany('material_categories'),
            'equipamentos' => $byCompany('printers'),
            'canais_de_venda' => $byCompany('sales_channels'),
            'orcamentos_e_vendas' => $byCompany('quotes'),
            'orcamentos_e_vendas_itens' => DB::table('quote_items')->whereIn('quote_id', $quoteIds)->orderBy('id'),
            'orcamentos_e_vendas_etapas' => DB::table('quote_stage_events')->whereIn('quote_id', $quoteIds)->orderBy('id'),
            'solicitacoes_de_orcamento' => $byCompany('quote_requests'),
            'avaliacoes' => $byCompany('product_reviews'),
            'cashback' => $byCompany('cashback_entries'),
            'expositores' => $byCompany('displays'),
            'expositores_estoque' => DB::table('display_stock_lines')->whereIn('display_id', $displayIds)->orderBy('id'),
            'expositores_movimentacoes' => DB::table('display_stock_movements')->whereIn('display_id', $displayIds)->orderBy('id'),
            'expositores_visitas' => DB::table('display_visits')->whereIn('display_id', $displayIds)->orderBy('id'),
            'catalogo_banners' => $byCompany('catalog_banners'),
        ];
    }

    private function csv(Builder $query): string
    {
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");

        // Cabeçalho sai mesmo sem linhas, pra mostrar quais dados existem
        $columns = array_values(array_filter(
            Schema::getColumnListing($query->from),
            fn ($column) => ! preg_match(self::SECRET_COLUMN, $column),
        ));
        fputcsv($out, $columns, ';', '"', '');

        foreach ($query->cursor() as $row) {
            $row = (array) $row;
            fputcsv($out, array_map(fn ($column) => $row[$column] ?? null, $columns), ';', '"', '');
        }

        rewind($out);
        $content = stream_get_contents($out);
        fclose($out);

        return $content;
    }

    private function readme(Company $company): string
    {
        $date = now($company->timezone ?: 'America/Sao_Paulo')->format('d/m/Y H:i');

        return <<<TXT
        Exportação de dados — {$company->name}
        Gerada em {$date}

        Cada arquivo .csv traz um tipo de dado da sua empresa no Meu Custo Total (clientes, produtos,
        orçamentos e vendas, insumos etc.). Abra no Excel, Google Planilhas ou LibreOffice.
        Separador: ponto e vírgula (;). Codificação: UTF-8.

        Os nomes das colunas são os mesmos usados internamente pelo sistema. Os campos "id" e "*_id"
        ligam os arquivos entre si — por exemplo, "customer_id" em orcamentos_e_vendas.csv é o "id"
        do cliente em clientes.csv.

        As fotos não vão no arquivo: produtos_fotos.csv lista o caminho de cada uma.
        Senhas, chaves de acesso e dados do cartão nunca são exportados.
        Arquivo só com o cabeçalho = você ainda não tem nada desse tipo cadastrado.
        TXT;
    }
}
