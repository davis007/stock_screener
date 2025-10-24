<?php
/**
 * 個別分析ページ
 * 証券コードを入力するとバンドウォーク分析、エクスパンション分析、三位一体分析を実行
 */

// エラーレポート設定
error_reporting(E_ALL);
ini_set('display_errors', 1);

// 入力された証券コードを取得
$stock_code = isset($_POST['stock_code']) ? trim($_POST['stock_code']) : '';
$analysis_results = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($stock_code)) {
    // 証券コードのフォーマットを統一（.Tがついていない場合は追加）
    if (strpos($stock_code, '.T') === false) {
        $stock_code = $stock_code . '.T';
    }

    // 4桁の数字部分を抽出（例: 7203.T → 7203）
    $code_only = preg_replace('/\.T$/', '', $stock_code);

    // Pythonスクリプトを実行して分析結果を取得
    $command = escapeshellcmd("python3 swing_analysis.py --individual " . escapeshellarg($code_only));
    $output = shell_exec($command . ' 2>&1');

    // 分析結果を解析
    $analysis_results = parse_analysis_results($output, $code_only);

    // デバッグ用：出力内容を確認
    error_log("Pythonスクリプト出力: " . $output);
    error_log("解析結果: " . print_r($analysis_results, true));
}

/**
 * Pythonスクリプトの出力から分析結果を解析
 */
