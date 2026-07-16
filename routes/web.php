<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'name' => 'Meu Custo Total API',
        'status' => 'ok',
    ]);
});
