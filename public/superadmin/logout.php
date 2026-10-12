<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Services\AuthService;

if (Auth::superAdminCheck()) {
    (new AuthService())->logout('superadmin');
}
flash('success', 'Signed out.');
redirect('/superadmin/login');