# Cron設定ガイド - 毎営業日16時実行

## 概要
スイングトレード銘柄分析スクリプト（`swing_analysis.py`）を、日本の株式市場営業日の毎日16時に自動実行するためのCron設定ガイドです。

## 前提条件
- 共用レンタルサーバー環境
- Playwrightなし（BeautifulSoupのみ使用）
- Python 3.7以上
- 必要なPythonパッケージがインストール済み

## 必要なPythonパッケージ
```bash
pip install yfinance pandas pandas-ta beautifulsoup4 holidays
```

**パッケージの説明:**
- `yfinance` - Yahoo Financeから株価データを取得
- `pandas` - データ分析・操作
- `pandas-ta` - テクニカル分析指標の計算
- `beautifulsoup4` - HTMLパース（kabutan.jpからランキング取得）
- `holidays` - 日本の祝日判定

## Cron設定方法

### 1. Cronタブを編集
```bash
crontab -e・🚀 バンドウォーク検出銘柄
・エクスパンション検出銘柄
・📊 三位一体モデル評価（スコア順）
```

### 2. 以下の行を追加

**営業日判定を含む完全な設定（推奨）:**
```cron
0 16 * * 1-5 /path/to/python /path/to/stock_screener/swing_analysis.py >> /path/to/logs/swing_analysis.log 2>&1
```

**説明:**
- `0 16` - 毎日16時00分に実行
- `* * 1-5` - 月〜金（1=月曜日、5=金曜日）
- `/path/to/python` - Pythonの実行ファイルパス
- `/path/to/stock_screener/swing_analysis.py` - スクリプトの絶対パス
- `>> /path/to/logs/swing_analysis.log 2>&1` - ログ出力

### 3. パスの確認

**Pythonのパスを確認:**
```bash
which python3
# または
which python
```

**スクリプトの絶対パスを確認:**
```bash
pwd  # 現在のディレクトリを確認
# /Volumes/ARSTH-2TB/dev/stock_screener の場合
```

### 4. 実際の設定例

共用レンタルサーバーの場合:
```cron
0 16 * * 1-5 /usr/bin/python3 /home/username/stock_screener/swing_analysis.py >> /home/username/logs/swing_analysis.log 2>&1
```

ローカル開発環境の場合:
```cron
0 16 * * 1-5 /usr/local/bin/python3 /Volumes/ARSTH-2TB/dev/stock_screener/swing_analysis.py >> /Volumes/ARSTH-2TB/dev/stock_screener/logs/swing_analysis.log 2>&1
```

## ログディレクトリの作成

```bash
mkdir -p /path/to/logs
chmod 755 /path/to/logs
```

## 祝日対応

スクリプト内の `is_market_open_day()` 関数が以下を自動判定します:

✅ **自動判定される休場日:**
- 土曜日・日曜日
- 日本の祝日（`holidays`ライブラリで自動判定）
- 年末年始（12月31日〜1月3日）

### 祝日判定の仕組み

`holidays`ライブラリを使用しているため、毎年自動的に最新の祝日情報が反映されます。

**判定例:**
```
2025-01-01 (水): ✗ 休場 - 元日
2025-01-13 (月): ✗ 休場 - 成人の日
2025-01-14 (火): ✓ 開場
2025-10-22 (水): ✓ 開場
2025-10-25 (土): ✗ 休場 - 土曜日
2025-10-26 (日): ✗ 休場 - 日曜日
2025-12-31 (水): ✗ 休場 - 年末
2026-01-01 (木): ✗ 休場 - 元日
```

⚠️ **注意:**
- `holidays`ライブラリは自動的に全国の祝日を判定します
- 手動での祝日リスト更新は不要です

## Cron実行の確認

### 1. Cronタブの確認
```bash
crontab -l
```

### 2. ログの確認
```bash
tail -f /path/to/logs/swing_analysis.log
```

### 3. 手動テスト実行
```bash
python3 /path/to/stock_screener/swing_analysis.py
```

## トラブルシューティング

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

4. **ログファイルの権限を確認:**
   ```bash
   ls -la /path/to/logs/
   ```

### エラーが出ている場合

ログファイルを確認:
```bash
cat /path/to/logs/swing_analysis.log
```

## 環境変数の設定（必要に応じて）

Cronで環境変数が必要な場合、Cronタブの先頭に追加:
```cron
PATH=/usr/local/bin:/usr/bin:/bin
SHELL=/bin/bash

0 16 * * 1-5 /usr/bin/python3 /path/to/stock_screener/swing_analysis.py >> /path/to/logs/swing_analysis.log 2>&1
```

## 祝日判定の自動更新

`holidays`ライブラリを使用しているため、祝日の手動更新は不要です。

ライブラリが自動的に最新の祝日情報を使用します。

## 参考資料

- [日本の祝日一覧](https://www.nta.go.jp/about/organization/ntc/kenkyu/ronsou/67onko/67-1.htm)
- [Cron式の書き方](https://ja.wikipedia.org/wiki/Cron)
- [Python公式ドキュメント](https://docs.python.org/ja/)

