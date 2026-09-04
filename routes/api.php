<?php

use App\Http\Controllers\PortfolioController;
use App\Http\Controllers\TransactionController;
use Illuminate\Support\Facades\Route;

Route::post('/clients/{client}/transactions', [TransactionController::class, 'store']);
Route::get('/clients/{client}/transactions', [TransactionController::class, 'index']);
Route::get('/clients/{client}/cash-balance', [PortfolioController::class, 'cashBalance']);
Route::get('/clients/{client}/portfolio', [PortfolioController::class, 'portfolio']);
