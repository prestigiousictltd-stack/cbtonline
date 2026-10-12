<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Auth;
use App\Services\AuthService;

if (Auth::clientCheck()) {
    (new AuthService())->logout('client');
}

flash('success', 'You have been signed out.');
redirect('/login');