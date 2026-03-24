<?php

use App\Http\Controllers\TelegramWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return "hi";
});

Route::post('/telegram/webhook', TelegramWebhookController::class);
