<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Web routes (session, CSRF protection)
|--------------------------------------------------------------------------
| Only URLs listed here exist. "php console route:list" shows them all.
|
| @var Core\Router $router
*/

use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\ConfirmPasswordController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\TwoFactorChallengeController;
use App\Http\Controllers\DocsController;
use App\Http\Controllers\FeatureController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\TwoFactorController;
use Core\Router;

$router->get('/', [HomeController::class, 'welcome'])->name('home');

// Health check for uptime monitors / load balancers.
$router->get('/up', fn () => ['status' => 'up', 'time' => date(DATE_ATOM)])->name('health');

// Feature detail pages linked from the welcome page.
$router->get('/features/{feature}', [FeatureController::class, 'show'])->where('feature', '[a-z-]+')->name('features.show');

// Framework documentation (resources/docs/*.md). Disable with DOCS_ENABLED=false.
$router->get('/docs', [DocsController::class, 'index'])->name('docs.index');
$router->get('/docs/search', [DocsController::class, 'search'])->name('docs.search');
$router->get('/docs/{page}', [DocsController::class, 'show'])->where('page', '[a-z0-9-]+')->name('docs.show');

// ---------------------------------------------------------------- guests only
$router->group(['middleware' => ['guest']], function (Router $router) {
    $router->get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    $router->post('/login', [AuthenticatedSessionController::class, 'store'])->middleware('throttle:10,1');

    $router->get('/register', [RegisteredUserController::class, 'create'])->name('register');
    $router->post('/register', [RegisteredUserController::class, 'store'])->middleware('throttle:5,1');

    $router->get('/forgot-password', [PasswordResetController::class, 'request'])->name('password.request');
    $router->post('/forgot-password', [PasswordResetController::class, 'email'])->name('password.email')->middleware('throttle:5,1');
    $router->get('/reset-password/{token}', [PasswordResetController::class, 'edit'])->name('password.reset');
    $router->post('/reset-password', [PasswordResetController::class, 'update'])->name('password.update')->middleware('throttle:5,1');

    // Second login step for accounts with two-factor authentication.
    $router->get('/two-factor-challenge', [TwoFactorChallengeController::class, 'create'])->name('two-factor.challenge');
    $router->post('/two-factor-challenge', [TwoFactorChallengeController::class, 'store'])->middleware('throttle:10,1');
});

// ---------------------------------------------------------------- signed-in users
$router->group(['middleware' => ['auth']], function (Router $router) {
    $router->post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    // "Confirm your password" for sensitive pages (middleware: password.confirm).
    $router->get('/confirm-password', [ConfirmPasswordController::class, 'show'])->name('password.confirm');
    $router->post('/confirm-password', [ConfirmPasswordController::class, 'store'])->middleware('throttle:6,1');

    $router->get('/verify-email', [EmailVerificationController::class, 'notice'])->name('verification.notice');
    $router->get('/verify-email/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->name('verification.verify')->middleware('signed', 'throttle:6,1');
    $router->post('/email/verification-notification', [EmailVerificationController::class, 'resend'])
        ->name('verification.send')->middleware('throttle:6,1');

    // ------------------------------------------------------------ verified users
    $router->group(['middleware' => ['verified']], function (Router $router) {
        $router->get('/dashboard', [HomeController::class, 'dashboard'])->name('dashboard');

        $router->get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
        $router->patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
        $router->post('/profile/avatar', [ProfileController::class, 'avatar'])->name('profile.avatar')->middleware('throttle:10,1');
        $router->put('/profile/password', [ProfileController::class, 'password'])->name('profile.password')->middleware('throttle:6,1');
        $router->delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
        $router->post('/profile/logout-other-devices', [TwoFactorController::class, 'logoutOtherDevices'])->name('profile.logout-others')->middleware('password.confirm');

        // Two-factor authentication settings (re-enter password first).
        $router->group(['middleware' => ['password.confirm']], function (Router $router) {
            $router->post('/user/two-factor', [TwoFactorController::class, 'store'])->name('two-factor.enable');
            $router->post('/user/two-factor/confirm', [TwoFactorController::class, 'confirm'])->name('two-factor.confirm')->middleware('throttle:6,1');
            $router->post('/user/two-factor/recovery-codes', [TwoFactorController::class, 'recoveryCodes'])->name('two-factor.recovery-codes');
            $router->delete('/user/two-factor', [TwoFactorController::class, 'destroy'])->name('two-factor.disable');
        });

        $router->get('/payments', [PaymentController::class, 'create'])->name('payments.create');
        $router->post('/payments', [PaymentController::class, 'store'])->name('payments.store')->middleware('throttle:10,1');
        $router->get('/payments/callback/{gateway}', [PaymentController::class, 'callback'])->name('payments.callback');
    });

    // ------------------------------------------------------------ admins only
    // Admins: verified email + role + two-factor + recently confirmed password.
    $router->group(['prefix' => '/admin', 'middleware' => ['verified', 'role:admin', 'two-factor', 'password.confirm'], 'as' => 'admin.'], function (Router $router) {
        $router->get('/users', [UserController::class, 'index'])->name('users.index');
        $router->patch('/users/{user}/role', [UserController::class, 'updateRole'])->name('users.role');
    });
});

// ---------------------------------------------------------------- provider webhooks (signature-verified, no CSRF)
$router->post('/payments/webhook/{gateway}', [PaymentController::class, 'webhook'])
    ->name('payments.webhook')->where('gateway', '[a-z]+')->middleware('throttle:120,1');
