<?php

use App\Http\Controllers\CashBalanceController;
use App\Http\Controllers\InstrumentController;
use App\Http\Controllers\PortfolioController;
use App\Http\Controllers\TransactionController;
use Illuminate\Support\Facades\Route;

Route::apiResource('instruments', InstrumentController::class)->only('store');
Route::apiResource('clients.transactions', TransactionController::class)->only(['index', 'store']);
Route::apiSingleton('clients.cash-balance', CashBalanceController::class)->only('show');
Route::apiSingleton('clients.portfolio', PortfolioController::class)->only('show');
