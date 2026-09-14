<?php
declare(strict_types=1);

namespace Tests\Poolbuy;

use Opencart\System\Library\Extension\Poolbuy\PoolPresenter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Display rules, with the emphasis on currency.
 *
 * A pool's prices are authoritative in the pool's OWN currency. The presenter
 * therefore renders each pool in that currency and never converts between them:
 * a 1,000 JPY pool and a 1,000 INR pool are different amounts of money and must
 * not be made to look comparable.
 */
#[CoversClass(PoolPresenter::class)]
final class PoolPresenterTest extends TestCase {
	/**
	 * @param array<string, mixed> $overrides
	 *
	 * @return array<string, mixed>
	 */
	private static function pool(array $overrides = []): array {
		return $overrides + [
			'pool_id'       => 1,
			'product_id'    => 10,
			'reference'     => 'PB-000001',
			'title'         => 'Test pool',
			'unit_label'    => 'Units',
			'currency_code' => 'INR',
			'moq_target'    => 100,
			'reserved_qty'  => 50,
			'status'        => 'active',
			'date_end'      => date('Y-m-d H:i:s', time() + 604800),
			'retail_price'  => 2000.00
		];
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private static function tiers(): array {
		return [
			['tier_id' => 1, 'min_qty' => 1, 'max_qty' => 99, 'price' => 1500.00],
			['tier_id' => 2, 'min_qty' => 100, 'max_qty' => null, 'price' => 1250.00]
		];
	}

	// ------------------------------------------------------------- currencies

	public function testATwoDecimalCurrencyFormatsWithTwoDecimals(): void {
		self::assertSame('1,500.00', PoolPresenter::money(1500.0, '', 2));
	}

	public function testAZeroDecimalCurrencyShowsNoDecimals(): void {
		// JPY has no minor unit. Rendering 1500.00 would invent precision.
		$formatted = PoolPresenter::moneyIn(1500.0, 'JPY');

		self::assertStringContainsString('1,500', $formatted);
		self::assertStringNotContainsString('.', $formatted);
		self::assertSame('1,500', PoolPresenter::money(1500.0, '', 0));
	}

	public function testEachKnownCurrencyResolvesItsOwnSymbolAndPrecision(): void {
		$expected = [
			'INR' => 2,
			'USD' => 2,
			'EUR' => 2,
			'GBP' => 2,
			'AED' => 2,
			'JPY' => 0
		];

		foreach ($expected as $code => $decimals) {
			$meta = PoolPresenter::currencyMeta($code);

			self::assertSame($decimals, $meta['decimals'], $code . ' decimal places');
			self::assertNotSame('', $meta['symbol'], $code . ' has a symbol');
			self::assertSame($code, $meta['code']);
		}
	}

	public function testAnUnknownCurrencyShowsItsCodeRatherThanBorrowingASymbol(): void {
		// Guessing a symbol here would mislabel money. Showing the code is honest.
		$meta = PoolPresenter::currencyMeta('XYZ');

		self::assertSame('XYZ ', $meta['symbol']);
		self::assertStringContainsString('XYZ', PoolPresenter::moneyIn(10.0, 'XYZ'));
	}

	public function testAConfiguredSymbolOnlyAppliesWhenThePoolNamesNoCurrency(): void {
		$configured = PoolPresenter::currencyMeta('', 'Rs.');

		self::assertSame('Rs.', $configured['symbol']);
	}

	public function testAPoolIsPresentedInItsOwnCurrencyNotTheStoreDefault(): void {
		// The store is configured with one symbol, but the pool names USD. The pool
		// wins: it must not be relabelled with another currency's sign.
		$view = PoolPresenter::present(
			self::pool(['currency_code' => 'USD']),
			self::tiers(),
			['symbol' => "\u{20B9}"]
		);

		self::assertSame('USD', $view['currency_code']);
		self::assertSame('$', $view['currency_symbol']);
		self::assertStringStartsWith('$', $view['unit_price_text']);
		self::assertStringNotContainsString("\u{20B9}", $view['unit_price_text']);
	}

	public function testPoolsInDifferentCurrenciesDoNotContaminateEachOther(): void {
		// Rendering a marketplace page means presenting several pools in one pass.
		// Each must keep its own currency.
		$inr = PoolPresenter::present(self::pool(['currency_code' => 'INR']), self::tiers());
		$jpy = PoolPresenter::present(self::pool(['currency_code' => 'JPY']), self::tiers());
		$usd = PoolPresenter::present(self::pool(['currency_code' => 'USD']), self::tiers());

		self::assertSame('INR', $inr['currency_code']);
		self::assertSame('JPY', $jpy['currency_code']);
		self::assertSame('USD', $usd['currency_code']);

		// Same numeric price, three different renderings - and JPY without decimals.
		self::assertSame(2, $inr['currency_decimals']);
		self::assertSame(0, $jpy['currency_decimals']);
		self::assertStringNotContainsString('.', $jpy['unit_price_text']);
		self::assertStringContainsString('.', $inr['unit_price_text']);
	}

	public function testTheNumericPriceIsNeverConvertedBetweenCurrencies(): void {
		// The same tier ladder in two currencies must yield the SAME number. Only
		// the presentation differs. Anything else would be a silent FX conversion.
		$inr = PoolPresenter::present(self::pool(['currency_code' => 'INR']), self::tiers());
		$usd = PoolPresenter::present(self::pool(['currency_code' => 'USD']), self::tiers());

		self::assertSame($inr['unit_price'], $usd['unit_price']);
		self::assertSame($inr['best_price'], $usd['best_price']);
	}

	public function testAPoolWithNoCurrencyFallsBackToTheConfiguredSymbol(): void {
		$view = PoolPresenter::present(
			self::pool(['currency_code' => '']),
			self::tiers(),
			['symbol' => 'Rs.']
		);

		self::assertStringStartsWith('Rs.', $view['unit_price_text']);
	}

	// ---------------------------------------------------------------- freight

	public function testFreightIsPresentedInThePoolCurrency(): void {
		$view = PoolPresenter::present(
			self::pool(['currency_code' => 'JPY', 'freight_total' => 9000.0, 'reserved_qty' => 90]),
			self::tiers()
		);

		self::assertTrue($view['has_freight']);
		self::assertSame(100.0, $view['freight_per_unit'], '9,000 over 90 units');
		self::assertStringNotContainsString('.', $view['freight_per_unit_text'], 'JPY carries no decimals');
	}

	public function testAPoolWithoutFreightReportsNoneRatherThanZero(): void {
		$view = PoolPresenter::present(self::pool(), self::tiers());

		self::assertFalse($view['has_freight']);
		self::assertNull($view['freight_per_unit'], 'null distinguishes "no freight" from "freight of nothing"');
		self::assertSame('', $view['freight_total_text']);
	}
}
