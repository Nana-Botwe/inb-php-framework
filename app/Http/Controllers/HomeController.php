<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Core\App;
use Core\Response;

final class HomeController extends Controller
{
    public function welcome(): Response
    {
        return $this->view('welcome', [
            'version' => App::VERSION,
            'phpVersion' => PHP_VERSION,
            'features' => FeatureController::all(),
        ]);
    }

    public function dashboard(): Response
    {
        $user = auth();

        return $this->view('dashboard', [
            'user' => $user,
            'payments' => $user?->payments()->latest()->limit(5)->get(),
        ]);
    }
}
