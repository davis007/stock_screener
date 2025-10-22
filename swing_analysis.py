import yfinance as yf
import pandas as pd
import pandas_ta as ta
from datetime import date, datetime
import os
import traceback
from typing import Optional, List, Dict
from bs4 import BeautifulSoup
import urllib.request
import urllib.error
import holidays

def fetch_ranking_stocks() -> List[Dict[str, str]]:
	"""
	kabutan.jpの値上がり率ランキングから全銘柄を取得（最大30件）
	Playwrightを使わずにurllib + BeautifulSoupで実装
	ページネーションで複数ページから取得

	Returns:
		銘柄情報のリスト（コード、企業名を含む）
	"""
	try:
		stocks = []
		base_url = "https://kabutan.jp/warning/?mode=2_1"

		# User-Agentを設定（ブロック対策）
		headers = {
			'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
		}

		# ページ1と2から取得（最大30件）
		for page in [1, 2]:
			if page == 1:
				url = base_url
			else:
				url = f"{base_url}&page={page}"

			try:
				req = urllib.request.Request(url, headers=headers)
				with urllib.request.urlopen(req, timeout=10) as response:
					html = response.read().decode('utf-8')

				soup = BeautifulSoup(html, "html.parser")

				# 全てのテーブルを探して、銘柄ランキングテーブルを特定
				tables = soup.find_all('table')
				ranking_tbody = None

				for table in tables:
					tbody = table.find('tbody')
					if not tbody:
						continue

					rows = tbody.find_all('tr')
					if not rows:
						continue

					# 最初の行に銘柄コードリンクがあるかチェック
					first_row = rows[0]
					code_link = first_row.find('a', href=lambda x: x and '/stock/?code=' in x)
					if code_link:
						ranking_tbody = tbody
						break

				if not ranking_tbody:
					continue

				rows = ranking_tbody.find_all('tr')

				for row in rows:
					try:
						# 最初のtdから銘柄コードを取得
						code_link = row.find('a', href=lambda x: x and '/stock/?code=' in x)
						if not code_link:
							continue

						code = code_link.get_text(strip=True)

						# 次のthから企業名を取得
						name_th = row.find('th', scope='row')
						if not name_th:
							continue

						name = name_th.get_text(strip=True)

						# 重複チェック
						if not any(s['code'] == code for s in stocks):
							stocks.append({
								'code': code,
								'name': name
							})
					except (AttributeError, TypeError, IndexError):
						continue

				# 30件に達したら終了
				if len(stocks) >= 30:
					stocks = stocks[:30]
					break

			except urllib.error.URLError as e:
				print(f"ページ {page} アクセスエラー: {str(e)}")
				continue

		print(f"kabutan.jpから {len(stocks)} 件の銘柄を取得しました")
		return stocks

	except Exception as e:
		print(f"ランキング取得エラー: {str(e)}")
		return []

def is_market_open_day(target_date: date = None) -> bool:
	"""
	日本の株式市場が開いている営業日かどうかを判定

	土日、日本の祝日、年末年始を除外

	Args:
		target_date: 判定対象の日付（デフォルトは今日）

	Returns:
		営業日ならTrue、そうでなければFalse
	"""
	if target_date is None:
		target_date = date.today()

	# 土日は休場
	if target_date.weekday() >= 5:  # 5=土曜日, 6=日曜日
		return False

	# 日本の祝日を判定（holidaysライブラリを使用）
	jp_holidays = holidays.Japan()
	if target_date in jp_holidays:
		return False

	# 年末年始の休場（12月31日〜1月3日）
	if (target_date.month == 12 and target_date.day >= 31) or \
	   (target_date.month == 1 and target_date.day <= 3):
		return False

	return True

