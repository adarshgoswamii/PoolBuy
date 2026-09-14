<?php
declare(strict_types=1);

namespace Tests\Poolbuy;

use Opencart\System\Library\Extension\Poolbuy\PoolCalculator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Rules for pool pricing and progress.
 *
 * The ladders used here are the ones from the PoolBuy design screens, so a
 * failure points directly at a discrepancy against the intended product
 * behaviour rather than at an invented fixture.
 */
#[CoversClass(PoolCalculator::class)]
final class PoolCalculatorTest extends TestCase {
	/**
	 * The Titan-X pump ladder from the product detail screen:
	 * Tier 1 (10-49) 1550, Tier 2 (50-149) 1380, Tier 3 (150+) 1250.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function titanLadder(): array {
		return [
			['tier_id' => 1, 'min_qty' => 10, 'max_qty' => 49, 'price' => 1550.00],
			['tier_id' => 2, 'min_qty' => 50, 'max_qty' => 149, 'price' => 1380.00],
			['tier_id' => 3, 'min_qty' => 150, 'max_qty' => null, 'price' => 1250.00]
		];
	}

	/**
	 * The filtration-unit ladder from the join-pool screen:
	 * 10-49 at 1250, 50-99 at 1100, 100+ at 950.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function filtrationLadder(): array {
		return [
			['tier_id' => 7, 'min_qty' => 10, 'max_qty' => 49, 'price' => 1250.00],
			['tier_id' => 8, 'min_qty' => 50, 'max_qty' => 99, 'price' => 1100.00],
			['tier_id' => 9, 'min_qty' => 100, 'max_qty' => null, 'price' => 950.00]
		];
	}

	// ---------------------------------------------------------------- normalise

	public function testNormaliseSortsByMinimumQuantityAndTypesValues(): void {
		$ladder = PoolCalculator::normaliseTiers([
			['tier_id' => '3', 'min_qty' => '150', 'max_qty' => '', 'price' => '1250.0000'],
			['tier_id' => '1', 'min_qty' => '10', 'max_qty' => '49', 'price' => '1550.0000'],
			['tier_id' => '2', 'min_qty' => '50', 'max_qty' => '149', 'price' => '1380.0000']
		]);

		self::assertSame([10, 50, 150], array_column($ladder, 'min_qty'));
		self::assertSame([49, 149, null], array_column($ladder, 'max_qty'));
		self::assertSame([1550.0, 1380.0, 1250.0], array_column($ladder, 'price'));
		self::assertSame(1, $ladder[0]['tier_id']);
	}

	public function testNormaliseTreatsZeroMaximumAsOpenEnded(): void {
		// The tier editor submits an empty ceiling as 0; that must mean "no ceiling",
		// not "a tier that can never match".
		$ladder = PoolCalculator::normaliseTiers([
			['min_qty' => 100, 'max_qty' => 0, 'price' => 950.0]
		]);

		self::assertNull($ladder[0]['max_qty']);
	}

	// ----------------------------------------------------------------- validate

	public function testValidLadderReportsNoProblems(): void {
		self::assertSame([], PoolCalculator::validateTiers(self::titanLadder()));
	}

	public function testEmptyLadderIsRejected(): void {
		$errors = PoolCalculator::validateTiers([]);

		self::assertCount(1, $errors);
		self::assertStringContainsString('at least one price tier', $errors[0]);
	}

	public function testOverlappingTiersAreRejected(): void {
		$errors = PoolCalculator::validateTiers([
			['min_qty' => 10, 'max_qty' => 60, 'price' => 1550.0],
			['min_qty' => 50, 'max_qty' => null, 'price' => 1380.0]
		]);

		self::assertNotEmpty($errors);
		self::assertStringContainsString('overlaps', implode(' ', $errors));
	}

	public function testGapBetweenTiersIsRejected(): void {
		$errors = PoolCalculator::validateTiers([
			['min_qty' => 10, 'max_qty' => 49, 'price' => 1550.0],
			['min_qty' => 80, 'max_qty' => null, 'price' => 1380.0]
		]);

		self::assertNotEmpty($errors);
		self::assertStringContainsString('gap', implode(' ', $errors));
	}

	public function testLadderMustBeToppedByAnOpenEndedTier(): void {
		$errors = PoolCalculator::validateTiers([
			['min_qty' => 10, 'max_qty' => 49, 'price' => 1550.0],
			['min_qty' => 50, 'max_qty' => 149, 'price' => 1380.0]
		]);

		self::assertNotEmpty($errors);
		self::assertStringContainsString('open ended', implode(' ', $errors));
	}

	public function testOpenEndedTierInTheMiddleIsRejected(): void {
		$errors = PoolCalculator::validateTiers([
			['min_qty' => 10, 'max_qty' => null, 'price' => 1550.0],
			['min_qty' => 50, 'max_qty' => null, 'price' => 1380.0]
		]);

		self::assertNotEmpty($errors);
		self::assertStringContainsString('only the last tier', strtolower(implode(' ', $errors)));
	}

	public function testNonPositivePriceIsRejected(): void {
		$errors = PoolCalculator::validateTiers([
			['min_qty' => 1, 'max_qty' => null, 'price' => 0.0]
		]);

		self::assertNotEmpty($errors);
		self::assertStringContainsString('greater than zero', implode(' ', $errors));
	}

	public function testMinimumQuantityBelowOneIsRejected(): void {
		$errors = PoolCalculator::validateTiers([
			['min_qty' => 0, 'max_qty' => null, 'price' => 100.0]
		]);

		self::assertNotEmpty($errors);
		self::assertStringContainsString('at least 1', implode(' ', $errors));
	}

	public function testInvertedTierRangeIsRejected(): void {
		$errors = PoolCalculator::validateTiers([
			['min_qty' => 50, 'max_qty' => 20, 'price' => 100.0]
		]);

		self::assertNotEmpty($errors);
		self::assertStringContainsString('cannot be less than', implode(' ', $errors));
	}

	public function testSingleOpenEndedTierIsValid(): void {
		self::assertSame([], PoolCalculator::validateTiers([
			['min_qty' => 1, 'max_qty' => null, 'price' => 42.0]
		]));
	}

	// ------------------------------------------------------------ resolve tiers

	/**
	 * Exercises the exact boundaries of the Titan-X ladder.
	 *
	 * @return array<string, array{int, float|null}>
	 */
	public static function titanBoundaryProvider(): array {
		return [
			'below the first tier'      => [9, null],
			'first tier lower bound'    => [10, 1550.00],
			'first tier upper bound'    => [49, 1550.00],
			'second tier lower bound'   => [50, 1380.00],
			'second tier upper bound'   => [149, 1380.00],
			'third tier lower bound'    => [150, 1250.00],
			'far above the top tier'    => [100000, 1250.00]
		];
	}

