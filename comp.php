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
		$script = escapeshellarg("{$root}/company.py");
		$arg = escapeshellarg($code);
		$cmd = "{$python} {$script} {$arg} 2>&1";
		exec($cmd, $output, $ret);
		$out = implode("\n", (array)$output);
		$json_raw = $out; // 生のJSONを保存
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
	<style>
		.info-table { width: 100%; margin-bottom: 0; }
		.info-table th { width: 30%; background-color: #f8f9fa; font-weight: 600; padding: 12px; border: 1px solid #dee2e6; }
		.info-table td { width: 70%; padding: 12px; border: 1px solid #dee2e6; }
		.info-table tr:hover { background-color: #f1f3f5; }
		.badge-status { font-size: 0.9rem; padding: 0.4em 0.8em; }
		.card-header { background-color: #007bff; color: white; font-weight: 600; }
	</style>
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

			// company.pyの新しいJSON構造に対応
			$symbol = htmlspecialchars((string)($g($data, 'symbol') ?? $code));
			$company_name = htmlspecialchars((string)($g($data, 'company_name') ?? ''));
			$last_date = htmlspecialchars((string)($g($data, 'last_date') ?? ''));
			$current_price = $g($data, 'current_price');
			$rsi = $g($data, 'RSI');
			$macd_cross = htmlspecialchars((string)($g($data, 'MACD_cross') ?? ''));
			$spread_trend = htmlspecialchars((string)($g($data, 'MA5_25_spread') ?? ''));
			$vol_change = $g($data, 'volume_change_percent');
			$volatility = $g($data, 'volatility_5d', []);
			$range_yen = $g($volatility, 'range_yen');
			$range_pct = $g($volatility, 'range_percent');
			$beta = $g($data, 'beta');
			$raw_earnings_date = (string)($g($data, 'earnings_date') ?? '');

// 2. スタイル変数を初期化
$earnings_style = '';

// 3. 生データが空でなければ、日付比較ロジックを実行
if (!empty($raw_earnings_date)) {
    try {
        $today = new DateTime('today');
        $limit_date = (new DateTime('today'))->modify('+3 days');
        $earnings_dt = (new DateTime($raw_earnings_date))->setTime(0, 0, 0);

        // 決算日が「今日」から「3日後」の範囲内か判定
        if ($earnings_dt >= $today && $earnings_dt <= $limit_date) {
            $earnings_style = 'style="background-color: red; color: white; font-weight: bold;"';
        }
    } catch (Exception $e) {
        // 日付形式が無効な場合は何もしない (スタイルは空のまま)
    }
}

// 4. 比較が終わった後、表示用にエスケープ (元の行の役割)
$earnings_date = htmlspecialchars($raw_earnings_date);
			$credit_ratio = $g($data, 'credit_ratio');
			$updated = htmlspecialchars((string)($g($data, 'updated') ?? ''));

			// デバッグ情報
			$debug_info = [
				'executed_command' => $cmd ?? 'N/A',
				'json_raw_preview' => substr($json_raw ?? '', 0, 500),
				'credit_ratio_in_json' => strpos($json_raw ?? '', '"credit_ratio"'),
				'credit_ratio_raw' => $credit_ratio,
				'credit_ratio_type' => gettype($credit_ratio),
				'has_credit_ratio_key' => array_key_exists('credit_ratio', $data ?? []),
				'data_keys' => array_keys($data ?? [])
			];

			// ニュース取得
			$news_block = $g($data, 'news', []);
			$news_list = [];
			if (is_array($news_block)) {
				if (isset($news_block['news']) && is_array($news_block['news'])) {
					$news_list = $news_block['news'];
				} elseif (isset($news_block['items']) && is_array($news_block['items'])) {
					$news_list = $news_block['items'];
				}
			}

			// フォーマット関数
			$fmt_num = function($val, $decimals = 2) {
				return $val !== null ? number_format($val, $decimals) : '—';
			};
			?>

		<!-- 基本情報 -->
		<div class="card mb-3">
			<div class="card-header"><i class="fas fa-info-circle"></i> 基本情報</div>
			<div class="card-body p-0">
				<table class="info-table">
					<tr><th>銘柄コード</th><td><strong><a href="https://kabutan.jp/stock/?code=<?= $symbol ?>" target="_blank"><?= $symbol ?></a></strong></td></tr>
					<tr><th>企業名</th><td><?= $company_name ?: '—' ?></td></tr>
					<tr><th>現在株価</th><td><strong><?= $current_price !== null ? number_format($current_price) . ' 円' : '—' ?></strong></td></tr>
					<tr><th>データ日付</th><td><?= $last_date ?: '—' ?></td></tr>
					<tr><th>更新日時</th><td><?= $updated ?: '—' ?></td></tr>
				</table>
			</div>
		</div>

		<?php
		// 分析サマリー評価関数
		function evaluateRisingPower($data) {
			$g = function($arr, $keys, $default = null) {
				$cur = $arr;
				foreach ((array)$keys as $k) {
					if (!is_array($cur) || !array_key_exists($k, $cur)) return $default;
					$cur = $cur[$k];
				}
				return $cur;
			};

			// 移動平均線の状態を取得（仮定）
			$ma5_trend = $g($data, 'MA5_trend');
			$ma25_trend = $g($data, 'MA25_trend');
			$ma75_trend = $g($data, 'MA75_trend');
			$ma5_25_cross = $g($data, 'MA5_25_cross');
			$macd_cross = $g($data, 'MACD_cross');

			// 上昇パワー評価ロジック
			// スコア 5/5: パーフェクトオーダー（上昇）
			if ($ma5_trend === 'up' && $ma25_trend === 'up' && $ma75_trend === 'up') {
				return 5;
			}
			// スコア 4/5: 5日線と25日線が両方上向き
			elseif ($ma5_trend === 'up' && $ma25_trend === 'up') {
				return 4;
			}
			// スコア 3/5: 5日線は上向きだが25日線は横ばい
			elseif ($ma5_trend === 'up' && $ma25_trend === 'flat') {
				return 3;
			}
			// スコア 2/5: デッドクロス発生
			elseif ($ma5_25_cross === 'dead' || $macd_cross === 'dead') {
				return 2;
			}
			// スコア 1/5: 下降のパーフェクトオーダー
			elseif ($ma5_trend === 'down' && $ma25_trend === 'down' && $ma75_trend === 'down') {
				return 1;
			}
			// デフォルト: 中程度
			return 3;
		}

		function evaluateBuyingPressure($vol_change) {
			if ($vol_change === null) return 3;
			if ($vol_change > 50) return 5;
			if ($vol_change > 20) return 4;
			if ($vol_change > -10) return 3;
			if ($vol_change > -30) return 2;
			return 1;
		}

		function evaluateExhaustionRisk($rsi) {
			if ($rsi === null) return 3;
			if ($rsi > 85) return 5; // ⭕️ 過熱感MAX (スコア 5)
			if ($rsi > 70) return 4; // ⭕️ かなり買われすぎ (スコア 4)
			if ($rsi > 30) return 3; // ⭕️ 過熱感なし (スコア 3)
			if ($rsi > 20) return 2; // ⭕️ やや売られすぎ (スコア 2)
			return 1; // ⭕️ 売られすぎ (スコア 1)
		}

		function evaluateOpportunity($volatility_pct) {
			if ($volatility_pct === null) return 3;
			if ($volatility_pct > 8) return 5; // 高いボラティリティはチャンス大
			if ($volatility_pct > 5) return 4;
			if ($volatility_pct > 2) return 3;
			if ($volatility_pct > 1) return 2;
			return 1; // 低いボラティリティはチャンス小
		}

		function evaluateReboundRisk($credit_ratio) {
			if ($credit_ratio === null) return 3;
			// 信用倍率が低いほどリスク小、高いほどリスク大
			if ($credit_ratio < 1.0) return 1; // 1.0倍未満: 最低リスク
			if ($credit_ratio < 2.0) return 2; // 1.0-2.0倍: リスク少なめ
			if ($credit_ratio < 5.0) return 3; // 2.0-5.0倍: 中立
			if ($credit_ratio < 10.0) return 4; // 5.0-10.0倍: リスク多め
			return 5; // 10.0倍以上: 最高リスク
		}

		// 評価コメント関数
		function getRisingPowerComment($score) {
			switch ($score) {
				case 5: return '最強のパーフェクトオーダー発生中';
				case 4: return '強い上昇トレンド。勢いあり';
				case 3: return '方向感なし。横ばい（レンジ相場）';
				case 2: return '下降トレンドに注意';
				case 1: return '完全な下降トレンド。下落が止まらない';
				default: return '評価不能';
			}
		}

		function getBuyingPressureComment($score) {
			switch ($score) {
				case 5: return '買い注文が殺到中。出来高が爆発';
				case 4: return '市場の注目が集まっている（出来高 増加）';
				case 3: return '出来高は平凡。様子見ムード';
				case 2: return '買い手が少ない。関心が薄い';
				case 1: return '買い手不在。完全に閑散';
				default: return '評価不能';
			}
		}

		function getExhaustionRiskComment($score) {
			switch ($score) {
				case 5: return '過熱感MAX。いつ急落してもおかしくない危険水域';
				case 4: return 'かなり買われすぎ。高値警戒';
				case 3: return '過熱感なし。まだ余力あり';
				case 2: return 'やや売られすぎ。反発注意';
				case 1: return '売られすぎ。絶好の反発狙いポイント';
				default: return '評価不能';
			}
		}

		function getOpportunityComment($score) {
			switch ($score) {
				case 5: return '値幅期待MAX。デイトレチャンスの宝庫';
				case 4: return '値動きが活発。チャンス多め';
				case 3: return '値動きは平凡。手堅いトレード向き';
				case 2: return '値動きが鈍い。チャンス少なめ';
				case 1: return '値動きなし。この銘柄は今日触るべきではない';
				default: return '評価不能';
			}
		}

		function getReboundRiskComment($score) {
			switch ($score) {
				case 5: return '反発の危険性（大）！ 売り圧力が非常に強い';
				case 4: return '反発の危険性（あり）！ 売り圧力多め';
				case 3: return '需給は中立。売り圧力は普通';
				case 2: return '反発の危険性（なし）！ 売り圧力少なめ';
				case 1: return '反発の危険性（ゼロ）！ 売り圧力が非常に少ない';
				default: return '評価不能';
			}
		}

		// 分析サマリーの評価
		$rising_power_score = evaluateRisingPower($data);
		$buying_pressure_score = evaluateBuyingPressure($vol_change);
		$exhaustion_risk_score = evaluateExhaustionRisk($rsi);
		$opportunity_score = evaluateOpportunity($range_pct);
		$rebound_risk_score = evaluateReboundRisk($credit_ratio);

		// 総合評価点の計算（100点満点）- デイトレ用配点
		// 買い圧力: 30点、チャンス: 30点、上昇パワー: 15点、息切れリスク: -15点、反発リスク: -10点
		$total_score = 0;
		$total_score += $buying_pressure_score * 6; // 5点×6 = 30点（最重要）
		$total_score += $opportunity_score * 6; // 5点×6 = 30点（最重要）
		$total_score += $rising_power_score * 3; // 5点×3 = 15点

		// ★修正: 息切れリスク（スコア5が最大リスク）
		// (スコア5 * 3 = 15点減点)
		$total_score -= $exhaustion_risk_score * 3;

		// ★修正: 反発リスク（スコア5が最大リスク）
		// (スコア5 * 2 = 10点減点)
		$total_score -= $rebound_risk_score * 2;

		// 0-100点に収める
		$total_score = max(0, min(100, $total_score));
		?>

		<!-- CCI分析 -->
		<?php
		$cci_analysis = $g($data, 'cci_analysis', []);
		$cci_score = $g($cci_analysis, 'cci_score', 0);
		$cci_expectation = htmlspecialchars((string)($g($cci_analysis, 'cci_expectation') ?? ''));
		$cci_value = $g($cci_analysis, 'cci_value');
		$cci_previous = $g($cci_analysis, 'cci_previous');
		?>
		<div class="card mb-3">
			<div class="card-header"><i class="fas fa-chart-line"></i> CCI分析 - 翌日株価期待値</div>
			<div class="card-body">
				<!-- CCI総合評価 -->
				<div class="text-center mb-4 p-3 bg-light rounded">
					<h4 class="mb-2">CCIスコア</h4>
					<div class="display-4 font-weight-bold <?= $cci_score >= 5 ? 'text-success' : ($cci_score <= -5 ? 'text-danger' : 'text-warning') ?>">
						<?= $cci_score ?>/10点
					</div>
					<div class="mt-2">
						<strong><?= $cci_expectation ?></strong>
					</div>
					<small class="text-muted">CCI(14)ベースの翌日株価方向性予測</small>
				</div>

				<div class="row">
					<div class="col-md-6 mb-3">
						<h6>CCI値</h6>
						<div class="d-flex justify-content-between align-items-center">
							<div>
								<strong><?= $cci_value !== null ? number_format($cci_value, 2) : '—' ?></strong>
								<?php if ($cci_value !== null): ?>
									<br><small class="text-muted">当日</small>
								<?php endif; ?>
							</div>
							<div>
								<strong><?= $cci_previous !== null ? number_format($cci_previous, 2) : '—' ?></strong>
								<?php if ($cci_previous !== null): ?>
									<br><small class="text-muted">前日</small>
								<?php endif; ?>
							</div>
						</div>
					</div>
					<div class="col-md-6 mb-3">
						<h6>CCIシグナル</h6>
						<div class="small">
							<?php if ($cci_score >= 10): ?>
								<span class="badge badge-success">非常に強い買い推奨</span>
							<?php elseif ($cci_score >= 5): ?>
								<span class="badge badge-success">買い推奨</span>
							<?php elseif ($cci_score <= -10): ?>
								<span class="badge badge-danger">非常に強い売り推奨</span>
							<?php elseif ($cci_score <= -5): ?>
								<span class="badge badge-danger">売り推奨</span>
							<?php else: ?>
								<span class="badge badge-warning">中立（様子見）</span>
							<?php endif; ?>
						</div>
						<small class="text-muted">CCI(14)ライン反転・クロス分析</small>
					</div>
				</div>

				<!-- CCI解説 -->
				<div class="mt-3 p-3 bg-light rounded">
					<h6>CCI分析の見方</h6>
					<ul class="small mb-0">
						<li><strong>優先度1: トレンド継続（順張り）</strong></li>
						<ul class="small">
							<li><strong>+100以上:</strong> 買いトレンド継続（加速: +5点, 維持: +3点）</li>
							<li><strong>-100以下:</strong> 売りトレンド継続（加速: -5点, 維持: -3点）</li>
						</ul>
						<li><strong>優先度2: トレンド転換（反転）</strong></li>
						<ul class="small">
							<li><strong>±200ライン反転:</strong> 最強の転換シグナル（±6点）</li>
							<li><strong>±100ライン反転:</strong> 通常の転換シグナル（±4点）</li>
						</ul>
						<li><strong>優先度3: トレンド発生（ゼロクロス）</strong></li>
						<ul class="small">
							<li><strong>ゼロラインクロス:</strong> トレンド発生シグナル（±3点）</li>
						</ul>
						<li><strong>補強要素:</strong> 出来高増加・ローソク足パターンで±2点追加</li>
					</ul>
				</div>
			</div>
		</div>

		<!-- 分析サマリー -->
		<div class="card mb-3">
			<div class="card-header"><i class="fas fa-chart-bar"></i> 分析サマリー</div>
			<div class="card-body">
				<!-- 総合評価 -->
				<div class="text-center mb-4 p-3 bg-light rounded">
					<h4 class="mb-2">明日上昇する可能性</h4>
					<div class="display-4 font-weight-bold <?= $total_score >= 70 ? 'text-success' : ($total_score >= 50 ? 'text-warning' : 'text-danger') ?>">
						<?= round($total_score) ?>点
					</div>
					<small class="text-muted">100点満点評価</small>
				</div>

				<div class="row">
					<div class="col-md-6 mb-3">
						<h6>上昇パワー (トレンド)</h6>
						<div class="progress mb-2" style="height: 20px;">
							<div class="progress-bar bg-success" role="progressbar" style="width: <?= $rising_power_score * 20 ?>%" aria-valuenow="<?= $rising_power_score ?>" aria-valuemin="1" aria-valuemax="5"></div>
						</div>
						<div class="small text-muted"><?= getRisingPowerComment($rising_power_score) ?></div>
						<small class="text-muted">MA5/MA25 乖離トレンド</small>
					</div>
					<div class="col-md-6 mb-3">
						<h6>買い圧力 (出来高)</h6>
						<div class="progress mb-2" style="height: 20px;">
							<div class="progress-bar bg-info" role="progressbar" style="width: <?= $buying_pressure_score * 20 ?>%" aria-valuenow="<?= $buying_pressure_score ?>" aria-valuemin="1" aria-valuemax="5"></div>
						</div>
						<div class="small text-muted"><?= getBuyingPressureComment($buying_pressure_score) ?></div>
						<small class="text-muted">出来高変化率</small>
					</div>
					<div class="col-md-6 mb-3">
						<h6>息切れリスク (RSI / 過熱感)</h6>
						<div class="progress mb-2" style="height: 20px;">
							<div class="progress-bar bg-warning" role="progressbar" style="width: <?= $exhaustion_risk_score * 20 ?>%" aria-valuenow="<?= $exhaustion_risk_score ?>" aria-valuemin="1" aria-valuemax="5"></div>
						</div>
						<div class="small text-muted"><?= getExhaustionRiskComment($exhaustion_risk_score) ?></div>
						<small class="text-muted">RSI 14日</small>
					</div>
					<div class="col-md-6 mb-3">
						<h6>チャンス (ボラティリティ)</h6>
						<div class="progress mb-2" style="height: 20px;">
							<div class="progress-bar bg-primary" role="progressbar" style="width: <?= $opportunity_score * 20 ?>%" aria-valuenow="<?= $opportunity_score ?>" aria-valuemin="1" aria-valuemax="5"></div>
						</div>
						<div class="small text-muted"><?= getOpportunityComment($opportunity_score) ?></div>
						<small class="text-muted">最新日ボラティリティ</small>
					</div>
					<div class="col-md-6 mb-3">
						<h6>反発リスク (信用倍率)</h6>
						<div class="progress mb-2" style="height: 20px;">
							<div class="progress-bar bg-danger" role="progressbar" style="width: <?= $rebound_risk_score * 20 ?>%" aria-valuenow="<?= $rebound_risk_score ?>" aria-valuemin="1" aria-valuemax="5"></div>
						</div>
						<div class="small text-muted"><?= getReboundRiskComment($rebound_risk_score) ?></div>
						<small class="text-muted">信用倍率</small>
					</div>
				</div>
			</div>
		</div>

			<!-- テクニカル指標 -->
			<div class="card mb-3">
				<div class="card-header"><i class="fas fa-chart-line"></i> テクニカル指標</div>
				<div class="card-body p-0">
					<table class="info-table">
						<tr>
							<th>RSI (14日)</th>
							<td><?= $fmt_num($rsi, 2) ?></td>
						</tr>
						<tr>
							<th>MACDクロス</th>
							<td><?= $macd_cross ?: '—' ?></td>
						</tr>
						<tr>
							<th>MA5/MA25 乖離トレンド</th>
							<td><?= $spread_trend ?: '—' ?></td>
						</tr>
						<tr>
							<th>出来高変化率 (%)</th>
							<td><?= $fmt_num($vol_change, 2) ?>%</td>
						</tr>
						<tr>
							<th>5日平均ボラティリティ (円)</th>
							<td><?= $fmt_num($range_yen, 2) ?> 円</td>
						</tr>
						<tr>
							<th>5日平均ボラティリティ (%)</th>
							<td><?= $fmt_num($range_pct, 2) ?>%</td>
						</tr>
						<tr>
							<th>ベータ値 (β)</th>
							<td>
								<?= $fmt_num($beta, 3) ?>
								<?php if ($beta !== null): ?>
									<br><small class="text-muted">日経平均が1%上昇すればこの株は<?= number_format(abs($beta), 3) ?>%上昇し、逆に日経平均が1%下落すれば<?= number_format(abs($beta), 3) ?>%下落しやすい事を意味しています</small>
								<?php endif; ?>
							</td>
						</tr>
					</table>
				</div>
			</div>

			<!-- ファンダメンタルズ・外部情報 -->
			<div class="card mb-3">
				<div class="card-header"><i class="fas fa-building"></i> ファンダメンタルズ・外部情報</div>
				<div class="card-body p-0">
					<table class="info-table">
						<tr>
							<th>次回決算発表日</th>
							<td><?= $earnings_date ?: '—' ?></td>
						</tr>
						<tr>
							<th>信用倍率</th>
							<td><?= $fmt_num($credit_ratio, 2) ?></td>
						</tr>
					</table>
				</div>
			</div>

			<!-- ニュース -->
			<div class="card mb-3">
				<div class="card-header"><i class="fas fa-newspaper"></i> 最新ニュース (最大10件)</div>
				<div class="list-group list-group-flush">
					<?php if (empty($news_list)): ?>
						<div class="list-group-item text-muted">ニュースが取得できませんでした。</div>
					<?php else: ?>
						<?php foreach ($news_list as $n): ?>
							<a class="list-group-item list-group-item-action" href="<?= htmlspecialchars($n['url'] ?? '#') ?>" target="_blank" rel="noopener">
								<div class="d-flex w-100 justify-content-between align-items-start">
									<div class="flex-grow-1">
										<i class="fas fa-external-link-alt text-primary mr-2"></i>
										<?= htmlspecialchars($n['title'] ?? 'タイトルなし') ?>
									</div>
									<small class="text-muted ml-3"><?= htmlspecialchars($n['published_at'] ?? '') ?></small>
								</div>
							</a>
						<?php endforeach; ?>
					<?php endif; ?>
				</div>
			</div>

			<!-- Debug JSON -->
			<div class="card mb-3">
				<div class="card-header"><i class="fas fa-code"></i> Debug JSON</div>
				<div class="card-body">
					<h6>信用倍率デバッグ:</h6>
					<pre class="m-0 mb-3" style="white-space:pre-wrap; word-break:break-all; font-size: 0.85rem;"><?= htmlspecialchars(json_encode($debug_info, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)) ?></pre>
					<h6>全データ:</h6>
					<pre class="m-0" style="white-space:pre-wrap; word-break:break-all; font-size: 0.85rem;"><?= htmlspecialchars(json_encode($data, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)) ?></pre>
				</div>
			</div>

		<?php endif; ?>
	</div>
</body>
</html>