function parse_analysis_results($output, $code) {
    $results = [
        'code' => $code,
        'trinity' => null,
        'bandwalk' => null,
        'expansion' => null,
        'error' => null
    ];

    if (empty($output)) {
        $results['error'] = '分析スクリプトの実行に失敗しました';
        return $results;
    }

    // 各行を配列に分割して解析
    $lines = explode("\n", $output);

    // 三位一体分析結果の解析
    $trinity_data = [];
    $in_trinity = false;

    // バンドウォーク分析結果の解析
    $bandwalk_data = [];
    $in_bandwalk = false;

    // エクスパンション分析結果の解析
    $expansion_data = [];
    $in_expansion = false;

    foreach ($lines as $line) {
        $line = trim($line);

        // 三位一体分析の開始
        if (strpos($line, '三位一体モデル評価結果') !== false) {
            $in_trinity = true;
            $in_bandwalk = false;
            $in_expansion = false;
            continue;
        }

        // バンドウォーク分析の開始
        if (strpos($line, 'バンドウォーク検出銘柄') !== false) {
            $in_trinity = false;
            $in_bandwalk = true;
            $in_expansion = false;
            continue;
        }

        // エクスパンション分析の開始
        if (strpos($line, 'エクスパンション検出銘柄') !== false) {
            $in_trinity = false;
            $in_bandwalk = false;
            $in_expansion = true;
            continue;
        }

        // 三位一体分析のデータ抽出
        if ($in_trinity) {
            if (preg_match('/現在価格: (\d+)円/', $line, $matches)) {
                $trinity_data['price'] = floatval($matches[1]);
            }
            if (preg_match('/スコア: (\d+)点/', $line, $matches)) {
                $trinity_data['score'] = intval($matches[1]);
                $trinity_data['status'] = get_trinity_status(intval($matches[1]));
            }
            if (preg_match('/RSI_9: ([\d.]+)/', $line, $matches)) {
                $trinity_data['rsi_9'] = floatval($matches[1]);
            }
            if (preg_match('/VWAP: (\d+)円/', $line, $matches)) {
                $trinity_data['vwap'] = floatval($matches[1]);
            }
            if (preg_match('/BBバンド幅: ([\d.]+)/', $line, $matches)) {
                $trinity_data['bb_width'] = floatval($matches[1]);
            }
            if (preg_match('/利食い目標: (\d+)円/', $line, $matches)) {
                $trinity_data['profit_target'] = floatval($matches[1]);
            }
            if (preg_match('/損切りライン: (\d+)円/', $line, $matches)) {
                $trinity_data['stop_loss'] = floatval($matches[1]);
            }
        }

        // バンドウォーク分析のデータ抽出
        if ($in_bandwalk) {
            if (preg_match('/現在価格: (\d+)円/', $line, $matches)) {
                $bandwalk_data['price'] = floatval($matches[1]);
            }
            if (preg_match('/バンドウォーク: (⭕ 発生中|❌ なし)/', $line, $matches)) {
                $bandwalk_data['detected'] = $matches[1] === '⭕ 発生中';
                $bandwalk_data['status'] = $matches[1] === '⭕ 発生中' ? '発生中' : 'なし';
            }
            if (preg_match('/BBバンド幅: ([\d.]+)/', $line, $matches)) {
                $bandwalk_data['bb_width'] = floatval($matches[1]);
            }
            if (preg_match('/利食い目標: (\d+)円/', $line, $matches)) {
                $bandwalk_data['profit_target'] = floatval($matches[1]);
            }
            if (preg_match('/損切りライン: (\d+)円/', $line, $matches)) {
                $bandwalk_data['stop_loss'] = floatval($matches[1]);
            }
        }

        // エクスパンション分析のデータ抽出
        if ($in_expansion) {
            if (preg_match('/現在価格: (\d+)円/', $line, $matches)) {
                $expansion_data['price'] = floatval($matches[1]);
            }
            if (preg_match('/エクスパンション: (⭕ 発生中|❌ なし)/', $line, $matches)) {
                $expansion_data['detected'] = $matches[1] === '⭕ 発生中';
                $expansion_data['status'] = $matches[1] === '⭕ 発生中' ? '発生中' : 'なし';
            }
            if (preg_match('/BBバンド幅: ([\d.]+)/', $line, $matches)) {
                $expansion_data['bb_width'] = floatval($matches[1]);
            }
            if (preg_match('/拡大率: ([\d.]+)%/', $line, $matches)) {
                $expansion_data['expansion_rate'] = floatval($matches[1]);
            }
            if (preg_match('/利食い目標: (\d+)円/', $line, $matches)) {
                $expansion_data['profit_target'] = floatval($matches[1]);
            }
            if (preg_match('/損切りライン: (\d+)円/', $line, $matches)) {
                $expansion_data['stop_loss'] = floatval($matches[1]);
            }
        }

        // デバッグ用：各分析データの状態を確認
        if ($in_expansion && !empty($expansion_data)) {
            error_log("エクスパンションデータ: " . print_r($expansion_data, true));
        }
    }

    // 結果を設定
    if (!empty($trinity_data)) {
        $results['trinity'] = $trinity_data;
    }
    if (!empty($bandwalk_data)) {
        $results['bandwalk'] = $bandwalk_data;
    }
    if (!empty($expansion_data)) {
        $results['expansion'] = $expansion_data;
    }

    // エラーチェック
    if (strpos($output, 'Error') !== false || strpos($output, 'エラー') !== false) {
        $results['error'] = '銘柄データの取得に失敗しました。正しい証券コードか確認してください。';
    }

    return $results;
}

/**
 * 三位一体分析のスコアから状態を判定
 */
function get_trinity_status($score) {
    if ($score >= 70) return '高評価';
    if ($score >= 50) return '中評価';
    return '低評価';
}

/**
 * 三位一体分析のスコアからCSSクラスを取得
 */
function get_trinity_status_class($score) {
    if ($score >= 70) return 'score-high';
    if ($score >= 50) return 'score-medium';
    return 'score-low';
}

/**
 * RSIの状態を判定
 */
function get_rsi_status($rsi) {
    if ($rsi >= 70) return '買われすぎ';
    if ($rsi <= 30) return '売られすぎ';
    return '適正';
}

/**
 * BBバンド幅の状態を判定
 */
function get_bb_width_status($bb_width) {
    if ($bb_width > 15) return '高ボラティリティ';
    if ($bb_width < 5) return '低ボラティリティ';
    return '適正';
}

/**
 * エクスパンション率の状態を判定
 */
function get_expansion_rate_status($rate) {
    if ($rate > 20) return '急拡大';
    if ($rate > 10) return '拡大中';
    return '安定';
}

/**
 * 価格をフォーマット
 */
function format_price($price) {
    return (int)round($price) . '円';
}