	#[DataProvider('titanBoundaryProvider')]
	public function testResolveTierAtEveryBoundary(int $quantity, ?float $expected): void {
		$tier = PoolCalculator::resolveTier(self::titanLadder(), $quantity);

		if ($expected === null) {
			self::assertNull($tier);
		} else {
			self::assertNotNull($tier);
			self::assertSame($expected, $tier['price']);
		}
	}

	public function testResolveTierReturnsNullWhenQuantityFallsAboveAClosedLadder(): void {
		// A malformed ladder with no open top tier cannot price 500 units. Strict
		// resolution reports that honestly rather than inventing a price.
		$closed = [
			['min_qty' => 10, 'max_qty' => 49, 'price' => 1550.0],
			['min_qty' => 50, 'max_qty' => 149, 'price' => 1380.0]
		];

		self::assertNull(PoolCalculator::resolveTier($closed, 500));
	}

	public function testBestUnlockedTierKeepsTopTierWhenQuantityOvershootsClosedLadder(): void {
		// Pools must stay priceable even if an operator mis-configures the ladder,
		// so the best-unlocked lookup clamps to the highest earned tier.
		$closed = [
			['min_qty' => 10, 'max_qty' => 49, 'price' => 1550.0],
			['min_qty' => 50, 'max_qty' => 149, 'price' => 1380.0]
		];

		$tier = PoolCalculator::bestUnlockedTier($closed, 500);

		self::assertNotNull($tier);
		self::assertSame(1380.0, $tier['price']);
	}

	public function testBestUnlockedTierIsNullBeforeTheFirstTierIsReached(): void {
		self::assertNull(PoolCalculator::bestUnlockedTier(self::titanLadder(), 9));
	}

	public function testPoolUnitPriceUsesCollectiveVolume(): void {
		// 150 units reserved unlocks the 150+ tier for the whole pool.
		self::assertSame(1250.00, PoolCalculator::poolUnitPrice(self::titanLadder(), 150));
	}

	public function testPoolUnitPriceIsNullWhenNoTierUnlocked(): void {
		self::assertNull(PoolCalculator::poolUnitPrice(self::titanLadder(), 0));
	}

	public function testQuoteUnitPriceLooksForwardToThePriceTheBuyerUnlocks(): void {
		// Pool holds 120; a buyer adding 40 pushes it to 160, unlocking 1250.
		self::assertSame(1250.00, PoolCalculator::quoteUnitPrice(self::titanLadder(), 120, 40));

		// The same buyer adding only 10 leaves the pool at 130, still on 1380.
		self::assertSame(1380.00, PoolCalculator::quoteUnitPrice(self::titanLadder(), 120, 10));
	}

