<?php

use App\Http\Controllers\TransactionController;
use Illuminate\Support\Facades\Route;

Route::post('/clients/{client}/transactions', [TransactionController::class, 'store']);
