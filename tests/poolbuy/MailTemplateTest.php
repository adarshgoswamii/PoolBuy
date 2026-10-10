<?php
declare(strict_types=1);

namespace Tests\Poolbuy;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The notification email templates.
 *
 * These are plain language files rather than classes, but they are still logic:
 * every body is interpolated with sprintf() from a specific call site, and a
 * mismatch between the number of placeholders and the number of arguments would
 * either throw or silently ship an email with a raw "%s" in it. The tests below
 * call each template exactly the way the lifecycle and payment controller do, so
 * the argument contract is pinned down.
 */
final class MailTemplateTest extends TestCase {
	/**
	 * Load Language
	 *
	 * The language file populates a local $_ array, exactly as OpenCart's language
	 * loader expects. Including it in a scoped function hands that array back
	 * without booting the framework.
	 *
	 * @return array<string, string>
	 */
	private static function load(): array {
		$file = __DIR__ . '/../../upload/extension/poolbuy/catalog/language/en-gb/poolbuy/mail.php';

		self::assertFileExists($file);

		$_ = [];

		require $file;

		/** @var array<string, string> $_ */
		return $_;
	}

	/**
	 * Assert No Placeholders Remain
	 *
	 * A rendered email must contain no unconsumed sprintf specifier. This is the
	 * failure that would otherwise reach a real buyer's inbox.
	 *
	 * @param string $rendered
	 *
	 * @return void
	 */
	private static function assertFullyInterpolated(string $rendered): void {
		self::assertDoesNotMatchRegularExpression('/%[0-9]*\$?[sd]/', $rendered, 'Rendered text still contains a sprintf placeholder: ' . $rendered);
		self::assertNotSame('', trim($rendered));
	}

	/**
	 * Pool lifecycle events, as sent by Lifecycle::notifyParticipants().
	 *
	 * @return array<string, array{string}>
	 */
	public static function poolEventProvider(): array {
		return [
			'reached'   => ['reached'],
			'expired'   => ['expired'],
			'fulfilled' => ['fulfilled']
		];
	}

	#[DataProvider('poolEventProvider')]
	public function testPoolEventTemplatesInterpolate(string $event): void {
		$language = self::load();

		self::assertArrayHasKey('subject_' . $event, $language);
		self::assertArrayHasKey('body_' . $event, $language);

		// Mirrors Lifecycle::notifyParticipants() exactly.
		$subject = sprintf($language['subject_' . $event], 'PB-88305');
		$body = sprintf($language['body_' . $event], 'Asha', 'Titan Pumps Bulk Run', 150, 'Units', 'TX-ABCD1234');

		self::assertFullyInterpolated($subject);
		self::assertFullyInterpolated($body);

		self::assertStringContainsString('PB-88305', $subject);
		self::assertStringContainsString('Asha', $body);
		self::assertStringContainsString('Titan Pumps Bulk Run', $body);
		self::assertStringContainsString('150', $body);
		self::assertStringContainsString('TX-ABCD1234', $body);
	}

	public function testPaymentRequestedTemplateInterpolatesWithThePayLink(): void {
		$language = self::load();

		// Mirrors Lifecycle::notifyPaymentRequested(): order id, then firstname,
		// order id and the payment link.
		$subject = sprintf($language['subject_payment_requested'], 42);
		$body = sprintf($language['body_payment_requested'], 'Asha', 42, 'https://shop.test/pay?order_id=42');

		self::assertFullyInterpolated($subject);
		self::assertFullyInterpolated($body);

		self::assertStringContainsString('42', $subject);
		self::assertStringContainsString('Asha', $body);
		self::assertStringContainsString('https://shop.test/pay?order_id=42', $body);
	}

	/**
	 * Order-scoped payment events, as sent by Lifecycle::notifyBuyerPayment() and
	 * the payment controller's notifyBuyer().
	 *
	 * @return array<string, array{string}>
	 */
	public static function paymentEventProvider(): array {
		return [
			'payment_received' => ['payment_received'],
			'refund_issued'    => ['refund_issued']
		];
	}

	#[DataProvider('paymentEventProvider')]
	public function testOrderScopedPaymentTemplatesInterpolate(string $event): void {
		$language = self::load();

		self::assertArrayHasKey('subject_' . $event, $language);
		self::assertArrayHasKey('body_' . $event, $language);

		$subject = sprintf($language['subject_' . $event], 42);
		$body = sprintf($language['body_' . $event], 'Asha', 42);

		self::assertFullyInterpolated($subject);
		self::assertFullyInterpolated($body);

		self::assertStringContainsString('42', $subject);
		self::assertStringContainsString('Asha', $body);
		self::assertStringContainsString('42', $body);
	}

	public function testEveryNotificationEventHasBothASubjectAndABody(): void {
		$language = self::load();

		$events = ['reached', 'expired', 'fulfilled', 'payment_requested', 'payment_received', 'refund_issued'];

		foreach ($events as $event) {
			self::assertArrayHasKey('subject_' . $event, $language, 'Missing subject for ' . $event);
			self::assertArrayHasKey('body_' . $event, $language, 'Missing body for ' . $event);
			self::assertNotSame('', trim($language['subject_' . $event]));
			self::assertNotSame('', trim($language['body_' . $event]));
		}
	}
}