	// -------------------------------------------------------------- next tier

	public function testNextTierAndUnitsNeededMatchTheProductScreen(): void {
		// Product screen: pool at 150 of 200 with the next milestone at 200.
		$ladder = [
			['min_qty' => 10, 'max_qty' => 49, 'price' => 1550.0],
			['min_qty' => 50, 'max_qty' => 199, 'price' => 1380.0],
			['min_qty' => 200, 'max_qty' => null, 'price' => 1250.0]
		];

		$next = PoolCalculator::nextTier($ladder, 150);

		self::assertNotNull($next);
		self::assertSame(200, $next['min_qty']);
		self::assertSame(1250.0, $next['price']);
		self::assertSame(50, PoolCalculator::unitsToNextTier($ladder, 150));
	}

	public function testUnitsToNextTierMatchesTheJoinScreenMilestone(): void {
		// Join screen: 410 of 500 reserved, next discount unlocks at 450.
		$ladder = [
			['min_qty' => 10, 'max_qty' => 449, 'price' => 1100.0],
			['min_qty' => 450, 'max_qty' => null, 'price' => 1045.0]
		];

		self::assertSame(40, PoolCalculator::unitsToNextTier($ladder, 410));
	}

	public function testNextTierIsNullOnceTopTierUnlocked(): void {
		self::assertNull(PoolCalculator::nextTier(self::titanLadder(), 150));
		self::assertNull(PoolCalculator::unitsToNextTier(self::titanLadder(), 900));
	}

	public function testNextTierIsTheFirstTierWhenPoolIsEmpty(): void {
		$next = PoolCalculator::nextTier(self::titanLadder(), 0);

		self::assertNotNull($next);
		self::assertSame(10, $next['min_qty']);
		self::assertSame(10, PoolCalculator::unitsToNextTier(self::titanLadder(), 0));
	}

	// --------------------------------------------------------------- progress

	public function testFillPercentageMatchesTheDesignScreens(): void {
		self::assertSame(75.0, PoolCalculator::fillPercentage(150, 200));   // product page
		self::assertSame(82.0, PoolCalculator::fillPercentage(410, 500));   // join flow
		self::assertSame(84.0, PoolCalculator::fillPercentage(42, 50));     // seller table
		self::assertSame(97.5, PoolCalculator::fillPercentage(195, 200));   // seller table row 2
		self::assertSame(78.0, PoolCalculator::fillPercentage(780, 1000));  // buyer dashboard
		self::assertSame(12.0, PoolCalculator::fillPercentage(12, 100));    // new pool card
	}

	public function testFillPercentageIsZeroWhenMoqTargetIsZero(): void {
		// Guards against a division by zero on a misconfigured pool.
		self::assertSame(0.0, PoolCalculator::fillPercentage(50, 0));
	}

	public function testFillPercentageIsZeroForNegativeOrEmptyReservations(): void {
		self::assertSame(0.0, PoolCalculator::fillPercentage(0, 200));
		self::assertSame(0.0, PoolCalculator::fillPercentage(-5, 200));
	}

	public function testFillPercentageIsCappedAtOneHundredWhenOversubscribed(): void {
		self::assertSame(100.0, PoolCalculator::fillPercentage(260, 200));
	}

	public function testMoqReachedDetection(): void {
		self::assertFalse(PoolCalculator::isMoqReached(199, 200));
		self::assertTrue(PoolCalculator::isMoqReached(200, 200));
		self::assertTrue(PoolCalculator::isMoqReached(201, 200));
	}

	public function testMoqIsNeverReachedWhenTargetIsZero(): void {
		self::assertFalse(PoolCalculator::isMoqReached(500, 0));
	}

	public function testUnitsRemainingNeverGoesNegative(): void {
		self::assertSame(50, PoolCalculator::unitsRemaining(150, 200));
		self::assertSame(8, PoolCalculator::unitsRemaining(42, 50));
		self::assertSame(0, PoolCalculator::unitsRemaining(260, 200));
	}

	// ----------------------------------------------------------- clamping

	public function testClampQuantityRespectsPerBuyerBounds(): void {
		// Join screen: min 10, max 200, plenty of headroom.
		self::assertSame(50, PoolCalculator::clampQuantity(50, 10, 200, 500));
		self::assertSame(10, PoolCalculator::clampQuantity(3, 10, 200, 500));
		self::assertSame(200, PoolCalculator::clampQuantity(900, 10, 200, 500));
	}

	public function testClampQuantityNeverExceedsUnitsStillAvailable(): void {
		self::assertSame(8, PoolCalculator::clampQuantity(50, 1, 200, 8));
	}