def analyze_swing_trinity(ticker_code: str) -> Optional[dict]:
	"""
	VWAP、RCI、ボリンジャーバンドを使用した三位一体モデルの分析

	Args:
		ticker_code: 銘柄コード（例: '6920'）

	Returns:
		採点結果の辞書、またはエラー時はNone
	"""
	try:
		# .Tを付加してyfinanceで取得
		ticker = f"{ticker_code}.T"
		df = yf.download(ticker, period="120d", progress=False, auto_adjust=True)

		# DataFrameのインデックスをリセット
		if isinstance(df.columns, pd.MultiIndex):
			df.columns = df.columns.get_level_values(0)

		# データ不足チェック
		if len(df) < 50:
			return None

		# テクニカル指標の計算
		df.ta.vwap(append=True)
		# RCIの代わりにRSIを使用（pandas-taではRCIが利用できない場合がある）
		df.ta.rsi(length=9, append=True)
		df.ta.bbands(length=20, std=2, append=True)

		# 最新データの取得
		latest = df.iloc[-1]
		prev_day = df.iloc[-2]
		three_days_ago = df.iloc[-4]

		score = 0

		# VWAP (30点)
		if latest['Close'] > latest['VWAP_D']:
			score += 20
		if latest['VWAP_D'] > three_days_ago['VWAP_D']:
			score += 10

		# RSI (40点) - RCIの代わりにRSIを使用
		rsi_latest = latest['RSI_9']
		rsi_prev = prev_day['RSI_9']

		if rsi_latest > rsi_prev:
			score += 20
		if rsi_latest < 70:  # 売られすぎゾーン（RSIは30以下が売られすぎ）
			score += 20
		if rsi_latest > 70:  # 買われすぎゾーン
			score -= 10

		# ボリンジャーバンド (30点)
		bb_middle = latest['BBM_20_2.0']
		bb_width = latest['BBB_20_2.0']
		bb_width_3d = three_days_ago['BBB_20_2.0']

		if latest['Close'] > bb_middle:
			score += 15
		if bb_width > bb_width_3d:
			score += 15

		# スコアを0-100の範囲に制限
		score = max(0, min(100, score))

		# 期待値の計算
		expected = calculate_expected_values(ticker_code)

		return {
			'code': ticker_code,
			'price': round(latest['Close'], 2),
			'score': int(score),
			'RSI_9': round(rsi_latest, 2),
			'VWAP': round(latest['VWAP_D'], 2),
			'BB_Width': round(bb_width, 2),
			'profit_target': expected['profit_target'] if expected else None,
			'stop_loss': expected['stop_loss'] if expected else None
		}

	except Exception as e:
		print(f"Error analyzing {ticker_code}: {str(e)}")
		return None

def calculate_expected_values(ticker_code: str) -> Optional[dict]:
	"""
	ATR（14日間）を使用した5日後の期待値を計算

	Args:
		ticker_code: 銘柄コード（例: '6920'）

	Returns:
		期待値の辞書、またはエラー時はNone
	"""
	try:
		# .Tを付加してyfinanceで取得
		ticker = f"{ticker_code}.T"
		df = yf.download(ticker, period="120d", progress=False, auto_adjust=True)

		# DataFrameのインデックスをリセット
		if isinstance(df.columns, pd.MultiIndex):
			df.columns = df.columns.get_level_values(0)

		# データ不足チェック
		if len(df) < 50:
			return None

		# ATRの計算
		df.ta.atr(length=14, append=True)

		# 最新データの取得
		latest = df.iloc[-1]
		current_price = latest['Close']
		atr_value = latest['ATRr_14']

		# 期待値の計算
		profit_target = round(current_price + (atr_value * 2.0), 2)  # 利食い目標
		stop_loss = round(current_price - (atr_value * 1.5), 2)      # 損切りライン

		return {
			'code': ticker_code,
			'current_price': round(current_price, 2),
			'atr': round(atr_value, 2),
			'profit_target': profit_target,
			'stop_loss': stop_loss
		}

	except Exception as e:
		print(f"Error calculating expected values for {ticker_code}: {str(e)}")
		return None

