<?php
$json_str = '{"symbol": "7203", "company_name": "Toyota Motor Corporation", "last_date": "2025-10-24", "RSI": 73.77, "MACD_cross": "❌", "MA5_25_spread": "拡大", "volume_change_percent": -23.9, "volatility_5d": {"range_yen": 50.8, "range_percent": 1.62}, "beta": 1.052, "earnings_date": null, "credit_ratio": 15.3, "updated": "2025-10-25 05:32:30"}';

$data = json_decode($json_str, true);

$g = function($arr, $keys, $default = null) {
	$cur = $arr;
	foreach ((array)$keys as $k) {
		if (!is_array($cur) || !array_key_exists($k, $cur)) return $default;
		$cur = $cur[$k];
	}
	return $cur;
};

echo "credit_ratio value: ";
var_dump($g($data, 'credit_ratio'));
echo "\n";

echo "Direct access: ";
var_dump($data['credit_ratio']);
echo "\n";

$credit_ratio = $g($data, 'credit_ratio');
$fmt_num = function($val, $decimals = 2) {
	return $val !== null ? number_format($val, $decimals) : '—';
};

echo "Formatted: " . $fmt_num($credit_ratio, 2) . "\n";
