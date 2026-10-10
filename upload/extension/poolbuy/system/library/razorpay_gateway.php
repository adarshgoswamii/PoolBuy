<?php
namespace Opencart\System\Library\Extension\Poolbuy;
/**
 * Class RazorpayGateway
 *
 * Razorpay Payment Links implementation. Signature verification (a pure HMAC
 * needing no network) is implemented inline; link creation and refunds perform
 * HTTP calls to Razorpay and are guarded so that, absent the cURL extension or
 * valid keys, they fail soft to null rather than fatal.
 *
 * This class is intentionally never selected by GatewayFactory unless a key and
 * secret are present, so a credential-free install never reaches the HTTP paths.
 *
 * @package Opencart\System\Library\Extension\Poolbuy
 */
class RazorpayGateway implements PaymentGatewayInterface {
	public const CODE = 'razorpay';

	private string $key;
	private string $secret;

	public function __construct(string $key, string $secret) {
		$this->key = $key;
		$this->secret = $secret;
	}

	public function code(): string {
		return self::CODE;
	}

	public function createPaymentLink(array $context): array {
		$currency = strtoupper(trim((string)($context['currency'] ?? 'INR'))) ?: 'INR';

		$amount = self::toMinorUnits((float)($context['amount'] ?? 0), $currency);
		$reference = 'PB-' . (int)($context['order_id'] ?? 0) . '-' . substr(hash('sha256', (string)($context['reference'] ?? '')), 0, 8);

		$body = [
			'amount'          => $amount,
			'currency'        => $currency,
			'accept_partial'  => false,
			'reference_id'    => $reference,
			'description'     => 'PoolBuy order ' . (int)($context['order_id'] ?? 0),
			'notify'          => ['email' => true, 'sms' => false],
			'reminder_enable' => true
		];

		$response = $this->call('POST', 'https://api.razorpay.com/v1/payment_links', $body);

		if ($response === null || !isset($response['id'], $response['short_url'])) {
			// Fail soft: no link. The caller keeps the payment pending and can retry.
			return ['reference' => $reference, 'url' => ''];
		}

		return ['reference' => (string)$response['id'], 'url' => (string)$response['short_url']];
	}

	public function verifyWebhookSignature(string $payload, string $signature, string $secret): bool {
		if ($secret === '' || $signature === '') {
			return false;
		}

		$expected = hash_hmac('sha256', $payload, $secret);

		return hash_equals($expected, $signature);
	}

	public function parseWebhook(string $payload): ?array {
		$data = json_decode($payload, true);

		if (!is_array($data) || !isset($data['event'])) {
			return null;
		}

		$entity = $data['payload']['payment_link']['entity'] ?? null;

		if (!is_array($entity) || !isset($entity['reference_id'])) {
			return null;
		}

		$event = (string)$data['event'];

		if ($event === 'payment_link.paid') {
			return ['reference' => (string)$entity['reference_id'], 'status' => 'paid'];
		}

		if ($event === 'payment_link.expired' || $event === 'payment_link.cancelled') {
			return ['reference' => (string)$entity['reference_id'], 'status' => 'failed'];
		}

		return null;
	}

	public function refund(string $reference, float $amount, string $currency = 'INR'): ?string {
		$response = $this->call('POST', 'https://api.razorpay.com/v1/payments/' . rawurlencode($reference) . '/refund', [
			'amount' => self::toMinorUnits($amount, $currency)
		]);

		if ($response === null || !isset($response['id'])) {
			return null;
		}

		return (string)$response['id'];
	}

	/**
	 * To Minor Units
	 *
	 * Razorpay takes amounts as integers in a currency's smallest unit. That is
	 * NOT always hundredths: JPY has no minor unit at all, so multiplying by 100
	 * would ask the gateway for a hundred times the money. The multiplier is
	 * therefore derived from the currency's real decimal count rather than assumed
	 * to be paise.
	 *
	 * @param float  $amount
	 * @param string $currency
	 *
	 * @return int
	 */
	public static function toMinorUnits(float $amount, string $currency): int {
		$decimals = PoolPresenter::currencyMeta($currency)['decimals'];

		return (int)round(max(0.0, $amount) * (10 ** $decimals));
	}

	/**
	 * Call
	 *
	 * Minimal authenticated JSON request to Razorpay. Returns the decoded body or
	 * null on any failure, so callers never see an exception from a payment
	 * provider outage.
	 *
	 * @param string               $method
	 * @param string               $url
	 * @param array<string, mixed> $body
	 *
	 * @return array<string, mixed>|null
	 */
	private function call(string $method, string $url, array $body): ?array {
		if (!function_exists('curl_init')) {
			return null;
		}

		$curl = curl_init();

		curl_setopt_array($curl, [
			CURLOPT_URL            => $url,
			CURLOPT_CUSTOMREQUEST  => $method,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_USERPWD        => $this->key . ':' . $this->secret,
			CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
			CURLOPT_POSTFIELDS     => (string)json_encode($body),
			CURLOPT_TIMEOUT        => 20
		]);

		$raw = curl_exec($curl);
		$status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);

		curl_close($curl);

		if (!is_string($raw) || $status < 200 || $status >= 300) {
			return null;
		}

		$decoded = json_decode($raw, true);

		return is_array($decoded) ? $decoded : null;
	}
}
