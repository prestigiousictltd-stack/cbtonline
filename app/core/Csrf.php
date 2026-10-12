<?php
declare(strict_types=1);

namespace App\Core;

final class Csrf
{
    public static function bootstrap(): void
    {
        if (empty($_SESSION[CSRF_TOKEN_KEY])) {
            $_SESSION[CSRF_TOKEN_KEY] = bin2hex(random_bytes(32));
        }
    }

    public static function token(): string
    {
        if (empty($_SESSION[CSRF_TOKEN_KEY])) {
            self::bootstrap();
        }
        return (string) $_SESSION[CSRF_TOKEN_KEY];
    }

    public static function field(): string
    {
        return sprintf(
            '<input type="hidden" name="%s" value="%s">',
            CSRF_FIELD_NAME,
            e(self::token())
        );
    }

    public static function check(?string $token): bool
    {
        return is_string($token)
            && $token !== ''
            && !empty($_SESSION[CSRF_TOKEN_KEY])
            && hash_equals((string) $_SESSION[CSRF_TOKEN_KEY], $token);
    }

    /** Verify or abort with a friendly message. */
    public static function verifyOrFail(?string $token, string $redirectTo): void
    {
        if (self::check($token)) {
            return;
        }
        flash('danger', 'Your session expired. Please try again.');
        redirect($redirectTo);
    }

    public static function rotate(): void
    {
        $_SESSION[CSRF_TOKEN_KEY] = bin2hex(random_bytes(32));
    }
}