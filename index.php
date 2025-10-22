<?php
/**
 * スイングトレード銘柄分析 - 分析履歴一覧ページ
 * DBから年月日ごとの分析日付を取得して表示
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

// 全ての分析日付を取得（降順）
$stmt = $pdo->query('SELECT DISTINCT analysis_date FROM analysis_results ORDER BY analysis_date DESC');
$dates = $stmt->fetchAll(PDO::FETCH_COLUMN);

// 年月ごとにグループ化
$date_groups = [];
foreach ($dates as $date_str) {
	list($year, $month, $day) = explode('-', $date_str);
	$year_key = $year;
	$month_key = $year . '年' . (int)$month . '月';
	
	if (!isset($date_groups[$year_key])) {
		$date_groups[$year_key] = [];
	}
	if (!isset($date_groups[$year_key][$month_key])) {
		$date_groups[$year_key][$month_key] = [];
	}
	
	$date_groups[$year_key][$month_key][] = [
		'day' => (int)$day . '日',
		'date_str' => $date_str
	];
}

// 月ごとに逆順ソート
foreach ($date_groups as &$year_data) {
	krsort($year_data);
	foreach ($year_data as &$month_data) {
		usort($month_data, function($a, $b) {
			return strcmp($b['date_str'], $a['date_str']);
		});
	}
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>スイングトレード銘柄分析 - 分析履歴</title>
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
			padding: 20px;
		}
		.container {
			max-width: 1200px;
			margin: 0 auto;
		}
		.header {
			background: white;
			padding: 30px;
			border-radius: 10px;
			margin-bottom: 30px;
			box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
		}
		.header h1 {
			color: #333;
			margin-bottom: 10px;
		}
		.header p {
			color: #666;
			font-size: 14px;
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
		.section h3 {
			color: #555;
			margin-top: 20px;
			margin-bottom: 15px;
			font-size: 16px;
		}
		.date-list {
			list-style: none;
			padding-left: 20px;
		}
		.date-list li {
			margin-bottom: 10px;
		}
		.date-list a {
			color: #667eea;
			text-decoration: none;
			font-weight: 500;
			padding: 8px 12px;
			border-radius: 5px;
			transition: all 0.3s ease;
		}
		.date-list a:hover {
			background: #f0f0f0;
			text-decoration: underline;
		}
		.count {
			color: #999;
			font-size: 12px;
			margin-left: 10px;
		}
		.footer {
			background: white;
			padding: 20px 30px;
			text-align: center;
			color: #666;
			font-size: 12px;
			border-radius: 10px;
			box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
		}
	</style>
</head>
<body>
	<div class="container">
		<div class="header">
			<h1>📅 スイングトレード銘柄分析 - 分析履歴</h1>
			<p>過去の分析結果を年月日ごとに表示しています</p>
		</div>

		<?php foreach (array_keys($date_groups) as $year): ?>
			<div class="section">
				<h2><?php echo htmlspecialchars($year); ?></h2>
				<?php foreach ($date_groups[$year] as $month => $days): ?>
					<h3><?php echo htmlspecialchars($month); ?></h3>
					<ul class="date-list">
						<?php foreach ($days as $item): ?>
							<li>
								<a href="report.php?date=<?php echo urlencode($item['date_str']); ?>">
									<?php echo htmlspecialchars($item['day']); ?>
								</a>
								<span class="count">分析結果を表示</span>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endforeach; ?>
			</div>
		<?php endforeach; ?>

		<div class="footer">
			<p>© 2025 スイングトレード銘柄分析システム</p>
		</div>
	</div>
</body>
</html>

