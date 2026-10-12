<?php
declare(strict_types=1);

namespace App\Payments;

use App\Core\Settings;
use RuntimeException;

final class GatewayManager
{
    /** @var array<string, class-string<PaymentGatewayInterface>> */
    private static array $drivers = [
        'paystack'      => PaystackGateway::class,
        'flutterwave'   => FlutterwaveGateway::class,
        'bank_transfer' => BankTransferGateway::class,
    ];

    public static function driver(string $name): PaymentGatewayInterface
    {
        if (!isset(self::$drivers[$name])) {
            throw new RuntimeException("Unknown payment gateway: {$name}");
        }
        $class = self::$drivers[$name];
        return new $class();
    }

    /** @return array<string, PaymentGatewayInterface> */
    public static function all(): array
    {
        $out = [];
        foreach (self::$drivers as $name => $class) {
            $out[$name] = new $class();
        }
        return $out;
    }

    /**
     * Drivers currently enabled via system_settings AND configured with credentials.
     * Bank transfer is toggled by payments_enabled_bank; online drivers by
     * payments_enabled_online.
     *
     * @return array<string, PaymentGatewayInterface>
     */
    public static function enabledDrivers(): array
    {
        $onlineEnabled = (bool) Settings::get('payments_enabled_online', true);
        $bankEnabled   = (bool) Settings::get('payments_enabled_bank', true);

        $out = [];
        foreach (self::all() as $name => $driver) {
            if (!$driver->isConfigured()) {
                continue;
            }
            if ($name === 'bank_transfer' && !$bankEnabled) {
                continue;
            }
            if ($name !== 'bank_transfer' && !$onlineEnabled) {
                continue;
            }
            $out[$name] = $driver;
        }
        return $out;
    }

    public static function register(string $name, string $class): void
    {
        if (!is_subclass_of($class, PaymentGatewayInterface::class)) {
            throw new RuntimeException('Driver must implement PaymentGatewayInterface.');
        }
        self::$drivers[$name] = $class;
    }
}