	public function testClampQuantityReturnsZeroWhenPoolIsFull(): void {
		self::assertSame(0, PoolCalculator::clampQuantity(10, 1, 0, 0));
	}

	public function testClampQuantityReturnsZeroWhenRemainingUnitsAreBelowTheMinimum(): void {
		// 5 units left but the pool insists on 10 per buyer: nobody can join.
		self::assertSame(0, PoolCalculator::clampQuantity(10, 10, 200, 5));
	}

	public function testClampQuantityTreatsZeroMaximumAsNoCeiling(): void {
		self::assertSame(400, PoolCalculator::clampQuantity(400, 1, 0, 500));
	}

	// ------------------------------------------------------- price breakdown

	public function testPriceBreakdownReproducesTheJoinScreenExactly(): void {
		// Join screen step 2: 50 units at 1,100 with 18% GST and a 2% platform fee.
		$breakdown = PoolCalculator::priceBreakdown(50, 1100.00, 18.0, 2.0);

		self::assertSame(55000.00, $breakdown['subtotal']);
		self::assertSame(9900.00, $breakdown['gst']);
		self::assertSame(1100.00, $breakdown['platform_fee']);
		self::assertSame(66000.00, $breakdown['total']);
	}

	public function testPriceBreakdownAddsShippingAfterTaxAndFee(): void {
		// Step 3 adds standard cargo freight of 420.
		$breakdown = PoolCalculator::priceBreakdown(50, 1100.00, 18.0, 2.0, 420.00);

		self::assertSame(420.00, $breakdown['shipping']);
		self::assertSame(66420.00, $breakdown['total']);
	}

	public function testPriceBreakdownTotalAlwaysEqualsTheSumOfItsParts(): void {
		$breakdown = PoolCalculator::priceBreakdown(37, 1381.37, 18.0, 2.5, 199.99);

		$sum = round(
			$breakdown['subtotal'] + $breakdown['gst'] + $breakdown['platform_fee'] + $breakdown['shipping'],
			PoolCalculator::SCALE
		);

		self::assertSame($sum, $breakdown['total']);
	}

	public function testPriceBreakdownWithZeroRatesChargesOnlyTheSubtotal(): void {
		$breakdown = PoolCalculator::priceBreakdown(10, 100.00, 0.0, 0.0);

		self::assertSame(1000.00, $breakdown['subtotal']);
		self::assertSame(0.0, $breakdown['gst']);
		self::assertSame(0.0, $breakdown['platform_fee']);
		self::assertSame(1000.00, $breakdown['total']);
	}

	public function testPriceBreakdownTreatsNegativeInputsAsZero(): void {
		$breakdown = PoolCalculator::priceBreakdown(-5, -10.0, -1.0, -1.0, -50.0);

		self::assertSame(0, $breakdown['quantity']);
		self::assertSame(0.0, $breakdown['unit_price']);
		self::assertSame(0.0, $breakdown['total']);
	}

	// ------------------------------------------------------ promotion discount

	public function testDiscountIsTakenBeforeTaxAndFeeAreCharged(): void {
		// 50 units at 1,100 is a 55,000 subtotal. A 5,000 discount leaves 50,000
		// as the taxable base, so GST is 9,000 and the 2% fee is 1,000.
		$breakdown = PoolCalculator::priceBreakdown(50, 1100.00, 18.0, 2.0, 0.0, 5000.00);

		self::assertSame(55000.00, $breakdown['subtotal'], 'the gross subtotal is unchanged by a discount');
		self::assertSame(5000.00, $breakdown['discount']);
		self::assertSame(50000.00, $breakdown['discounted_subtotal']);
		self::assertSame(9000.00, $breakdown['gst'], 'GST is charged on the discounted base');
		self::assertSame(1000.00, $breakdown['platform_fee'], 'the platform fee is charged on the discounted base');
		self::assertSame(60000.00, $breakdown['total']);
	}

	public function testDiscountIsCappedAtTheSubtotalSoTheTotalNeverGoesNegative(): void {
		// A 999,999 discount against a 1,000 subtotal must not invert the order.
		$breakdown = PoolCalculator::priceBreakdown(10, 100.00, 18.0, 2.0, 0.0, 999999.00);

		self::assertSame(1000.00, $breakdown['discount'], 'the discount is clamped to the subtotal');
		self::assertSame(0.0, $breakdown['discounted_subtotal']);
		self::assertSame(0.0, $breakdown['gst']);
		self::assertSame(0.0, $breakdown['platform_fee']);
		self::assertSame(0.0, $breakdown['total']);
	}

