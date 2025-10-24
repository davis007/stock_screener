<?php
/**
 * スイングトレード銘柄分析 - 日別分析結果ページ
 * DBから指定日付の分析結果を取得して表示
 */

// SQLiteデータベースに接続
$db_path = __DIR__ . '/stock_analysis.db';

if (!file_exists($db_path)) {
	die('エラー: データベースファイルが見つかりません。');
}

try {
	$pdo = new PDO('sqlite:' . $db_path);
	$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
	die('データベース接続エラー: ' . $e->getMessage());
}

// 日付パラメータを取得
$analysis_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');

// 日付フォーマットの検証
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $analysis_date)) {
	die('エラー: 無効な日付形式です。');
}

// 三位一体モデルの分析結果を取得
$stmt = $pdo->prepare('
	SELECT ar.*, c.name as company_name
	FROM analysis_results ar
	LEFT JOIN companies c ON ar.code = c.code
	WHERE ar.analysis_date = ?
	ORDER BY ar.score DESC
');
$stmt->execute([$analysis_date]);
$trinity_results = $stmt->fetchAll(PDO::FETCH_ASSOC);

// バンドウォーク検出結果を取得
$stmt = $pdo->prepare('
	SELECT br.*, c.name as company_name
	FROM bandwalk_results br
	LEFT JOIN companies c ON br.code = c.code
	WHERE br.analysis_date = ?
	ORDER BY br.code
');
$stmt->execute([$analysis_date]);
$bandwalk_results = $stmt->fetchAll(PDO::FETCH_ASSOC);

// エクスパンション検出結果を取得
$stmt = $pdo->prepare('
	SELECT er.*, c.name as company_name
	FROM expansion_results er
	LEFT JOIN companies c ON er.code = c.code
	WHERE er.analysis_date = ?
	ORDER BY er.code
');
$stmt->execute([$analysis_date]);
$expansion_results = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 日付をフォーマット
$date_obj = DateTime::createFromFormat('Y-m-d', $analysis_date);
$today = $date_obj->format('Y年m月d日');

// ヘルパー関数
function format_price($price) {
	return (int)round($price) . '円';
}

function format_percentage($current_price, $target_price) {
	if (!$target_price) return '-';
	$percentage = (($target_price - $current_price) / $current_price) * 100;
	$price_str = format_price($target_price);
	return $price_str . ' (' . sprintf('%+d', (int)round($percentage)) . '%)';
}

function get_score_class($score) {
	if ($score >= 70) return 'score-high';
	if ($score >= 50) return 'score-medium';
	return 'score-low';
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>スイングトレード銘柄分析 - <?php echo htmlspecialchars($today); ?></title>
	<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
	<style>
		* {
			margin: 0;
			padding: 0;
			box-sizing: border-box;
		}
		body {
			font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
			background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
			min-height: 100vh;
			display: flex;
			flex-direction: column;
		}
		.container {
			max-width: 1200px;
			margin: 0 auto;
			width: 100%;
		}
		.header {
			background: white;
			padding: 20px 30px;
			box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
			flex-shrink: 0;
		}
		.header h1 {
			color: #333;
			margin-bottom: 5px;
		}
		.header p {
			color: #666;
			font-size: 14px;
		}
		.header a {
			color: #667eea;
			text-decoration: none;
			margin-right: 20px;
		}
		.header a:hover {
			text-decoration: underline;
		}
		.content {
			flex: 1;
			overflow-y: auto;
			padding: 20px;
		}
		.section {
			background: white;
			padding: 30px;
			border-radius: 10px;
			margin-bottom: 30px;
			box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
		}
		.section h2 {
			color: #333;
			margin-bottom: 20px;
			border-bottom: 3px solid #667eea;
			padding-bottom: 10px;
		}
		table {
			width: 100%;
			border-collapse: collapse;
		}
		th {
			background: #667eea;
			color: white;
			padding: 15px;
			text-align: left;
			font-weight: 600;
		}
		td {
			padding: 12px 15px;
			border-bottom: 1px solid #eee;
		}
		tr:hover {
			background: #f5f5f5;
		}
		table a {
			color: #667eea;
			text-decoration: none;
			font-weight: 500;
		}
		table a:hover {
			text-decoration: underline;
		}
		.score-high {
			color: #27ae60;
			font-weight: bold;
		}
		.score-medium {
			color: #f39c12;
			font-weight: bold;
		}
		.score-low {
			color: #e74c3c;
			font-weight: bold;
		}
		.badge {
			display: inline-block;
			padding: 5px 10px;
			border-radius: 20px;
			font-size: 12px;
			font-weight: bold;
		}
		.badge-yes {
			background: #27ae60;
			color: white;
		}
		.badge-no {
			background: #95a5a6;
			color: white;
		}
		.filter-btn {
			padding: 8px 16px;
			border: 2px solid #667eea;
			background: white;
			color: #667eea;
			border-radius: 20px;
			cursor: pointer;
			font-weight: 600;
			font-size: 14px;
			transition: all 0.3s ease;
		}
		.filter-btn:hover {
			background: #f0f0ff;
		}
		.filter-btn.active {
			background: #667eea;
			color: white;
		}
		.bandwalk-row.hidden {
			display: none;
		}
		.expansion-row.hidden {
			display: none;
		}
		.tooltip-container {
			position: relative;
			display: inline-flex;
			align-items: center;
			gap: 5px;
			cursor: help;
		}
		.tooltip-icon {
			display: inline-flex;
			align-items: center;
			justify-content: center;
			width: 18px;
			height: 18px;
			background: #667eea;
			color: white;
			border-radius: 50%;
			font-size: 12px;
			font-weight: bold;
			cursor: pointer;
		}
		.tooltip-icon:hover {
			background: #764ba2;
		}
		.tooltip-text {
			visibility: hidden;
			width: 350px;
			background: #333;
			color: #fff;
			text-align: left;
			border-radius: 8px;
			padding: 12px;
			position: absolute;
			z-index: 1000;
			bottom: 125%;
			left: 50%;
			margin-left: -175px;
			opacity: 0;
			transition: opacity 0.3s;
			font-size: 12px;
			line-height: 1.6;
			box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
			white-space: pre-wrap;
			word-wrap: break-word;
		}
		.tooltip-text::after {
			content: "";
			position: absolute;
			top: 100%;
			left: 50%;
			margin-left: -5px;
			border-width: 5px;
			border-style: solid;
			border-color: #333 transparent transparent transparent;
		}
		.tooltip-container:hover .tooltip-text {
			visibility: visible;
			opacity: 1;
		}
		.footer {
			background: white;
			padding: 20px 30px;
			text-align: center;
			color: #666;
			font-size: 12px;
			flex-shrink: 0;
			border-top: 1px solid #eee;
		}
		.detail-icon {
			color: #667eea;
			margin-left: 6px;
			cursor: pointer;
			transition: color 0.3s ease;
		}
		.detail-icon:hover {
			color: #764ba2;
		}
	</style>
</head>
<body>
	<div class="header">
		<h1>🎯 スイングトレード銘柄分析</h1>
		<p>分析日時: <?php echo htmlspecialchars($today); ?> | <a href="index.php">📅 分析履歴</a></p>
	</div>

	<div class="content">
		<div class="container">
			<?php if (count($bandwalk_results) > 0): ?>
				<div class="section">
					<h2>🚀 バンドウォーク検出銘柄</h2>

					<!-- フィルターボタン -->
					<div style="margin-bottom: 15px; display: flex; gap: 10px;">
						<button class="filter-btn" onclick="filterBandwalk('all', this)">全部表示</button>
						<button class="filter-btn active" onclick="filterBandwalk('active', this)">発生中のみ</button>
					</div>

					<table id="bandwalk-table">
						<thead>
							<tr>
								<th>企業名</th>
								<th>銘柄コード</th>
								<th>価格</th>
								<th>バンドウォーク</th>
								<th>BBバンド幅</th>
								<th>利食い目標</th>
								<th>損切りライン</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($bandwalk_results as $row): ?>
								<?php $is_bandwalk = $row['is_bandwalk'] ? true : false; ?>
								<tr class="bandwalk-row" data-bandwalk="<?php echo $is_bandwalk ? 'active' : 'inactive'; ?>">
									<td>
										<a href="https://kabutan.jp/stock/chart?code=<?php echo htmlspecialchars($row['code']); ?>" target="_blank">
											<?php echo htmlspecialchars($row['company_name']); ?>
										</a>
										<a href="comp.php?code=<?php echo htmlspecialchars($row['code']); ?>" target="_blank" title="詳細を見る">
											<i class="fa-solid fa-circle-info detail-icon"></i>
										</a>
									</td>
									<td><?php echo htmlspecialchars($row['code']); ?></td>
									<td><?php echo format_price($row['price']); ?></td>
									<td><span class="badge <?php echo $is_bandwalk ? 'badge-yes' : 'badge-no'; ?>"><?php echo $is_bandwalk ? '⭕ 発生中' : '❌ なし'; ?></span></td>
									<td><?php echo (int)$row['bb_width']; ?></td>
									<td style="color: #27ae60; font-weight: bold;"><?php echo format_percentage($row['price'], $row['profit_target']); ?></td>
									<td style="color: #e74c3c; font-weight: bold;"><?php echo format_percentage($row['price'], $row['stop_loss']); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>

			<?php if (count($expansion_results) > 0): ?>
				<div class="section">
					<h2>📈 エクスパンション検出銘柄</h2>

					<!-- フィルターボタン -->
					<div style="margin-bottom: 15px; display: flex; gap: 10px;">
						<button class="filter-btn" onclick="filterExpansion('all', this)">全部表示</button>
						<button class="filter-btn active" onclick="filterExpansion('active', this)">発生中のみ</button>
					</div>

					<table id="expansion-table">
						<thead>
							<tr>
								<th>企業名</th>
								<th>銘柄コード</th>
								<th>価格</th>
								<th>エクスパンション</th>
								<th>BBバンド幅</th>
								<th>拡大率</th>
								<th>利食い目標</th>
								<th>損切りライン</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($expansion_results as $row): ?>
								<?php $is_expansion = $row['is_expansion'] ? true : false; ?>
								<tr class="expansion-row" data-expansion="<?php echo $is_expansion ? 'active' : 'inactive'; ?>">
									<td>
										<a href="https://kabutan.jp/stock/chart?code=<?php echo htmlspecialchars($row['code']); ?>" target="_blank">
											<?php echo htmlspecialchars($row['company_name']); ?>
										</a>
										<a href="comp.php?code=<?php echo htmlspecialchars($row['code']); ?>" target="_blank" title="詳細を見る">
											<i class="fa-solid fa-circle-info detail-icon"></i>
										</a>
									</td>
									<td><?php echo htmlspecialchars($row['code']); ?></td>
									<td><?php echo format_price($row['price']); ?></td>
									<td><span class="badge <?php echo $is_expansion ? 'badge-yes' : 'badge-no'; ?>"><?php echo $is_expansion ? '⭕ 発生中' : '❌ なし'; ?></span></td>
									<td><?php echo (int)$row['bb_width']; ?></td>
									<td style="color: #3498db; font-weight: bold;"><?php echo (int)$row['expansion_rate']; ?>%</td>
									<td style="color: #27ae60; font-weight: bold;"><?php echo format_percentage($row['price'], $row['profit_target']); ?></td>
									<td style="color: #e74c3c; font-weight: bold;"><?php echo format_percentage($row['price'], $row['stop_loss']); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>

			<?php if (count($trinity_results) > 0): ?>
				<div class="section">
					<h2>📊 三位一体モデル評価（スコア順）</h2>
					<table>
						<thead>
							<tr>
								<th>企業名</th>
								<th>銘柄コード</th>
								<th>現在価格</th>
								<th>スコア</th>
								<th>
									<div class="tooltip-container">
										RSI_9
										<span class="tooltip-icon">?</span>
										<span class="tooltip-text">📈 RSI_9（相対力指数・9期間）
• 直近9本のローソク足の値動きの強弱を数値化。
• 0〜100で表し、70以上は買われすぎ、30以下は売られすぎ。
• 短期トレードでは反転タイミングを狙う「振り子型インジケーター」。
• 値動きの初動確認や、短期過熱・反発サイン検出に強い。</span>
									</div>
								</th>
								<th>
									<div class="tooltip-container">
										VWAP
										<span class="tooltip-icon">?</span>
										<span class="tooltip-text">💰 VWAP（出来高加重平均価格）
• 「その日の出来高で重みづけした平均価格」。
• 1日の中で「機関投資家や短期筋の平均取得コスト」を示す。
• 価格がVWAPより上なら買い優勢、下なら売り優勢。
• 特にスイングでは、VWAPを支持線・抵抗線として使う。</span>
									</div>
								</th>
								<th>
									<div class="tooltip-container">
										BBバンド幅
										<span class="tooltip-icon">?</span>
										<span class="tooltip-text">📊 BBバンド幅（ボリンジャーバンド幅）
• ボリンジャーバンドの±2σ間の拡がり具合。
• 値が大きいほどボラティリティ（値動き）が拡大、小さいほど収束中＝「次の大きな動き前」。
• バンド幅が狭→急拡大した瞬間にバンドウォーク発生。
• あなたのHTMLにもあるように「バンドウォーク検出銘柄」欄はこの指標を使って抽出されています。</span>
									</div>
								</th>
								<th>利食い目標</th>
								<th>損切りライン</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($trinity_results as $row): ?>
								<tr>
									<td>
										<a href="https://kabutan.jp/stock/chart?code=<?php echo htmlspecialchars($row['code']); ?>" target="_blank">
											<?php echo htmlspecialchars($row['company_name']); ?>
										</a>
										<a href="comp.php?code=<?php echo htmlspecialchars($row['code']); ?>" target="_blank" title="詳細を見る">
											<i class="fa-solid fa-circle-info detail-icon"></i>
										</a>
									</td>
									<td><?php echo htmlspecialchars($row['code']); ?></td>
									<td><?php echo format_price($row['price']); ?></td>
									<td class="<?php echo get_score_class($row['score']); ?>"><?php echo (int)$row['score']; ?></td>
									<td><?php echo (int)$row['rsi_9']; ?></td>
									<td><?php echo (int)$row['vwap']; ?></td>
									<td><?php echo (int)$row['bb_width']; ?></td>
									<td style="color: #27ae60; font-weight: bold;"><?php echo format_percentage($row['price'], $row['profit_target']); ?></td>
									<td style="color: #e74c3c; font-weight: bold;"><?php echo format_percentage($row['price'], $row['stop_loss']); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php else: ?>
				<div class="section">
					<p style="text-align: center; color: #999;">この日付の分析結果がありません。</p>
				</div>
			<?php endif; ?>


		</div>
	</div>

	<div class="footer">
		<p>© 2025 スイングトレード銘柄分析システム | 最終更新: <?php echo htmlspecialchars($today); ?></p>
	</div>

	<script>
		function filterBandwalk(filter, buttonElement) {
			// ボタンのアクティブ状態を更新
			// バンドウォークテーブルの親要素から最も近いボタンを取得
			const bandwalkSection = buttonElement.closest('.section');
			if (bandwalkSection) {
				const buttons = bandwalkSection.querySelectorAll('.filter-btn');
				buttons.forEach(btn => btn.classList.remove('active'));
				if (buttonElement) {
					buttonElement.classList.add('active');
				}
			}

			// テーブルの行をフィルタリング
			const rows = document.querySelectorAll('.bandwalk-row');
			rows.forEach(row => {
				if (filter === 'all') {
					row.classList.remove('hidden');
				} else if (filter === 'active') {
					if (row.dataset.bandwalk === 'active') {
						row.classList.remove('hidden');
					} else {
						row.classList.add('hidden');
					}
				}
			});
		}

		function filterExpansion(filter, buttonElement) {
			// ボタンのアクティブ状態を更新
			// エクスパンションテーブルの親要素から最も近いボタンを取得
			const expansionSection = buttonElement.closest('.section');
			if (expansionSection) {
				const buttons = expansionSection.querySelectorAll('.filter-btn');
				buttons.forEach(btn => btn.classList.remove('active'));
				if (buttonElement) {
					buttonElement.classList.add('active');
				}
			}

			// テーブルの行をフィルタリング
			const rows = document.querySelectorAll('.expansion-row');
			rows.forEach(row => {
				if (filter === 'all') {
					row.classList.remove('hidden');
				} else if (filter === 'active') {
					if (row.dataset.expansion === 'active') {
						row.classList.remove('hidden');
					} else {
						row.classList.add('hidden');
					}
				}
			});
		}

		// ページ読み込み時に「発生中のみ」でフィルタリング
		document.addEventListener('DOMContentLoaded', function() {
			// バンドウォークのフィルタリング
			const bandwalkRows = document.querySelectorAll('.bandwalk-row');
			bandwalkRows.forEach(row => {
				if (row.dataset.bandwalk !== 'active') {
					row.classList.add('hidden');
				}
			});

			// エクスパンションのフィルタリング
			const expansionRows = document.querySelectorAll('.expansion-row');
			expansionRows.forEach(row => {
				if (row.dataset.expansion !== 'active') {
					row.classList.add('hidden');
				}
			});
		});
	</script>
</body>
</html>