def check_bandwalk(ticker_code: str) -> Optional[dict]:
	"""
	ボリンジャーバンドのバンドウォーク検出

	Args:
		ticker_code: 銘柄コード（例: '6920'）

	Returns:
		判定結果の辞書、またはエラー時はNone
	"""
	try:
		# .Tを付加してyfinanceで取得
		ticker = f"{ticker_code}.T"
		df = yf.download(ticker, period="60d", progress=False, auto_adjust=True)

		# DataFrameのインデックスをリセット
		if isinstance(df.columns, pd.MultiIndex):
			df.columns = df.columns.get_level_values(0)

		# データ不足チェック
		if len(df) < 25:
			return None

		# ボリンジャーバンドの計算
		df.ta.bbands(length=20, std=2, append=True)

		# +1σラインの計算
		df['BB_Plus1Sigma'] = df['BBM_20_2.0'] + ((df['BBU_20_2.0'] - df['BBL_20_2.0']) / 4)

		# 最新データの取得
		latest = df.iloc[-1]
		prev_1 = df.iloc[-2]
		prev_2 = df.iloc[-3]
		prev_3 = df.iloc[-4]

		# 条件A: 過去3日間連続で終値が+1σを上回っている
		condition_a = (
			latest['Close'] > latest['BB_Plus1Sigma'] and
			prev_1['Close'] > prev_1['BB_Plus1Sigma'] and
			prev_2['Close'] > prev_2['BB_Plus1Sigma']
		)

		# 条件B: バンド幅が拡大傾向
		bb_width_3d = df.iloc[-4]['BBB_20_2.0']
		condition_b = latest['BBB_20_2.0'] > bb_width_3d

		# バンドウォーク判定
		is_bandwalk = condition_a and condition_b

		# 期待値の計算
		expected = calculate_expected_values(ticker_code)

		return {
			'code': ticker_code,
			'is_bandwalk': is_bandwalk,
			'price': round(latest['Close'], 2),
			'bb_width': round(latest['BBB_20_2.0'], 2),
			'profit_target': expected['profit_target'] if expected else None,
			'stop_loss': expected['stop_loss'] if expected else None
		}

	except Exception as e:
		print(f"Error checking bandwalk for {ticker_code}: {str(e)}")
		return None

def format_price(price: float) -> str:
	"""
	価格をフォーマット（小数点以下は省略、円記号付き）
	"""
	return f"{int(round(price))}円"

def format_percentage(current_price: float, target_price: float) -> str:
	"""
	目標価格と現在価格から、数字+%形式でフォーマット
	例: 3005円 -> 3125円 = +4%
	"""
	percentage = ((target_price - current_price) / current_price) * 100
	price_str = format_price(target_price)
	return f"{price_str} ({percentage:+.0f}%)"



def main():
	"""メイン処理"""
	try:
		from db_manager import DatabaseManager

		today = date.today()
		print(f"分析開始: {today}")
		print("-" * 50)

		# 株式市場が開催されているか判定
		if not is_market_open_day(today):
			print(f"本日 {today} は株式市場の休場日です。分析をスキップします。")
			return

		# Y!ファイナンスのランキングから銘柄を取得
		print("kabutan.jpのランキングを取得中...")
		stocks = fetch_ranking_stocks()

		if not stocks:
			print("エラー: 銘柄情報を取得できませんでした")
			return

		print(f"対象銘柄数: {len(stocks)}")
		print("-" * 50)

		# データベース初期化
		db = DatabaseManager()
		analysis_date = str(date.today())

		trinity_results = []
		bandwalk_results = []

		# 各銘柄を分析
		for stock in stocks:
			code = stock['code']
			company_name = stock['name']
			print(f"分析中: {code} ({company_name})")

			# 企業情報をDB保存
			db.upsert_company(code, company_name)

			# 三位一体モデルの分析
			trinity = analyze_swing_trinity(code)
			if trinity:
				trinity['company_name'] = company_name
				trinity_results.append(trinity)
				# DB保存
				db.save_analysis_result(analysis_date, trinity)

			# バンドウォーク検出
			bandwalk = check_bandwalk(code)
			if bandwalk:
				bandwalk['company_name'] = company_name
				bandwalk_results.append(bandwalk)
				# DB保存
				db.save_bandwalk_result(analysis_date, bandwalk)

		print("-" * 50)

		# DataFrameに変換
		trinity_df = pd.DataFrame(trinity_results)
		bandwalk_df = pd.DataFrame(bandwalk_results)

		# 三位一体モデルをスコアの降順でソート
		if len(trinity_df) > 0:
			trinity_df = trinity_df.sort_values('score', ascending=False).reset_index(drop=True)
			print("\n【三位一体モデル評価結果】")
			print(trinity_df[['company_name', 'code', 'price', 'score', 'RSI_9', 'VWAP', 'BB_Width']])

		# バンドウォーク検出結果をフィルタリング
		if len(bandwalk_df) > 0:
			bandwalk_true = bandwalk_df[bandwalk_df['is_bandwalk'] == True]
			print("\n【バンドウォーク検出銘柄】")
			if len(bandwalk_true) > 0:
				print(bandwalk_true[['company_name', 'code', 'price', 'is_bandwalk', 'bb_width']])
			else:
				print("バンドウォーク検出銘柄なし")

		print(f"✓ 分析データをデータベースに保存しました")
		print(f"✓ 分析日時: {analysis_date}")

	except Exception as e:
		print(f"エラーが発生しました: {str(e)}")
		traceback.print_exc()

if __name__ == "__main__":
	main()