	public function testNegativeDiscountCannotBeUsedToInflateAnOrder(): void {
		$breakdown = PoolCalculator::priceBreakdown(10, 100.00, 18.0, 2.0, 0.0, -500.00);

		self::assertSame(0.0, $breakdown['discount']);
		self::assertSame(1200.00, $breakdown['total']);
	}

	public function testFreightIsAddedAfterTheDiscountAndIsNotDiscounted(): void {
		$breakdown = PoolCalculator::priceBreakdown(50, 1100.00, 18.0, 2.0, 420.00, 5000.00);

		self::assertSame(420.00, $breakdown['shipping']);
		self::assertSame(60420.00, $breakdown['total']);
	}

	public function testOmittingTheDiscountReproducesThePrePromotionFigures(): void {
		$without = PoolCalculator::priceBreakdown(37, 1381.37, 18.0, 2.5, 199.99);
		$explicit_zero = PoolCalculator::priceBreakdown(37, 1381.37, 18.0, 2.5, 199.99, 0.0);

		self::assertSame($without, $explicit_zero);
		self::assertSame(0.0, $without['discount']);
		self::assertSame($without['subtotal'], $without['discounted_subtotal']);
	}

	// --------------------------------------------------- promotion resolution

	/**
	 * A live, unlimited 10% code with no constraints.
	 *
	 * @param array<string, mixed> $overrides
	 *
	 * @return array<string, mixed>
	 */
	private static function promotion(array $overrides = []): array {
		return $overrides + [
			'promotion_id'     => 1,
			'code'             => 'POOL10',
			'scope'            => 'buyer',
			'pool_id'          => 0,
			'type'             => 'percent',
			'value'            => 10.0,
			'min_qty'          => 0,
			'max_redemptions'  => 0,
			'redemptions_used' => 0,
			'date_start'       => null,
			'date_end'         => null,
			'status'           => 1
		];
	}

	public function testPercentagePromotionDiscountsTheSubtotal(): void {
		$result = PoolCalculator::resolveDiscount(self::promotion(), 50, 55000.00);

		self::assertTrue($result['valid']);
		self::assertSame(5500.00, $result['discount'], '10% of 55,000');
	}

	public function testFixedPromotionDiscountsAFlatAmount(): void {
		$promotion = self::promotion(['type' => 'fixed', 'value' => 2500.00]);

		$result = PoolCalculator::resolveDiscount($promotion, 50, 55000.00);

		self::assertTrue($result['valid']);
		self::assertSame(2500.00, $result['discount']);
	}

	public function testAFixedPromotionCannotExceedTheSubtotal(): void {
		// A 5,000 off code against a 1,000 order must not create a refund.
		$promotion = self::promotion(['type' => 'fixed', 'value' => 5000.00]);

		$result = PoolCalculator::resolveDiscount($promotion, 10, 1000.00);

		self::assertTrue($result['valid']);
		self::assertSame(1000.00, $result['discount'], 'capped at the subtotal');
	}

	public function testAPercentageAbove100IsClampedToTheWholeSubtotal(): void {
		$promotion = self::promotion(['value' => 900.0]);

		$result = PoolCalculator::resolveDiscount($promotion, 10, 1000.00);

		self::assertSame(1000.00, $result['discount'], 'a 900% code discounts 100%, never more');
	}

	public function testAnInactivePromotionIsRejected(): void {
		$result = PoolCalculator::resolveDiscount(self::promotion(['status' => 0]), 50, 55000.00);

		self::assertFalse($result['valid']);
		self::assertSame('inactive', $result['reason']);
		self::assertSame(0.0, $result['discount']);
	}

	public function testAPromotionThatHasNotStartedIsRejected(): void {
		$promotion = self::promotion(['date_start' => '2030-01-01 00:00:00']);

		$result = PoolCalculator::resolveDiscount($promotion, 50, 55000.00, strtotime('2026-01-01 00:00:00'));

		self::assertFalse($result['valid']);
		self::assertSame('not_started', $result['reason']);
	}

	public function testAnExpiredPromotionIsRejected(): void {
		$promotion = self::promotion(['date_end' => '2020-01-01 00:00:00']);

		$result = PoolCalculator::resolveDiscount($promotion, 50, 55000.00, strtotime('2026-01-01 00:00:00'));

		self::assertFalse($result['valid']);
		self::assertSame('expired', $result['reason']);
	}

	public function testOpenEndedDatesImposeNoBound(): void {
		// Null, empty string and MySQL's zero date all mean "no bound".
		foreach ([null, '', '0000-00-00 00:00:00'] as $blank) {
			$promotion = self::promotion(['date_start' => $blank, 'date_end' => $blank]);

			$result = PoolCalculator::resolveDiscount($promotion, 50, 55000.00);

			self::assertTrue($result['valid'], 'a blank date must not reject the code');
		}
	}

