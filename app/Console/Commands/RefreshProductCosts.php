<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\ProductRecipe;
use Illuminate\Console\Command;

/**
 * Recalcula o custo (e o preço automático) de todos os produtos com custo pela ficha. Rodar uma vez depois de
 * mudar a regra do cálculo — ex.: acabamento e embalagem saíram da taxa de falha.
 */
class RefreshProductCosts extends Command
{
    protected $signature = 'products:refresh-costs';

    protected $description = 'Recalcula o custo pela ficha técnica de todos os produtos';

    public function handle(ProductRecipe $recipe): int
    {
        $companyIds = Product::where('auto_cost', true)->distinct()->pluck('company_id');

        foreach ($companyIds as $companyId) {
            $recipe->refreshCompany($companyId);
        }

        $this->info("Custos recalculados em {$companyIds->count()} loja(s).");

        return self::SUCCESS;
    }
}
