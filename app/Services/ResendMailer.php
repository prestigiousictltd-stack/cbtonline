<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Logger;

final class ResendMailer
{
    private const API_URL = 'https://api.resend.com/emails';

    public function __construct(
        private readonly string $apiKey,
        private readonly string $fromAddress,
        private readonly string $fromName = APP_NAME
    ) {}

    public static function fromEnv(): self
    {
        $apiKey = (string) env('RESEND_API_KEY', '');
        $from   = (string) env('RESEND_FROM_EMAIL', 'onboarding@resend.dev');
        $name   = (string) env('RESEND_FROM_NAME', APP_NAME);

        return new self($apiKey, $from, $name);
    }

    /**
     * @param string $to          Recipient email address.
     * @param string $subject     Email subject line.
     * @param string $htmlBody    HTML body of the email.
     * @return bool               True on 2xx response, false otherwise.
     */
    public function send(string $to, string $subject, string $htmlBody): bool
    {
        if ($this->apiKey === '') {
            Logger::error('ResendMailer: RESEND_API_KEY is not set.');
            return false;
        }

        $payload = [
            'from'    => sprintf('%s <%s>', $this->fromName, $this->fromAddress),
            'to'      => [$to],
            'subject' => $subject,
            'html'    => $htmlBody,
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => self::API_URL,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        if (APP_ENV === 'local') {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        }

        $raw  = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            Logger::error('ResendMailer: cURL error', ['error' => $err]);
            return false;
        }
        if ($http < 200 || $http >= 300) {
            Logger::error('ResendMailer: API error', [
                'http'   => $http,
                'body'   => substr((string) $raw, 0, 500),
                'to'     => $to,
            ]);
            return false;
        }

        Logger::info('ResendMailer: email accepted', ['to' => $to, 'subject' => $subject]);
        return true;
    }
}