	public function testAPromotionBelowItsMinimumQuantityIsRejected(): void {
		$promotion = self::promotion(['min_qty' => 100]);

		$result = PoolCalculator::resolveDiscount($promotion, 50, 55000.00);

		self::assertFalse($result['valid']);
		self::assertSame('min_qty', $result['reason']);
	}

	public function testAPromotionAtItsMinimumQuantityIsAccepted(): void {
		$promotion = self::promotion(['min_qty' => 50]);

		self::assertTrue(PoolCalculator::resolveDiscount($promotion, 50, 55000.00)['valid']);
	}

	public function testAnExhaustedPromotionIsRejected(): void {
		$promotion = self::promotion(['max_redemptions' => 5, 'redemptions_used' => 5]);

		$result = PoolCalculator::resolveDiscount($promotion, 50, 55000.00);

		self::assertFalse($result['valid']);
		self::assertSame('exhausted', $result['reason'], 'a real code that ran out says so');
	}

	public function testTheLastRedemptionIsStillAvailable(): void {
		$promotion = self::promotion(['max_redemptions' => 5, 'redemptions_used' => 4]);

		self::assertTrue(PoolCalculator::resolveDiscount($promotion, 50, 55000.00)['valid']);
	}

	public function testZeroMaxRedemptionsMeansUnlimited(): void {
		$promotion = self::promotion(['max_redemptions' => 0, 'redemptions_used' => 9999]);

		self::assertTrue(PoolCalculator::resolveDiscount($promotion, 50, 55000.00)['valid']);
	}

	public function testAnUnknownPromotionTypeIsRejected(): void {
		$promotion = self::promotion(['type' => 'buy_one_get_one']);

		$result = PoolCalculator::resolveDiscount($promotion, 50, 55000.00);

		self::assertFalse($result['valid']);
		self::assertSame('unknown_type', $result['reason']);
	}

	public function testAZeroValuePromotionIsRejectedRatherThanAppliedAsNothing(): void {
		$result = PoolCalculator::resolveDiscount(self::promotion(['value' => 0]), 50, 55000.00);

		self::assertFalse($result['valid']);
		self::assertSame('worthless', $result['reason']);
	}

	public function testANegativePromotionValueCannotIncreaseTheTotal(): void {
		$result = PoolCalculator::resolveDiscount(self::promotion(['value' => -50]), 50, 55000.00);

		self::assertFalse($result['valid']);
		self::assertSame(0.0, $result['discount']);
	}

	public function testAnEmptyPromotionIsRejected(): void {
		$result = PoolCalculator::resolveDiscount([], 50, 55000.00);

		self::assertFalse($result['valid']);
		self::assertSame('not_found', $result['reason']);
	}

	public function testThereIsNothingToDiscountOnAnEmptyOrder(): void {
		$result = PoolCalculator::resolveDiscount(self::promotion(), 0, 0.0);

		self::assertFalse($result['valid']);
		self::assertSame('nothing_to_discount', $result['reason']);
	}

	public function testAResolvedDiscountFedIntoTheBreakdownNeverInvertsTheOrder(): void {
		// The two pieces are designed to compose: whatever resolveDiscount returns
		// must be safe to hand straight to priceBreakdown.
		$promotion = self::promotion(['type' => 'fixed', 'value' => 999999.00]);

		$resolved = PoolCalculator::resolveDiscount($promotion, 10, 1000.00);
		$breakdown = PoolCalculator::priceBreakdown(10, 100.00, 18.0, 2.0, 0.0, $resolved['discount']);

		self::assertSame(0.0, $breakdown['total']);
		self::assertGreaterThanOrEqual(0.0, $breakdown['discounted_subtotal']);
	}

	// ----------------------------------------------------- freight allocation
	public function testFreightSharesSumExactlyToTheQuotedTotal(): void {
		// 9,000 freight across 150 units in three unequal commitments.
		$reservations = [
			['reservation_id' => 1, 'quantity' => 40],
			['reservation_id' => 2, 'quantity' => 60],
			['reservation_id' => 3, 'quantity' => 50]
		];

		$shares = PoolCalculator::allocateFreight($reservations, 9000.00);

		self::assertSame(2400.00, $shares[1], '40/150 of 9,000');
		self::assertSame(3600.00, $shares[2], '60/150 of 9,000');
		self::assertSame(3000.00, $shares[3], '50/150 of 9,000');
		self::assertSame(9000.00, round(array_sum($shares), PoolCalculator::SCALE));
	}

