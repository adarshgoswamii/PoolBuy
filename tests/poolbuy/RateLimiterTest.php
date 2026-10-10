<?php
declare(strict_types=1);

namespace Tests\Poolbuy;

use Opencart\System\Library\Extension\Poolbuy\RateLimiter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Fixed-window rate limiting arithmetic.
 *
 * The property that matters most is that a MISSING or blank configured limit does
 * not mean "unlimited". The extension treats a blank cron token as deny-all rather
 * than allow-all, and the limiter follows the same instinct: absent configuration
 * falls back to a real limit, and only an explicit 0 turns the limiter off.
 */
#[CoversClass(RateLimiter::class)]
final class RateLimiterTest extends TestCase {
	public function testWindowStartFloorsToTheWindowBoundary(): void {
		// 60 second windows: everything from 1200 to 1259 shares one bucket.
		$this->assertSame(1200, RateLimiter::windowStart(1200, 60));
		$this->assertSame(1200, RateLimiter::windowStart(1237, 60));
		$this->assertSame(1200, RateLimiter::windowStart(1259, 60));
		$this->assertSame(1260, RateLimiter::windowStart(1260, 60));
	}

	public function testWindowStartFallsBackWhenTheWindowIsNonsense(): void {
		$this->assertSame(1200, RateLimiter::windowStart(1237, 0));
		$this->assertSame(1200, RateLimiter::windowStart(1237, -5));
	}

	public function testTheSameCallerAndWindowShareABucket(): void {
		$a = RateLimiter::bucket('cron.run', '10.0.0.1', 1200);
		$b = RateLimiter::bucket('cron.run', '10.0.0.1', 1200);

		$this->assertSame($a, $b);
	}

	public function testBucketsSeparateRouteCallerAndWindow(): void {
		$base = RateLimiter::bucket('cron.run', '10.0.0.1', 1200);

		$this->assertNotSame($base, RateLimiter::bucket('payment.webhook', '10.0.0.1', 1200), 'a different endpoint must not share a counter');
		$this->assertNotSame($base, RateLimiter::bucket('cron.run', '10.0.0.2', 1200), 'a different caller must not share a counter');
		$this->assertNotSame($base, RateLimiter::bucket('cron.run', '10.0.0.1', 1260), 'a new window must start a new counter');
	}

	public function testBucketIsAFixedLengthHashSoLongInputCannotOverflowTheColumn(): void {
		$bucket = RateLimiter::bucket(str_repeat('route', 200), str_repeat('a', 500), 1200);

		$this->assertSame(64, strlen($bucket));
		$this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $bucket);
	}

	public function testBlankOrMissingLimitFallsBackToTheDefaultRatherThanUnlimited(): void {
		$this->assertSame(RateLimiter::DEFAULT_LIMIT, RateLimiter::resolveLimit(null));
		$this->assertSame(RateLimiter::DEFAULT_LIMIT, RateLimiter::resolveLimit(''));
		$this->assertSame(RateLimiter::DEFAULT_LIMIT, RateLimiter::resolveLimit('not a number'));
		$this->assertSame(RateLimiter::DEFAULT_LIMIT, RateLimiter::resolveLimit(-1));
	}

	public function testAnExplicitZeroDisablesTheLimiter(): void {
		$this->assertSame(0, RateLimiter::resolveLimit(0));
		$this->assertSame(0, RateLimiter::resolveLimit('0'));
		$this->assertFalse(RateLimiter::exceeded(999999, 0), 'a limit of 0 is off, so nothing is ever exceeded');
	}

	public function testConfiguredLimitIsHonoured(): void {
		$this->assertSame(5, RateLimiter::resolveLimit('5'));
		$this->assertSame(5, RateLimiter::resolveLimit(5));
	}

	public function testBlankOrNonsenseWindowFallsBackToTheDefault(): void {
		$this->assertSame(RateLimiter::DEFAULT_WINDOW, RateLimiter::resolveWindow(null));
		$this->assertSame(RateLimiter::DEFAULT_WINDOW, RateLimiter::resolveWindow(''));
		$this->assertSame(RateLimiter::DEFAULT_WINDOW, RateLimiter::resolveWindow('abc'));
		$this->assertSame(RateLimiter::DEFAULT_WINDOW, RateLimiter::resolveWindow(0));
		$this->assertSame(RateLimiter::DEFAULT_WINDOW, RateLimiter::resolveWindow(-30));
		$this->assertSame(30, RateLimiter::resolveWindow('30'));
	}

	public function testTheLimitItselfIsAllowedAndOnlyTheNextRequestIsRefused(): void {
		// hits counts the request being judged, so hits == limit is the last allowed one.
		$this->assertFalse(RateLimiter::exceeded(1, 3));
		$this->assertFalse(RateLimiter::exceeded(3, 3));
		$this->assertTrue(RateLimiter::exceeded(4, 3));
	}

	public function testRetryAfterCountsDownToTheWindowRollover(): void {
		$this->assertSame(60, RateLimiter::retryAfter(1200, 60, 1200));
		$this->assertSame(23, RateLimiter::retryAfter(1200, 60, 1237));
		$this->assertSame(1, RateLimiter::retryAfter(1200, 60, 1259));
	}

	public function testRetryAfterIsNeverZeroOrNegative(): void {
		// A Retry-After of 0 tells a client nothing useful, and a negative value is
		// meaningless, so a stale window still reports at least a second.
		$this->assertSame(1, RateLimiter::retryAfter(1200, 60, 1260));
		$this->assertSame(1, RateLimiter::retryAfter(1200, 60, 9999));
	}

	public function testClientKeyFallsBackWhenTheAddressIsMissing(): void {
		$this->assertSame('unknown', RateLimiter::clientKey(''));
		$this->assertSame('unknown', RateLimiter::clientKey('   '));
		$this->assertSame('10.0.0.7', RateLimiter::clientKey(' 10.0.0.7 '));
	}
}
