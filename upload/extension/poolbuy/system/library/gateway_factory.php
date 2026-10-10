<?php
namespace Opencart\System\Library\Extension\Poolbuy;
/**
 * Class GatewayFactory
 *
 * Chooses the payment gateway from configuration. Razorpay is selected only when
 * it is explicitly configured AND both a key and secret are present; anything
 * else - including a "razorpay" selection with missing keys - falls back to the
 * mock, so a half-configured install degrades to a safe, working default rather
 * than a broken checkout.
 *
 * @package Opencart\System\Library\Extension\Poolbuy
 */
class GatewayFactory {
	/**
	 * Make
	 *
	 * @param array{gateway?: string, razorpay_key?: string, razorpay_secret?: string, base_url?: string} $config
	 *
	 * @return PaymentGatewayInterface
	 */
	public static function make(array $config): PaymentGatewayInterface {
		$gateway = (string)($config['gateway'] ?? MockGateway::CODE);
		$key = trim((string)($config['razorpay_key'] ?? ''));
		$secret = trim((string)($config['razorpay_secret'] ?? ''));

		if ($gateway === RazorpayGateway::CODE && $key !== '' && $secret !== '') {
			return new RazorpayGateway($key, $secret);
		}

		return new MockGateway((string)($config['base_url'] ?? ''));
	}
}
