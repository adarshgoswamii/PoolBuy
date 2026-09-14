<?php
declare(strict_types=1);

namespace Tests\Poolbuy;

use Opencart\System\Library\Extension\Poolbuy\GatewayFactory;
use Opencart\System\Library\Extension\Poolbuy\MockGateway;
use Opencart\System\Library\Extension\Poolbuy\RazorpayGateway;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The payment gateway abstraction: factory selection, mock link creation,
 * fail-closed signature verification, webhook parsing and refunds.
 */
#[CoversClass(MockGateway::class)]
#[CoversClass(RazorpayGateway::class)]
#[CoversClass(GatewayFactory::class)]
final class PaymentGatewayTest extends TestCase {
	public function testFactoryDefaultsToMockWhenNothingConfigured(): void {
		$gateway = GatewayFactory::make([]);

		self::assertInstanceOf(MockGateway::class, $gateway);
		self::assertSame('mock', $gateway->code());
	}

	public function testFactoryReturnsMockWhenRazorpaySelectedButKeysMissing(): void {
		// A half-configured install must degrade to the safe default, never a
		// broken checkout.
		$gateway = GatewayFactory::make(['gateway' => 'razorpay', 'razorpay_key' => '', 'razorpay_secret' => '']);

		self::assertInstanceOf(MockGateway::class, $gateway);
	}

	public function testFactoryReturnsRazorpayOnlyWhenFullyConfigured(): void {
		$gateway = GatewayFactory::make([
			'gateway'         => 'razorpay',
			'razorpay_key'    => 'rzp_test_key',
			'razorpay_secret' => 'rzp_test_secret'
		]);

		self::assertInstanceOf(RazorpayGateway::class, $gateway);
		self::assertSame('razorpay', $gateway->code());
	}

	public function testMockCreatesDeterministicPaymentLink(): void {
		$gateway = new MockGateway('https://shop.test/index.php?route=extension/poolbuy/payment.pay');

		$link = $gateway->createPaymentLink([
			'order_id'  => 42,
			'reference' => 'PB-000042',
			'amount'    => 1234.56,
			'currency'  => 'INR'
		]);

		self::assertSame('MOCK-PL-00000042', $link['reference']);
		self::assertStringContainsString('order_id=42', $link['url']);
		self::assertStringContainsString('ref=MOCK-PL-00000042', $link['url']);
	}

	public function testMockSignatureFailsClosedOnBlankSecretOrSignature(): void {
		$gateway = new MockGateway();

		self::assertFalse($gateway->verifyWebhookSignature('{}', 'anything', ''));
		self::assertFalse($gateway->verifyWebhookSignature('{}', '', 'secret'));
	}

	public function testMockSignatureAcceptsAGenuineHmacAndRejectsTampering(): void {
		$gateway = new MockGateway();
		$payload = '{"reference":"MOCK-PL-00000042","status":"paid"}';
		$secret = 'whsec_test';

		$signature = MockGateway::sign($payload, $secret);

		self::assertTrue($gateway->verifyWebhookSignature($payload, $signature, $secret));
		self::assertFalse($gateway->verifyWebhookSignature($payload . ' ', $signature, $secret));
		self::assertFalse($gateway->verifyWebhookSignature($payload, $signature, 'wrong_secret'));
	}

	public function testMockParsesPaidAndFailedWebhooksAndRejectsGarbage(): void {
		$gateway = new MockGateway();

		self::assertSame(
			['reference' => 'MOCK-PL-1', 'status' => 'paid'],
			$gateway->parseWebhook('{"reference":"MOCK-PL-1","status":"paid"}')
		);

		self::assertSame(
			['reference' => 'MOCK-PL-1', 'status' => 'failed'],
			$gateway->parseWebhook('{"reference":"MOCK-PL-1","status":"failed"}')
		);

		self::assertNull($gateway->parseWebhook('not json'));
		self::assertNull($gateway->parseWebhook('{"reference":"x"}'));
		self::assertNull($gateway->parseWebhook('{"reference":"x","status":"pending"}'));
	}

	public function testMockRefundIsDeterministicAndGuardsBadInput(): void {
		$gateway = new MockGateway();

		$refund = $gateway->refund('MOCK-PL-00000042', 1234.56);

		self::assertIsString($refund);
		self::assertStringStartsWith('MOCK-RF-', (string)$refund);
		self::assertSame($refund, $gateway->refund('MOCK-PL-00000042', 1234.56));

		self::assertNull($gateway->refund('', 100.0));
		self::assertNull($gateway->refund('MOCK-PL-00000042', 0.0));
	}

	public function testRazorpaySignatureVerificationIsPureAndFailsClosed(): void {
		// Signature checking needs no network, so it is safe to assert directly.
		$gateway = new RazorpayGateway('rzp_test_key', 'rzp_test_secret');
		$payload = '{"event":"payment_link.paid"}';
		$secret = 'whsec_rzp';

		$signature = hash_hmac('sha256', $payload, $secret);

		self::assertTrue($gateway->verifyWebhookSignature($payload, $signature, $secret));
		self::assertFalse($gateway->verifyWebhookSignature($payload, $signature, ''));
		self::assertFalse($gateway->verifyWebhookSignature($payload, 'bad', $secret));
	}

	public function testRazorpayParsesPaymentLinkEvents(): void {
		$gateway = new RazorpayGateway('k', 's');

		$paid = '{"event":"payment_link.paid","payload":{"payment_link":{"entity":{"reference_id":"PB-42-abcd1234"}}}}';
		$expired = '{"event":"payment_link.expired","payload":{"payment_link":{"entity":{"reference_id":"PB-42-abcd1234"}}}}';

		self::assertSame(['reference' => 'PB-42-abcd1234', 'status' => 'paid'], $gateway->parseWebhook($paid));
		self::assertSame(['reference' => 'PB-42-abcd1234', 'status' => 'failed'], $gateway->parseWebhook($expired));
		self::assertNull($gateway->parseWebhook('{"event":"payment.captured"}'));
	}

	// ------------------------------------------------------------ minor units

	public function testATwoDecimalCurrencyIsSentInHundredths(): void {
		// 1,500.00 INR is 150000 paise.
		self::assertSame(150000, RazorpayGateway::toMinorUnits(1500.00, 'INR'));
		self::assertSame(150000, RazorpayGateway::toMinorUnits(1500.00, 'USD'));
	}

	public function testAZeroDecimalCurrencyIsNotMultipliedByAHundred(): void {
		// JPY has NO minor unit. Sending 150000 for a 1,500 yen order would ask the
		// gateway for a hundred times the money. This is the whole reason the
		// multiplier is derived from the currency rather than assumed to be paise.
		self::assertSame(1500, RazorpayGateway::toMinorUnits(1500.00, 'JPY'));
	}

	public function testMinorUnitsRoundRatherThanTruncate(): void {
		// 10.005 in hundredths is 1000.5; truncating would quietly lose a paisa.
		self::assertSame(1001, RazorpayGateway::toMinorUnits(10.005, 'INR'));
		self::assertSame(1234, RazorpayGateway::toMinorUnits(12.34, 'INR'));
	}

	public function testMinorUnitsNeverGoNegative(): void {
		// A negative charge is not a refund; it is a bug. Clamp rather than send it.
		self::assertSame(0, RazorpayGateway::toMinorUnits(-50.0, 'INR'));
	}

	public function testAnUnknownCurrencyFallsBackToHundredths(): void {
		// Two decimals is the safe default for an unrecognised code.
		self::assertSame(1000, RazorpayGateway::toMinorUnits(10.00, 'XYZ'));
	}
}
