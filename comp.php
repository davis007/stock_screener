<?php
header('Content-Type: text/html; charset=utf-8');

function toHalfWidth($str) {
	return mb_convert_kana($str, 'n', 'UTF-8'); // 全角→半角数字
}

$code = isset($_GET['code']) ? toHalfWidth($_GET['code']) : '';
$code = strtoupper(trim($code));
$code = preg_replace('/[^0-9A-Z]/', '', $code); // 不要文字除去

$root = __DIR__;
$json = null;
$error = null;
$data = null;

if ($code !== '') {
	if (!preg_match('/^[0-9A-Z]{3,5}$/', $code)) {
		$error = "❌ 無効なコード形式です（例: 7203, 285A, 2768T など）: {$code}";
	} else {
		$python = 'python3';
		$cmd = escapeshellcmd("{$python} {$root}/company.py " . escapeshellarg($code)) . ' 2>&1';
		exec($cmd, $output, $ret);
		$out = implode("\n", (array)$output);
		$data = json_decode($out, true);
		if ($ret !== 0) {
			$error = "❌ Pythonスクリプトの実行に失敗しました。\n<pre>" . htmlspecialchars($out) . "</pre>";
		} elseif (json_last_error() !== JSON_ERROR_NONE) {
			$error = "❌ JSONの解析に失敗しました。\n<pre>" . htmlspecialchars($out) . "</pre>";
		}
	}
}
?>
<!doctype html>
<html lang="ja">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>銘柄レポート</title>
	<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
	<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
