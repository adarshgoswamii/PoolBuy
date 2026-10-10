<?php
namespace Opencart\System\Library\Extension\Poolbuy;
/**
 * Interface PaymentGatewayInterface
 *
 * The narrow payment surface the rest of PoolBuy depends on. A gateway can create
 * a payment link for an order, verify and parse an inbound webhook, and refund a
 * captured payment.
 *
 * Implementations are free of OpenCart dependencies - no registry, no database,
 * no superglobals - so the payment rules can be unit tested directly, exactly
 * like PoolCalculator and PoolLifecycle.
 *
 * @package Opencart\System\Library\Extension\Poolbuy
 */
interface PaymentGatewayInterface {
	/**
	 * A short, stable identifier for the gateway, stored on every payment row so
	 * a refund can be routed back to the gateway that captured it.
	 *
	 * @return string
	 */
	public function code(): string;

	/**
	 * Create Payment Link
	 *
	 * Produces a payable link for an order. The returned reference is the
	 * gateway's own handle for the link/payment and is what a later webhook will
	 * quote, so it is persisted on the payment row.
	 *
	 * @param array{order_id: int, reference: string, amount: float, currency: string, email?: string, name?: string} $context
	 *
	 * @return array{reference: string, url: string}
	 */
	public function createPaymentLink(array $context): array;

	/**
	 * Verify Webhook Signature
	 *
	 * Fail-closed authentication of an inbound webhook. A blank secret, a blank
	 * signature, or any mismatch returns false and the caller must reject the
	 * request - a missing secret is never treated as "no auth required".
	 *
	 * @param string $payload   the raw request body, verbatim
	 * @param string $signature the signature header the gateway sent
	 * @param string $secret    the configured webhook secret
	 *
	 * @return bool
	 */
	public function verifyWebhookSignature(string $payload, string $signature, string $secret): bool;

	/**
	 * Parse Webhook
	 *
	 * Extracts the normalised outcome from a raw webhook body. Returns null when
	 * the body is not something this gateway recognises.
	 *
	 * @param string $payload
	 *
	 * @return array{reference: string, status: string}|null status is one of paid|failed
	 */
	public function parseWebhook(string $payload): ?array;

	/**
	 * Refund
	 *
	 * Reverses a captured payment. Returns the gateway's refund reference on
	 * success or null on failure; the caller decides how to record either.
	 *
	 * @param string $reference the gateway reference captured at link creation
	 * @param float  $amount
	 * @param string $currency  the pool's currency, which decides the minor unit
	 *
	 * @return string|null
	 */
	public function refund(string $reference, float $amount, string $currency = 'INR'): ?string;
}
