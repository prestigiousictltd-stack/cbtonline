<?php
declare(strict_types=1);

namespace App\Payments;

interface PaymentGatewayInterface
{
    /** Machine name: paystack, flutterwave, bank_transfer. */
    public function name(): string;

    /** Human label shown in the UI. */
    public function label(): string;

    /** Is the gateway configured with credentials? */
    public function isConfigured(): bool;

    /** Does this gateway accept inbound webhooks? */
    public function supportsWebhook(): bool;

    /**
     * Initialize a payment intent.
     *
     * @param array{
     *     amount:string,        // major units (naira), formatted with 2 decimals
     *     email:string,
     *     reference:string,     // our payment_ref
     *     callback_url:string,
     *     metadata?:array,
     *     description?:string
     * } $data
     *
     * @return array{
     *     ok:bool,
     *     authorization_url?:string,
     *     gateway_reference?:string,
     *     raw?:array,
     *     error?:string
     * }
     */
    public function initialize(array $data): array;

    /**
     * Verify a transaction with the provider.
     *
     * @return array{
     *     ok:bool,
     *     status?:string,       // 'successful'|'failed'|'pending'
     *     amount?:string,       // major units (naira), formatted
     *     reference?:string,    // our payment_ref
     *     gateway_reference?:string,
     *     payload?:array,
     *     error?:string
     * }
     */
    public function verify(string $reference): array;

    /**
     * Handle an incoming webhook: validate signature, extract the event,
     * and return a normalised envelope.
     *
     * @return array{
     *     ok:bool,
     *     event?:string,            // gateway event name
     *     reference?:string,        // our payment_ref (metadata.reference)
     *     gateway_reference?:string,
     *     amount?:string,           // major units
     *     status?:string,           // 'successful'|'failed'|'pending'
     *     payload?:array,
     *     error?:string
     * }
     */
    public function handleWebhook(array $headers, string $rawBody): array;
}