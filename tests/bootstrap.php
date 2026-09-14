<?php
/**
 * PHPUnit bootstrap for the PoolBuy test suite.
 *
 * PoolBuy's domain classes are deliberately free of OpenCart dependencies, so
 * the tests load them directly instead of booting the framework. That keeps the
 * suite fast and means a failing test points at a pricing rule rather than at
 * some incidental environment problem.
 *
 * OpenCart's own autoloader maps CamelCase class names onto snake_case files
 * (PoolCalculator -> pool_calculator.php); the map below mirrors that so test
 * files can refer to classes by their real namespaced names.
 */

declare(strict_types=1);

$library = __DIR__ . '/../upload/extension/poolbuy/system/library/';

$classmap = [
	'Opencart\\System\\Library\\Extension\\Poolbuy\\PoolCalculator'          => $library . 'pool_calculator.php',
	'Opencart\\System\\Library\\Extension\\Poolbuy\\PoolLifecycle'           => $library . 'pool_lifecycle.php',
	'Opencart\\System\\Library\\Extension\\Poolbuy\\PoolPresenter'           => $library . 'pool_presenter.php',
	'Opencart\\System\\Library\\Extension\\Poolbuy\\PaymentGatewayInterface' => $library . 'payment_gateway_interface.php',
	'Opencart\\System\\Library\\Extension\\Poolbuy\\MockGateway'             => $library . 'mock_gateway.php',
	'Opencart\\System\\Library\\Extension\\Poolbuy\\RazorpayGateway'         => $library . 'razorpay_gateway.php',
	'Opencart\\System\\Library\\Extension\\Poolbuy\\GatewayFactory'          => $library . 'gateway_factory.php',
	'Opencart\\System\\Library\\Extension\\Poolbuy\\RateLimiter'             => $library . 'rate_limiter.php'
];

spl_autoload_register(static function (string $class) use ($classmap): void {
	if (isset($classmap[$class]) && is_file($classmap[$class])) {
		require_once $classmap[$class];
	}
});
