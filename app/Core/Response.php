<?php
declare(strict_types=1);

namespace App\Core;

final class Response
{
    /** Emit standard security headers on every response. */
    public static function emitSecurityHeaders(): void
    {
        if (headers_sent()) return;

        // Prevent the browser from guessing content types.
        header('X-Content-Type-Options: nosniff');

        // Prevent the site from being framed (clickjacking).
        header('X-Frame-Options: DENY');

        // Limit referrer information.
        header('Referrer-Policy: strict-origin-when-cross-origin');

        // Disable browser features we don't need.
        header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=()');

        // Strict CSP for our own pages. Bootstrap CDN and the two payment
        // gateways must be allowed; everything else is denied.
        // `frame-ancestors 'none'` supersedes X-Frame-Options for modern browsers.
        // Allow inline scripts because several pages use them (task-timer etc.)
        // — for stricter deployments move them to external files and drop
        // 'unsafe-inline'.
        $csp = implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://checkout.paystack.com https://checkout.flutterwave.com",
            "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net",
            "img-src 'self' data: https:",
            "font-src 'self' data: https://cdn.jsdelivr.net",
            "connect-src 'self' https://api.paystack.co https://api.flutterwave.com",
            "form-action 'self' https://checkout.paystack.com https://checkout.flutterwave.com",
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "object-src 'none'",
        ]);
        header('Content-Security-Policy: ' . $csp);

        // Only send HSTS when the request arrived over HTTPS.
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        if ($isHttps) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }

    public static function json(array $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function redirect(string $path): never
    {
        redirect($path);
    }

    public static function text(string $body, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        echo $body;
        exit;
    }
}