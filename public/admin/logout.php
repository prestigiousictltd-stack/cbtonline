<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Services\AuthService;

if (Auth::adminCheck()) {
    (new AuthService())->logout('admin');
}
flash('success', 'Signed out.');
redirect('/admin/login');