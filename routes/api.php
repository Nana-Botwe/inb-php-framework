<?php

/*
|--------------------------------------------------------------------------
| API routes — all prefixed with /api, CORS + rate limited (60/min), no sessions/CSRF.
|--------------------------------------------------------------------------
| Authenticate with "Authorization: Bearer <token>" and ->middleware('auth:api').
|
| @var Core\Router $router
*/

use App\Http\Controllers\Api\TokenController;
use Core\Router;

$router->get('/health', fn () => ['status' => 'ok', 'time' => date(DATE_ATOM)]);

$router->post('/tokens', [TokenController::class, 'store'])->middleware('throttle:5,1');

$router->group(['middleware' => ['auth:api']], function (Router $router) {
    $router->get('/user', [TokenController::class, 'user']);
    $router->delete('/tokens/current', [TokenController::class, 'destroy']);
});