/**
 * 目標価格と現在価格からパーセンテージを計算
 */
function format_percentage($current_price, $target_price) {
    if (!$target_price) return '-';
    $percentage = (($target_price - $current_price) / $current_price) * 100;
    $price_str = format_price($target_price);
    return $price_str . ' (' . sprintf('%+d', (int)round($percentage)) . '%)';
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>個別分析 - スイングトレード銘柄分析</title>
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
            max-width: 800px;
            margin: 0 auto;
        }
        .header {
            background: white;
            padding: 30px;
            border-radius: 10px;
            margin-bottom: 30px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            text-align: center;
        }
        .header h1 {
            color: #333;
            margin-bottom: 10px;
        }
        .header p {
            color: #666;
            font-size: 14px;
        }
        .header a {
            color: #667eea;
            text-decoration: none;
            margin: 0 10px;
        }
        .header a:hover {
            text-decoration: underline;
        }
        .form-section {
            background: white;
            padding: 30px;
            border-radius: 10px;
            margin-bottom: 30px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        .form-section h2 {
            color: #333;
            margin-bottom: 20px;
            border-bottom: 3px solid #667eea;
            padding-bottom: 10px;
        }
        .input-group {
            display: flex;
            gap: 10px;
            margin-bottom: 15px;
        }
        .input-group input {
            flex: 1;
            padding: 12px 15px;
            border: 2px solid #ddd;
            border-radius: 5px;
            font-size: 16px;
        }
        .input-group input:focus {
            outline: none;
            border-color: #667eea;
        }
        .btn {
            padding: 12px 30px;
            background: #667eea;
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.3s ease;
        }
        .btn:hover {
            background: #764ba2;
        }
        .results-section {
            background: white;
            padding: 30px;
            border-radius: 10px;
            margin-bottom: 30px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        .results-section h2 {
            color: #333;
            margin-bottom: 20px;
            border-bottom: 3px solid #667eea;
            padding-bottom: 10px;
        }
        .analysis-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        .analysis-card {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            border-left: 4px solid #667eea;
        }
        .analysis-card h3 {
            color: #333;
            margin-bottom: 10px;
            font-size: 16px;
        }
        .analysis-card p {
            color: #666;
            font-size: 14px;
            margin-bottom: 5px;
        }
        .status {
            font-weight: bold;
            margin-top: 10px;
            padding: 5px 10px;
            border-radius: 20px;
            display: inline-block;
            font-size: 12px;
        }
        .status-high {
            background: #27ae60;
            color: white;
        }
        .status-medium {
            background: #f39c12;
            color: white;
        }
        .status-low {
            background: #e74c3c;
            color: white;
        }
        .status-yes {
            background: #27ae60;
            color: white;
        }
        .status-no {
            background: #95a5a6;
            color: white;
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
        .error-message {
            background: #ffe6e6;
            color: #e74c3c;
            padding: 15px;
            border-radius: 5px;
            border-left: 4px solid #e74c3c;
            margin-top: 20px;
        }
        .info-message {
            background: #e6f3ff;
            color: #3498db;
            padding: 15px;
            border-radius: 5px;
            border-left: 4px solid #3498db;
            margin-top: 20px;
        }
        .analysis-tables {
            display: flex;
            flex-direction: column;
            gap: 20px;
            margin-top: 20px;
        }
        .analysis-table-section {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            border-left: 4px solid #667eea;
        }
        .analysis-table-section h3 {
            color: #333;
            margin-bottom: 15px;
            font-size: 16px;
            text-align: center;
        }
        .analysis-table {
            width: 100%;
            border-collapse: collapse;
        }
        .analysis-table th {
            text-align: left;
            padding: 8px 12px;
            background: #e9ecef;
            font-weight: 600;
            color: #333;
            border-bottom: 1px solid #dee2e6;
            width: 40%;
        }
        .analysis-table td {
            padding: 8px 12px;
            border-bottom: 1px solid #dee2e6;
            color: #555;
        }
        .analysis-table tr:last-child th,
        .analysis-table tr:last-child td {
            border-bottom: none;
        }
        .status-indicator {
            color: #6c757d;
            font-size: 12px;
            margin-left: 5px;
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
            <h1>🔍 個別銘柄分析</h1>
            <p>証券コードを入力して、バンドウォーク分析・エクスパンション分析・三位一体分析を実行</p>
            <p style="margin-top: 10px;">
                <a href="index.php">📅 分析履歴</a> |
                <a href="report.php">📊 最新分析結果</a>
            </p>
        </div>

        <div class="form-section">
            <h2>銘柄コード入力</h2>
            <form method="POST" action="">
                <div class="input-group">
                    <input type="text" name="stock_code" placeholder="証券コードを入力（例: 7203 または 7203.T）"
                           value="<?php echo htmlspecialchars(isset($_POST['stock_code']) ? $_POST['stock_code'] : ''); ?>"
                           required>
                    <button type="submit" class="btn">分析実行</button>
                </div>
                <p style="color: #666; font-size: 14px; margin-top: 10px;">
                    ※ 4桁の証券コードを入力してください（例: 7203, 9984, 6758など）
                </p>
            </form>
        </div>

        <?php if ($_SERVER['REQUEST_METHOD'] === 'POST'): ?>
            <div class="results-section">
                <h2>分析結果: <?php echo htmlspecialchars($stock_code); ?></h2>

                <?php if (isset($analysis_results['error'])): ?>
                    <div class="error-message">
                        <strong>エラー:</strong> <?php echo htmlspecialchars($analysis_results['error']); ?>
                    </div>
                <?php elseif (empty($analysis_results['trinity']) && empty($analysis_results['bandwalk']) && empty($analysis_results['expansion'])): ?>
                    <div class="info-message">
                        分析結果が見つかりませんでした。正しい証券コードか確認してください。
                    </div>
                <?php else: ?>
                    <div class="analysis-tables">
                        <!-- 三位一体分析の詳細表示 -->
                        <?php if ($analysis_results['trinity']): ?>
                            <div class="analysis-table-section">
                                <h3>📊 三位一体分析</h3>
                                <table class="analysis-table">
                                    <tr>
                                        <th>現在価格</th>
                                        <td><?php echo format_price($analysis_results['trinity']['price']); ?></td>
                                    </tr>
                                    <tr>
                                        <th>スコア</th>
                                        <td class="<?php echo get_trinity_status_class($analysis_results['trinity']['score']); ?>">
                                            <?php echo $analysis_results['trinity']['score']; ?>点 (<?php echo $analysis_results['trinity']['status']; ?>)
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>RSI_9</th>
                                        <td>
                                            <?php echo $analysis_results['trinity']['rsi_9']; ?>
                                            <span class="status-indicator">(<?php echo get_rsi_status($analysis_results['trinity']['rsi_9']); ?>)</span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>VWAP</th>
                                        <td>
                                            <?php echo format_price($analysis_results['trinity']['vwap']); ?>
                                            <span class="status-indicator">(<?php echo $analysis_results['trinity']['price'] > $analysis_results['trinity']['vwap'] ? '上昇傾向' : '下降傾向'; ?>)</span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>BBバンド幅</th>
                                        <td>
                                            <?php echo $analysis_results['trinity']['bb_width']; ?>
                                            <span class="status-indicator">(<?php echo get_bb_width_status($analysis_results['trinity']['bb_width']); ?>)</span>
                                        </td>
                                    </tr>
                                    <?php if ($analysis_results['trinity']['profit_target']): ?>
                                    <tr>
                                        <th>利食い目標</th>
                                        <td style="color: #27ae60; font-weight: bold;">
                                            <?php echo format_percentage($analysis_results['trinity']['price'], $analysis_results['trinity']['profit_target']); ?>
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                    <?php if ($analysis_results['trinity']['stop_loss']): ?>
                                    <tr>
                                        <th>損切りライン</th>
                                        <td style="color: #e74c3c; font-weight: bold;">
                                            <?php echo format_percentage($analysis_results['trinity']['price'], $analysis_results['trinity']['stop_loss']); ?>
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                </table>
                            </div>
                        <?php endif; ?>

                        <!-- バンドウォーク分析の詳細表示 -->
                        <?php if ($analysis_results['bandwalk']): ?>
                            <div class="analysis-table-section">
                                <h3>🚀 バンドウォーク分析</h3>
                                <table class="analysis-table">
                                    <tr>
                                        <th>現在価格</th>
                                        <td><?php echo format_price($analysis_results['bandwalk']['price']); ?></td>
                                    </tr>
                                    <tr>
                                        <th>バンドウォーク</th>
                                        <td>
                                            <span class="status <?php echo $analysis_results['bandwalk']['detected'] ? 'status-yes' : 'status-no'; ?>">
                                                <?php echo $analysis_results['bandwalk']['status']; ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>BBバンド幅</th>
                                        <td>
                                            <?php echo $analysis_results['bandwalk']['bb_width']; ?>
                                            <span class="status-indicator">(<?php echo get_bb_width_status($analysis_results['bandwalk']['bb_width']); ?>)</span>
                                        </td>
                                    </tr>
                                    <?php if ($analysis_results['bandwalk']['profit_target']): ?>
                                    <tr>
                                        <th>利食い目標</th>
                                        <td style="color: #27ae60; font-weight: bold;">
                                            <?php echo format_percentage($analysis_results['bandwalk']['price'], $analysis_results['bandwalk']['profit_target']); ?>
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                    <?php if ($analysis_results['bandwalk']['stop_loss']): ?>
                                    <tr>
                                        <th>損切りライン</th>
                                        <td style="color: #e74c3c; font-weight: bold;">
                                            <?php echo format_percentage($analysis_results['bandwalk']['price'], $analysis_results['bandwalk']['stop_loss']); ?>
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                </table>
                            </div>
                        <?php endif; ?>

                        <!-- エクスパンション分析の詳細表示 -->
                        <?php if ($analysis_results['expansion']): ?>
                            <div class="analysis-table-section">
                                <h3>📈 エクスパンション分析</h3>
                                <table class="analysis-table">
                                    <tr>
                                        <th>現在価格</th>
                                        <td><?php echo format_price($analysis_results['expansion']['price']); ?></td>
                                    </tr>
                                    <tr>
                                        <th>エクスパンション</th>
                                        <td>
                                            <span class="status <?php echo $analysis_results['expansion']['detected'] ? 'status-yes' : 'status-no'; ?>">
                                                <?php echo $analysis_results['expansion']['status']; ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>BBバンド幅</th>
                                        <td>
                                            <?php echo $analysis_results['expansion']['bb_width']; ?>
                                            <span class="status-indicator">(<?php echo get_bb_width_status($analysis_results['expansion']['bb_width']); ?>)</span>
                                        </td>
                                    </tr>
                                    <?php if (isset($analysis_results['expansion']['expansion_rate'])): ?>
                                    <tr>
                                        <th>拡大率</th>
                                        <td>
                                            <?php echo $analysis_results['expansion']['expansion_rate']; ?>%
                                            <span class="status-indicator">(<?php echo get_expansion_rate_status($analysis_results['expansion']['expansion_rate']); ?>)</span>
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                    <?php if ($analysis_results['expansion']['profit_target']): ?>
                                    <tr>
                                        <th>利食い目標</th>
                                        <td style="color: #27ae60; font-weight: bold;">
                                            <?php echo format_percentage($analysis_results['expansion']['price'], $analysis_results['expansion']['profit_target']); ?>
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                    <?php if ($analysis_results['expansion']['stop_loss']): ?>
                                    <tr>
                                        <th>損切りライン</th>
                                        <td style="color: #e74c3c; font-weight: bold;">
                                            <?php echo format_percentage($analysis_results['expansion']['price'], $analysis_results['expansion']['stop_loss']); ?>
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div style="margin-top: 30px; text-align: center;">
                        <a href="https://kabutan.jp/stock/chart?code=<?php echo htmlspecialchars($analysis_results['code']); ?>"
                           target="_blank" class="btn" style="text-decoration: none;">
                            📈 kabutanでチャートを確認
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="footer">
            <p>© 2025 スイングトレード銘柄分析システム</p>
        </div>
    </div>
</body>
</html>
