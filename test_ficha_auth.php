<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$request = Illuminate\Http\Request::create('/api/gimnasio/entrenamiento/fichas', 'GET');
// We need to bypass Sanctum to test just the controller logic for AppFichaController
$controller = app()->make(\App\Http\Controllers\Personas\AppFichaController::class);
$req = Illuminate\Http\Request::create('/api/app/fichas', 'GET');
$user = \App\Models\User::find(1);
$req->setUserResolver(function () use ($user) { return $user; });
$response = $controller->getFichas($req);
echo $response->getContent();