</head>
<body class="bg-light">
	<div class="container py-4">
		<h1 class="mb-4">銘柄レポート</h1>

		<form class="mb-4" method="get">
			<div class="form-row align-items-end">
				<div class="col-auto">
					<label for="code">銘柄コード（英数字3〜5桁）</label>
					<input type="text" id="code" name="code" class="form-control" maxlength="5" value="<?= htmlspecialchars($code) ?>" required>
				</div>
				<div class="col-auto">
					<button type="submit" class="btn btn-primary">取得</button>
				</div>
			</div>
		</form>

		<?php if ($error): ?>
			<div class="alert alert-danger" role="alert"><?= $error ?></div>
		<?php elseif ($code === ''): ?>
			<div class="alert alert-info">銘柄コードを入力してください。</div>
		<?php elseif (!$data): ?>
			<div class="alert alert-warning">データの取得に失敗しました。</div>
		<?php else: ?>

			<?php
			// 安全取得ヘルパ
			$g = function($arr, $keys, $default = null) {
				$cur = $arr;
				foreach ((array)$keys as $k) {
					if (!is_array($cur) || !array_key_exists($k, $cur)) return $default;
					$cur = $cur[$k];
				}
				return $cur;
			};

			$symbol = htmlspecialchars($g($data, ['symbol'], $code));
			$latest_date = htmlspecialchars($g($data, ['latest','date'], ''));
			$close = $g($data, ['latest','close']);
			$open  = $g($data, ['latest','open']);
			$high  = $g($data, ['latest','high']);
			$low   = $g($data, ['latest','low']);
			$vol   = $g($data, ['latest','volume']);

			$rsi_v = $g($data, ['rsi','value']);
			$rsi_c = htmlspecialchars($g($data, ['rsi','comment'], ''));

			$macd_cross = htmlspecialchars($g($data, ['macd','cross'], ''));
			$macd_dir   = htmlspecialchars($g($data, ['macd','direction'], ''));
			$macd_conf  = $g($data, ['macd','confidence_percent']);

			$dev5 = $g($data, ['moving_averages','dev5_percent']);
			$dev25 = $g($data, ['moving_averages','dev25_percent']);
			$spread = htmlspecialchars($g($data, ['moving_averages','spread_trend'], ''));
			$volchg = $g($data, ['moving_averages','volume_change_percent']);

			$rng_yen = $g($data, ['volatility_5d','avg_range_yen']);
			$rng_pct = $g($data, ['volatility_5d','avg_range_percent']);

			$beta = $g($data, ['beta']);
			$earn_date = htmlspecialchars($g($data, ['earnings_next','date'], ''));
			$credit_val = $g($data, ['credit_ratio','value']);

			$final = htmlspecialchars($g($data, ['final_judgement'], ''));
			$signals = (array)$g($data, ['signals'], []);

			$news_block = $g($data, ['news'], []);
			$news_list = [];
			if (is_array($news_block)) {
				if (isset($news_block['news']) && is_array($news_block['news'])) {
					$news_list = $news_block['news'];
				} elseif (isset($news_block['items']) && is_array($news_block['items'])) {
					$news_list = $news_block['items'];
				}
			}
			?>

			<div class="card mb-3">
				<div class="card-header">サマリー</div>
				<div class="card-body">
					<p class="mb-1">銘柄: <strong><?= $symbol ?></strong> / 日付: <?= $latest_date ?></p>
					<p class="mb-1">終値: <?= htmlspecialchars((string)$close) ?> / 始値: <?= htmlspecialchars((string)$open) ?> / 高値: <?= htmlspecialchars((string)$high) ?> / 安値: <?= htmlspecialchars((string)$low) ?> / 出来高: <?= htmlspecialchars((string)$vol) ?></p>
				</div>
			</div>

			<div class="card mb-3">
				<div class="card-header">テクニカル</div>
				<div class="card-body">
					<ul class="mb-0">
						<li>RSI(14): <?= htmlspecialchars($rsi_v === null ? '—' : (string)round($rsi_v,2)) ?>（<?= $rsi_c ?>）</li>
						<li>MACD: クロス = <?= $macd_cross ?> / 方向 = <?= $macd_dir ?> / 信頼度 = <?= htmlspecialchars($macd_conf === null ? '—' : (string)$macd_conf) ?>%</li>
						<li>移動平均乖離: DEV5 = <?= htmlspecialchars($dev5 === null ? '—' : (string)$dev5) ?>% / DEV25 = <?= htmlspecialchars($dev25 === null ? '—' : (string)$dev25) ?>% / 乖離トレンド = <?= $spread ?></li>
						<li>出来高変化率: <?= htmlspecialchars($volchg === null ? '—' : (string)$volchg) ?>%</li>
						<li>5日平均ボラ: <?= htmlspecialchars($rng_yen === null ? '—' : (string)$rng_yen) ?>円（<?= htmlspecialchars($rng_pct === null ? '—' : (string)$rng_pct) ?>%）</li>
						<li>β: <?= htmlspecialchars($beta === null ? '—' : (string)$beta) ?></li>
					</ul>
				</div>
			</div>

			<div class="card mb-3">
				<div class="card-header">ファンダ・外部情報</div>
				<div class="card-body">
					<ul class="mb-0">
						<li>次回決算発表日: <?= $earn_date ?: '—' ?></li>
						<li>信用倍率: <?= htmlspecialchars($credit_val === null ? '—' : (string)$credit_val) ?></li>
					</ul>
				</div>
			</div>

			<div class="card mb-3">
				<div class="card-header">シグナル</div>
				<div class="card-body">
					<p class="mb-1">判定: <strong><?= $final ?></strong></p>
					<p class="mb-0">内訳: <?= htmlspecialchars(implode('・', $signals)) ?></p>
				</div>
			</div>

			<div class="card mb-3">
				<div class="card-header">ニュース（最大10件）</div>
				<div class="list-group list-group-flush">
					<?php if (!$news_list): ?>
						<div class="list-group-item text-muted">ニュースが取得できませんでした。</div>
					<?php else: foreach ($news_list as $n): ?>
						<a class="list-group-item list-group-item-action" href="<?= htmlspecialchars($n['url'] ?? '#') ?>" target="_blank">
							<?= htmlspecialchars($n['title'] ?? '') ?>
							<small class="text-muted ml-2"><?= htmlspecialchars($n['published_at'] ?? '') ?></small>
						</a>
					<?php endforeach; endif; ?>
				</div>
			</div>

			<div class="card">
				<div class="card-header">Debug JSON</div>
				<pre class="m-0 p-3" style="white-space:pre-wrap; word-break:break-all;"><?= htmlspecialchars(json_encode($data, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)) ?></pre>
			</div>

		<?php endif; ?>
	</div>
</body>
</html>