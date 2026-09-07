<?php
declare(strict_types=1);

namespace Tests\Poolbuy;

use Opencart\System\Library\Extension\Poolbuy\PoolLifecycle;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The pool state machine.
 */
#[CoversClass(PoolLifecycle::class)]
final class PoolLifecycleTest extends TestCase {
	public function testEveryStatusIsRecognised(): void {
		$expected = ['draft', 'active', 'reached', 'closed', 'expired', 'fulfilled', 'cancelled'];

		self::assertSame($expected, PoolLifecycle::statuses());

		foreach ($expected as $status) {
			self::assertTrue(PoolLifecycle::isStatus($status));
		}
	}

	public function testUnknownStatusIsRejected(): void {
		self::assertFalse(PoolLifecycle::isStatus('archived'));
		self::assertFalse(PoolLifecycle::canTransition('draft', 'archived'));
		self::assertFalse(PoolLifecycle::canTransition('archived', 'active'));
	}

	/**
	 * @return array<string, array{string, string, bool}>
	 */
	public static function transitionProvider(): array {
		return [
			// The happy path all the way through fulfilment
			'draft to active'         => ['draft', 'active', true],
			'active to reached'       => ['active', 'reached', true],
			'reached to closed'       => ['reached', 'closed', true],
			'closed to fulfilled'     => ['closed', 'fulfilled', true],

			// Legitimate off-ramps
			'draft to cancelled'      => ['draft', 'cancelled', true],
			'active to expired'       => ['active', 'expired', true],
			'active to cancelled'     => ['active', 'cancelled', true],
			'reached to cancelled'    => ['reached', 'cancelled', true],

			// Skipping states must not be possible
			'draft cannot reach'      => ['draft', 'reached', false],
			'draft cannot close'      => ['draft', 'closed', false],
			'active cannot close'     => ['active', 'closed', false],
			'active cannot fulfil'    => ['active', 'fulfilled', false],
			'reached cannot fulfil'   => ['reached', 'fulfilled', false],

			// No going backwards
			'active cannot draft'      => ['active', 'draft', false],
			'reached cannot activate'  => ['reached', 'active', false],
			'closed cannot activate'   => ['closed', 'active', false],

			// Terminal states are final
			'expired is final'        => ['expired', 'active', false],
			'fulfilled is final'      => ['fulfilled', 'closed', false],
			'cancelled is final'      => ['cancelled', 'active', false],

			// A pool can always be saved without changing status
			'draft stays draft'       => ['draft', 'draft', true],
			'expired stays expired'   => ['expired', 'expired', true]
		];
	}

	#[DataProvider('transitionProvider')]
	public function testTransition(string $from, string $to, bool $allowed): void {
		self::assertSame($allowed, PoolLifecycle::canTransition($from, $to));
	}

	public function testTerminalStatusesHaveNoOnwardTransitions(): void {
		foreach (['expired', 'fulfilled', 'cancelled'] as $status) {
			self::assertTrue(PoolLifecycle::isTerminal($status));
			self::assertSame([], PoolLifecycle::allowedTransitions($status));
		}
	}

	public function testNonTerminalStatusesOfferOnwardTransitions(): void {
		foreach (['draft', 'active', 'reached', 'closed'] as $status) {
			self::assertFalse(PoolLifecycle::isTerminal($status));
			self::assertNotSame([], PoolLifecycle::allowedTransitions($status));
		}
	}

	public function testOnlyAnActivePoolAcceptsReservations(): void {
		self::assertTrue(PoolLifecycle::isOpenForReservations('active'));

		// A pool that already hit its MOQ keeps its participants but must not take
		// on late joiners while fulfilment is being prepared.
		foreach (['draft', 'reached', 'closed', 'expired', 'fulfilled', 'cancelled'] as $status) {
			self::assertFalse(PoolLifecycle::isOpenForReservations($status), $status . ' must not accept reservations');
		}
	}

	public function testLiveCommitmentStatuses(): void {
		foreach (['active', 'reached', 'closed'] as $status) {
			self::assertTrue(PoolLifecycle::countsTowardsLivePool($status));
		}

		foreach (['draft', 'expired', 'fulfilled', 'cancelled'] as $status) {
			self::assertFalse(PoolLifecycle::countsTowardsLivePool($status));
		}
	}
}
