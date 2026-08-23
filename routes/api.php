<?php

use App\Http\Controllers\API\DeployController;
use Illuminate\Support\Facades\Route;

Route::post('/deploy', [DeployController::class, 'deploy']);