	public function testFreightIndivisibleByThreeLosesNothingToRounding(): void {
		// 100.00 across three equal commitments cannot divide evenly. The largest
		// remainder method must still account for every last unit.
		$reservations = [
			['reservation_id' => 1, 'quantity' => 10],
			['reservation_id' => 2, 'quantity' => 10],
			['reservation_id' => 3, 'quantity' => 10]
		];

		$shares = PoolCalculator::allocateFreight($reservations, 100.00);

		self::assertSame(100.00, round(array_sum($shares), PoolCalculator::SCALE), 'not a single paisa is lost or invented');

		// Exactly one buyer absorbs the odd remaining unit.
		sort($shares);
		self::assertSame([33.3333, 33.3333, 33.3334], $shares);
	}

	public function testFreightSplitsEquallyForEqualQuantities(): void {
		$reservations = [
			['reservation_id' => 1, 'quantity' => 25],
			['reservation_id' => 2, 'quantity' => 25]
		];

		$shares = PoolCalculator::allocateFreight($reservations, 500.00);

		self::assertSame(250.00, $shares[1]);
		self::assertSame(250.00, $shares[2]);
	}

	public function testASingleBuyerCarriesTheWholeFreightCharge(): void {
		$shares = PoolCalculator::allocateFreight([['reservation_id' => 9, 'quantity' => 12]], 1234.56);

		self::assertSame(1234.56, $shares[9]);
	}

	public function testZeroAndNegativeFreightAllocateNothingToAnyone(): void {
		$reservations = [
			['reservation_id' => 1, 'quantity' => 10],
			['reservation_id' => 2, 'quantity' => 20]
		];

		foreach ([0.0, -750.00] as $total) {
			$shares = PoolCalculator::allocateFreight($reservations, $total);

			self::assertSame([1 => 0.0, 2 => 0.0], $shares, 'every share is present and zero');
		}
	}

	public function testFreightAllocatesNothingWhenNoQuantityIsCommitted(): void {
		// A pool whose reservations all carry zero quantity has nothing to divide
		// freight against; the charge must not be dumped on an arbitrary buyer.
		$shares = PoolCalculator::allocateFreight([['reservation_id' => 1, 'quantity' => 0]], 500.00);

		self::assertSame([1 => 0.0], $shares);
	}

	public function testFreightAllocationIsEmptyForAnEmptyPool(): void {
		self::assertSame([], PoolCalculator::allocateFreight([], 500.00));
	}

	public function testFreightAllocationIsDeterministicAcrossRuns(): void {
		// Idempotency at pool close depends on a re-run producing identical
		// figures, including which buyer absorbed the spare unit.
		$reservations = [
			['reservation_id' => 3, 'quantity' => 7],
			['reservation_id' => 1, 'quantity' => 7],
			['reservation_id' => 2, 'quantity' => 7]
		];

		$first = PoolCalculator::allocateFreight($reservations, 1000.00);
		$second = PoolCalculator::allocateFreight($reservations, 1000.00);

		self::assertSame($first, $second);
	}

	public function testFreightSharesFallAsThePoolFills(): void {
		// The point of pooling: the same 9,000 freight spread over more units
		// costs an individual buyer less.
		$early = PoolCalculator::allocateFreight([['reservation_id' => 1, 'quantity' => 10]], 9000.00);

		$late = PoolCalculator::allocateFreight([
			['reservation_id' => 1, 'quantity' => 10],
			['reservation_id' => 2, 'quantity' => 90]
		], 9000.00);

		self::assertSame(9000.00, $early[1]);
		self::assertSame(900.00, $late[1], 'the same commitment now carries a tenth of the freight');
	}

	public function testFreightIgnoresRowsWithoutAReservationId(): void {
		$shares = PoolCalculator::allocateFreight([
			['reservation_id' => 0, 'quantity' => 50],
			['reservation_id' => 4, 'quantity' => 50]
		], 600.00);

		self::assertSame([4 => 600.00], $shares, 'an unidentified row cannot be charged');
	}

	// ------------------------------------------------------------- repricing

	public function testRepricingMovesEveryParticipantToTheUnlockedTier(): void {
		// Three buyers joined while the pool was on the 1380 tier; the pool then
		// crossed 150 units, unlocking 1250 for everyone.
		$reservations = [
			['reservation_id' => 1, 'quantity' => 40, 'unit_price_locked' => 1550.00],
			['reservation_id' => 2, 'quantity' => 60, 'unit_price_locked' => 1380.00],
			['reservation_id' => 3, 'quantity' => 55, 'unit_price_locked' => 1380.00]
		];

		$changes = PoolCalculator::repriceReservations($reservations, self::titanLadder(), 155);

		self::assertCount(3, $changes);

		foreach ($changes as $change) {
			self::assertSame(1250.00, $change['new_unit_price']);
		}

		// Buyer 1 saves (1550 - 1250) * 40 = 12,000.
		self::assertSame(12000.00, $changes[0]['delta']);
		// Buyers 2 and 3 save (1380 - 1250) * qty.
		self::assertSame(7800.00, $changes[1]['delta']);
		self::assertSame(7150.00, $changes[2]['delta']);
	}

