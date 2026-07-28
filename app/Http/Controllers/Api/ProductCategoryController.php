<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProductCategory;

/**
 * Lista fixa (igual pra todas as empresas), mantida na tabela product_categories —
 * pra adicionar uma categoria nova basta inserir uma linha ali, sem precisar de deploy.
 */
class ProductCategoryController extends Controller
{
    public function index()
    {
        return ProductCategory::orderBy('name')->get();
    }
}
