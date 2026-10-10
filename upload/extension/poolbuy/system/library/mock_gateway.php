<?php
namespace Opencart\System\Library\Extension\Poolbuy;
/**
 * Class MockGateway
 *
 * A fully self-contained payment gateway. It invents deterministic references
 * and signs webhooks with a plain HMAC so the entire pay/confirm/refund cycle
 * works with zero external calls and zero credentials. Signatures are still real
 * HMACs, so the fail-closed and tamper-rejection tests exercise genuine
 * verification logic rather than a stub that always says yes.
 *
 * This is the default gateway, used for all seeding, testing and demos.
 *
 * @package Opencart\System\Library\Extension\Poolbuy
 */
class MockGateway implements PaymentGatewayInterface {
	public const CODE = 'mock';

	/**
	 * Where the mock "hosted page" lives. The catalog controller serves this
	 * route; in a test only the shape of the URL matters.
	 */
	private string $base_url;

	/**
	 * @param string $base_url storefront base, e.g. https://shop.example/index.php?route=extension/poolbuy/payment.pay
	 */
	public function __construct(string $base_url = '') {
		$this->base_url = $base_url;
	}

	public function code(): string {
		return self::CODE;
	}

	public function createPaymentLink(array $context): array {
		$order_id = (int)($context['order_id'] ?? 0);
		$reference = 'MOCK-PL-' . str_pad((string)$order_id, 8, '0', STR_PAD_LEFT);

		$url = $this->base_url;
		$separator = (strpos($url, '?') === false) ? '?' : '&';
		$url .= $separator . 'order_id=' . $order_id . '&ref=' . rawurlencode($reference);

		return ['reference' => $reference, 'url' => $url];
	}

	public function verifyWebhookSignature(string $payload, string $signature, string $secret): bool {
		// Fail closed: a blank secret or blank signature can never authenticate.
		if ($secret === '' || $signature === '') {
			return false;
		}

		$expected = hash_hmac('sha256', $payload, $secret);

		return hash_equals($expected, $signature);
	}

	public function parseWebhook(string $payload): ?array {
		$data = json_decode($payload, true);

		if (!is_array($data) || !isset($data['reference'], $data['status'])) {
			return null;
		}

		$status = (string)$data['status'];

		if ($status !== 'paid' && $status !== 'failed') {
			return null;
		}

		return ['reference' => (string)$data['reference'], 'status' => $status];
	}

	public function refund(string $reference, float $amount, string $currency = 'INR'): ?string {
		if ($reference === '' || $amount <= 0) {
			return null;
		}

		return 'MOCK-RF-' . substr(hash('sha256', $reference . '|' . $amount), 0, 16);
	}

	/**
	 * Sign Payload
	 *
	 * Test and seed helper: produce the signature a valid webhook would carry.
	 * Not part of the interface because a real gateway signs on its own servers.
	 *
	 * @param string $payload
	 * @param string $secret
	 *
	 * @return string
	 */
	public static function sign(string $payload, string $secret): string {
		return hash_hmac('sha256', $payload, $secret);
	}
}
