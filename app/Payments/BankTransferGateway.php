<?php
declare(strict_types=1);

namespace App\Payments;

use App\Core\Settings;

final class BankTransferGateway implements PaymentGatewayInterface
{
    public function name(): string  { return 'bank_transfer'; }
    public function label(): string { return 'Bank Transfer'; }

    /**
     * Bank transfer is considered "configured" when the account details have
     * been filled in via /superadmin/settings. If any of the three core fields
     * are empty we hide it from the funding page.
     */
    public function isConfigured(): bool
    {
        $details = Settings::get('bank_transfer_details', []);
        if (!is_array($details)) {
            return false;
        }
        $bank    = trim((string) ($details['bank_name']      ?? ''));
        $account = trim((string) ($details['account_name']   ?? ''));
        $number  = trim((string) ($details['account_number'] ?? ''));

        return $bank !== '' && $account !== '' && $number !== '';
    }

    /**
     * Bank transfer does not receive webhooks. Verification is manual, done
     * by a Super Admin via /superadmin/payments.
     */
    public function supportsWebhook(): bool
    {
        return false;
    }

    public function initialize(array $data): array
    {
        return ['ok' => true, 'authorization_url' => url('/client/fund-wallet')];
    }

    public function verify(string $reference): array
    {
        return ['ok' => false, 'error' => 'Bank transfers are verified manually by Super Admin.'];
    }

    public function handleWebhook(array $headers, string $rawBody): array
    {
        return ['ok' => false, 'error' => 'Bank transfer webhooks are not applicable.'];
    }
}