	public function testRepricingSkipsReservationsAlreadyOnTheUnlockedPrice(): void {
		$reservations = [
			['reservation_id' => 1, 'quantity' => 40, 'unit_price_locked' => 1250.00],
			['reservation_id' => 2, 'quantity' => 60, 'unit_price_locked' => 1380.00]
		];

		$changes = PoolCalculator::repriceReservations($reservations, self::titanLadder(), 155);

		self::assertCount(1, $changes);
		self::assertSame(2, $changes[0]['reservation_id']);
	}

	public function testRepricingIsSkippedEntirelyWhenDisabled(): void {
		$reservations = [
			['reservation_id' => 1, 'quantity' => 40, 'unit_price_locked' => 1550.00]
		];

		self::assertSame([], PoolCalculator::repriceReservations($reservations, self::titanLadder(), 155, false));
	}

	public function testRepricingDoesNothingWhenNoTierIsUnlocked(): void {
		$reservations = [
			['reservation_id' => 1, 'quantity' => 5, 'unit_price_locked' => 1550.00]
		];

		self::assertSame([], PoolCalculator::repriceReservations($reservations, self::titanLadder(), 5));
	}

	public function testRepricingHandlesAnEmptyParticipantList(): void {
		self::assertSame([], PoolCalculator::repriceReservations([], self::titanLadder(), 200));
	}

	public function testRepricingCanRaisePricesAndReportsANegativeDelta(): void {
		// A pool that shrinks below a tier boundary (cancellations) reprices upward;
		// the delta is negative to make the direction unambiguous.
		$reservations = [
			['reservation_id' => 1, 'quantity' => 10, 'unit_price_locked' => 1250.00]
		];

		$changes = PoolCalculator::repriceReservations($reservations, self::titanLadder(), 60);

		self::assertCount(1, $changes);
		self::assertSame(1380.00, $changes[0]['new_unit_price']);
		self::assertSame(-1300.00, $changes[0]['delta']);
	}

	// --------------------------------------------------------------- savings

	public function testSavingsAgainstRetailMatchesTheLandingHero(): void {
		// Landing hero: pool price 12.40 versus retail 24.99.
		self::assertSame(1259.00, PoolCalculator::savingsAgainst(100, 12.40, 24.99));
	}

	public function testSavingsAreNeverNegative(): void {
		self::assertSame(0.0, PoolCalculator::savingsAgainst(100, 30.00, 24.99));
	}

	// ----------------------------------------------------------- tier ladder

	public function testTierLadderMarksUnlockedActiveAndLockedTiers(): void {
		// Product screen state: 150 of 200 reserved on a 10/50/200 ladder, so the
		// 1380 tier is active and the 1250 tier is 50 units away.
		$ladder = [
			['min_qty' => 10, 'max_qty' => 49, 'price' => 1550.0],
			['min_qty' => 50, 'max_qty' => 199, 'price' => 1380.0],
			['min_qty' => 200, 'max_qty' => null, 'price' => 1250.0]
		];

		$decorated = PoolCalculator::tierLadder($ladder, 150);

		self::assertTrue($decorated[0]['unlocked']);
		self::assertFalse($decorated[0]['active']);
		self::assertSame(0, $decorated[0]['units_needed']);

		self::assertTrue($decorated[1]['unlocked']);
		self::assertTrue($decorated[1]['active']);

		self::assertFalse($decorated[2]['unlocked']);
		self::assertFalse($decorated[2]['active']);
		self::assertSame(50, $decorated[2]['units_needed']);
	}

	public function testTierLadderHasNoActiveTierBeforeTheFirstIsReached(): void {
		$decorated = PoolCalculator::tierLadder(self::titanLadder(), 0);

		self::assertSame([false, false, false], array_column($decorated, 'active'));
		self::assertSame([10, 50, 150], array_column($decorated, 'units_needed'));
	}

	// -------------------------------------------------- cross-check the screens

	public function testFiltrationLadderPricesTheJoinScreenQuantities(): void {
		self::assertSame(1250.00, PoolCalculator::poolUnitPrice(self::filtrationLadder(), 49));
		self::assertSame(1100.00, PoolCalculator::poolUnitPrice(self::filtrationLadder(), 50));
		self::assertSame(1100.00, PoolCalculator::poolUnitPrice(self::filtrationLadder(), 99));
		self::assertSame(950.00, PoolCalculator::poolUnitPrice(self::filtrationLadder(), 100));
	}
}
