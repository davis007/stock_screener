# Stock Screener - スイングトレード銘柄分析システム

日本の株式市場の値上がり率ランキングから、スイングトレード向けの銘柄を自動分析するシステムです。

## 📋 概要

### 機能
- **自動ランキング取得**: kabutan.jpから毎日最新の値上がり率ランキング（30銘柄）を取得
- **テクニカル分析**: RSI、VWAP、ボリンジャーバンドを使用した三位一体モデル評価
- **バンドウォーク検出**: ボリンジャーバンド幅の急拡大を検出
- **データベース保存**: SQLiteで分析結果を永続保存
- **Web表示**: PHPで動的にレポートを表示

### 対応環境
- Python 3.7以上
- PHP 7.0以上
- 共用レンタルサーバー対応（Playwright不要）

## 🚀 セットアップ

### 1. 必要なPythonパッケージをインストール

```bash
pip install yfinance pandas pandas-ta beautifulsoup4 holidays
```

**パッケージの説明:**
- `yfinance` - Yahoo Financeから株価データを取得
- `pandas` - データ分析・操作
- `pandas-ta` - テクニカル分析指標の計算
- `beautifulsoup4` - HTMLパース（kabutan.jpからランキング取得）
- `holidays` - 日本の祝日判定

### 2. PHPサーバーを起動

```bash
php -S localhost:8000
```

### 3. ブラウザでアクセス

```
http://localhost:8000
```

## 📊 ファイル構成

```
stock_screener/
├── swing_analysis.py      # メイン分析スクリプト（毎日実行）
├── db_manager.py          # SQLiteデータベース管理
├── index.php              # 分析履歴一覧ページ
├── report.php             # 日別分析結果ページ
├── stock_analysis.db      # SQLiteデータベース
├── README.md              # このファイル
└── CRON_SETUP_GUIDE.md    # Cron設定ガイド
```

## 🔄 自動実行設定（Cron）

### 概要
毎営業日（月〜金）の16時に自動実行するようにCronを設定できます。

### 前提条件
- **日本の株式市場営業日のみ実行** - スクリプト内で自動判定
- **土日は自動的にスキップ** - Cronは毎日実行しても、スクリプトが判定
- **祝日は自動的にスキップ** - `holidays`ライブラリで全国の祝日を判定
- **年末年始（12月31日〜1月3日）は自動的にスキップ**

### 動作フロー
```
Cron実行（毎日16時）
    ↓
is_market_open_day()で営業日判定
    ↓
祝日？ → YES → 終了（何もしない）
    ↓ NO
土日？ → YES → 終了（何もしない）
    ↓ NO
年末年始？ → YES → 終了（何もしない）
    ↓ NO
分析実行 → kabutan.jpからランキング取得 → テクニカル分析 → DB保存
```

### Cron設定方法

#### 1. Cronタブを編集
```bash
crontab -e
```

#### 2. 以下の行を追加

**基本的な設定:**
```cron
0 16 * * 1-5 /usr/bin/python3 /path/to/stock_screener/swing_analysis.py >> /path/to/logs/swing_analysis.log 2>&1
```

**説明:**
- `0 16` - 毎日16時00分に実行
- `* * 1-5` - 月〜金（1=月曜日、5=金曜日）
- `/usr/bin/python3` - Pythonの実行ファイルパス
- `/path/to/stock_screener/swing_analysis.py` - スクリプトの絶対パス
- `>> /path/to/logs/swing_analysis.log 2>&1` - ログ出力

#### 3. パスの確認

**Pythonのパスを確認:**
```bash
which python3
```

**スクリプトの絶対パスを確認:**
```bash
pwd
```

#### 4. 実際の設定例

共用レンタルサーバーの場合:
```cron
0 16 * * 1-5 /usr/bin/python3 /home/username/stock_screener/swing_analysis.py >> /home/username/logs/swing_analysis.log 2>&1
```

ローカル開発環境の場合:
```cron
0 16 * * 1-5 /usr/local/bin/python3 /Volumes/ARSTH-2TB/dev/stock_screener/swing_analysis.py >> /Volumes/ARSTH-2TB/dev/stock_screener/logs/swing_analysis.log 2>&1
```

#### 5. ログディレクトリの作成

```bash
mkdir -p /path/to/logs
chmod 755 /path/to/logs
```

### Cron実行の確認

#### ログの確認
```bash
tail -f /path/to/logs/swing_analysis.log
```

#### 手動テスト実行
```bash
python3 /path/to/stock_screener/swing_analysis.py
```

## 📈 テクニカル指標の説明

### RSI_9（相対力指数・9期間）
- 直近9本のローソク足の値動きの強弱を数値化
- 0〜100で表示、70以上は買われすぎ、30以下は売られすぎ
- 短期トレードでの反転タイミング検出に強い

### VWAP（出来高加重平均価格）
- その日の出来高で重みづけした平均価格
- 機関投資家や短期筋の平均取得コストを示す
- スイングでは支持線・抵抗線として使用

### BBバンド幅（ボリンジャーバンド幅）
- ±2σ間の拡がり具合
- 値が大きいほどボラティリティが拡大
- バンドウォーク検出の基準指標

## 🔍 トラブルシューティング

### Cronが実行されない場合

1. **Cronサービスが起動しているか確認:**
   ```bash
   # macOS
   sudo launchctl list | grep cron

   # Linux
   sudo systemctl status cron
   ```

2. **パスが正しいか確認:**
   ```bash
   ls -la /path/to/stock_screener/swing_analysis.py
   ```

3. **Pythonパッケージがインストールされているか確認:**
   ```bash
   python3 -c "import yfinance; import pandas; import pandas_ta; import bs4"
   ```

### エラーが出ている場合

ログファイルを確認:
```bash
cat /path/to/logs/swing_analysis.log
```

## 📝 詳細設定

詳細なCron設定方法については、`CRON_SETUP_GUIDE.md` を参照してください。

## 📄 ライセンス

このプロジェクトはMITライセンスの下で公開されています。

## 🤝 貢献

バグ報告や機能提案は、Issuesで受け付けています。

