import yfinance as yf
import requests, re, json, sys, math
import pandas as pd
import numpy as np
from bs4 import BeautifulSoup
from datetime import datetime
import pandas_ta as ta

# --- helpers ---
def to_float_or_none(x):
	try:
		# Series → 末尾、ndarray/list/tuple → 末尾、その他 → そのまま
		if hasattr(x, "iloc"):
			v = x.iloc[-1]
		elif isinstance(x, (np.ndarray, list, tuple)):
			v = x[-1]
		else:
			v = x
		v = float(v)
		if math.isnan(v):
			return None
		return v
	except Exception:
		return None

# === RSI ===
def calc_rsi(close, period=14):
	change = close.diff()
	up = change.clip(lower=0)
	down = -change.clip(upper=0)
	ema_up = up.ewm(span=period, adjust=False).mean()
	ema_down = down.ewm(span=period, adjust=False).mean()
	rs = ema_up / ema_down
	return 100 - (100 / (1 + rs))

# === MACD ===
def calc_macd(close):
	short = close.ewm(span=12, adjust=False).mean()
	long = close.ewm(span=26, adjust=False).mean()
	macd = short - long
	signal = macd.ewm(span=9, adjust=False).mean()
	return macd, signal

# === ベータ値 ===
def calc_beta(stock_close, index_close):
	df = pd.concat([stock_close, index_close], axis=1).dropna()
	df.columns = ['stock', 'index']
	ret_s = df['stock'].pct_change().dropna()
	ret_i = df['index'].pct_change().dropna()
	if len(ret_s) < 10:
		return None
	cov = np.cov(ret_s, ret_i)[0][1]
	var = np.var(ret_i)
	return float(cov / var) if var else None

# === Kabutan決算発表予定日 ===
def fetch_earnings_date(code):
	url = f"https://kabutan.jp/stock/finance?code={code}"
	try:
		html = requests.get(url, headers={"User-Agent":"Mozilla/5.0"}, timeout=10).text
		soup = BeautifulSoup(html, "html.parser")

		# 方法1: id="kessan_happyoubi" のdiv要素を探す
		kessan_div = soup.find("div", id="kessan_happyoubi")
		if kessan_div:
			# time要素のdatetime属性から日付を取得
			time_elem = kessan_div.find("time")
			if time_elem and time_elem.has_attr("datetime"):
				datetime_str = time_elem["datetime"]
				# datetime形式: "2025-11-06T00:00:00+09:00" から日付部分を抽出
				m = re.search(r"(\d{4}-\d{2}-\d{2})", datetime_str)
				if m:
					return m.group(1)

		# 方法2: 「決算発表予定日」を含むdt要素を探す
		dt_elem = soup.find("dt", string=lambda s: s and "決算発表予定日" in s)
		if dt_elem:
			dd_elem = dt_elem.find_next_sibling("dd")
			if dd_elem:
				time_elem = dd_elem.find("time")
				if time_elem and time_elem.has_attr("datetime"):
					datetime_str = time_elem["datetime"]
					m = re.search(r"(\d{4}-\d{2}-\d{2})", datetime_str)
					if m:
						return m.group(1)

		# 方法3: 従来の方法（互換性のため）
		th = soup.find("th", string=lambda s: s and "決算発表日" in s)
		if th:
			td = th.find_next_sibling("td")
			if td:
				m = re.search(r"(\d{4})年(\d{1,2})月(\d{1,2})日", td.text)
				if m:
					y,mn,d = map(int, m.groups())
					return f"{y:04d}-{mn:02d}-{d:02d}"

		# 方法4: テキスト検索で探す
		dt = soup.find(string=re.compile("決算発表日"))
		if dt:
			dd = dt.find_parent().find_next_sibling()
			if dd:
				m = re.search(r"(\d{4})年(\d{1,2})月(\d{1,2})日", dd.text)
				if m:
					y,mn,d = map(int, m.groups())
					return f"{y:04d}-{mn:02d}-{d:02d}"
	except Exception as e:
		# デバッグ用: エラーを無視するが、必要に応じてログ出力可能
		pass
	return None

# === Kabutan信用倍率 ===
def fetch_credit_ratio(code):
	url = f"https://kabutan.jp/stock/?code={code}"
	headers = {"User-Agent": "Mozilla/5.0"}
	try:
		res = requests.get(url, headers=headers, timeout=10)
		res.raise_for_status()
		soup = BeautifulSoup(res.text, "html.parser")

		# 方法1: stockinfo_i3テーブル内の信用倍率を探す
		stockinfo_i3 = soup.find("div", id="stockinfo_i3")
		if stockinfo_i3:
			table = stockinfo_i3.find("table")
			if table:
				# thead内のth要素で「信用倍率」の列インデックスを探す
				thead = table.find("thead")
				if thead:
					th_elements = thead.find_all("th")
					credit_ratio_index = -1
					for i, th in enumerate(th_elements):
						if th.get_text(strip=True) == "信用倍率":
							credit_ratio_index = i
							break

					# tbody内の対応するtd要素を取得
					if credit_ratio_index >= 0:
						tbody = table.find("tbody")
						if tbody:
							tr = tbody.find("tr")
							if tr:
								td_elements = tr.find_all("td")
								if len(td_elements) > credit_ratio_index:
									td = td_elements[credit_ratio_index]
									text = td.get_text(strip=True)
									# 「－倍」などのデータなしを検出
									if "－" in text or text == "―" or not text or text.startswith("-"):
										return None
									val = re.sub(r"[^0-9.]", "", text)
									if val:
										return float(val)

		# 方法2: th要素で「信用倍率」を探す（従来の方法）
		th = soup.find("th", string=lambda s: s and "信用倍率" in s)
		if th:
			td = th.find_next_sibling("td")
			if not td:
				td = th.find_next("td")
			if td:
				text = td.get_text(strip=True)
				# 「－倍」などのデータなしを検出
				if "－" in text or text == "―" or not text or text.startswith("-"):
					return None
				val = re.sub(r"[^0-9.]", "", text)
				if val:
					return float(val)

		# 方法3: dt要素で探す
		dt = soup.find("dt", string=lambda s: s and "信用倍率" in s)
		if dt:
			dd = dt.find_next_sibling("dd")
			if dd:
				text = dd.get_text(strip=True)
				val = re.sub(r"[^0-9.]", "", text)
				if val:
					return float(val)

		# 方法4: div.fin_data_set 内を探す
		fin_data = soup.find_all("div", class_=re.compile(r"fin.*data"))
		for div in fin_data:
			if "信用倍率" in div.get_text():
				text = div.get_text()
				m = re.search(r"信用倍率[^0-9]*([0-9.]+)", text)
				if m:
					return float(m.group(1))

	except Exception as e:
		pass
	return None

# === Minkabuニュース ===
def fetch_minkabu_news(code, limit=10):
	url = f"https://assets.minkabu.jp/jsons/stock-jam/stocks/{code}/lump.json"
	try:
		data = requests.get(url, headers={"User-Agent":"Mozilla/5.0"}, timeout=10).json()
		news_days = data.get("stock", {}).get("news", [])
		items = [n for group in news_days for n in (group or [])]
		items.sort(key=lambda x: x.get("published_at", ""), reverse=True)
		if not items:
			return {"error": "ニュース取得失敗"}
		return {
			"news": [
				{
					"id": n.get("id"),
					"title": n.get("title"),
					"published_at": n.get("published_at"),
					"url": f"https://minkabu.jp/stock/{code}/news/{n.get('id')}"
				}
				for n in items[:limit]
			]
		}
	except Exception:
		return {"error": "ニュース取得失敗"}

# === CCIベースの翌日株価期待値スコアラー ===
def calc_cci_score(df, code):
	"""
	CCIベースの翌日株価期待値スコアを計算する
	"""
	try:
		# データの最終日（最新日付）を取得
		last_date = df.index[-1].strftime("%Y-%m-%d")

		# CCI計算 (length=14) - pandas-taを使わずに手動計算
		df_copy = df.copy()

		# CCIの計算式: (Typical Price - SMA(TP, n)) / (0.015 * Mean Deviation)
		# Typical Price = (High + Low + Close) / 3
		df_copy["TP"] = (df_copy["High"] + df_copy["Low"] + df_copy["Close"]) / 3
		df_copy["TP_SMA"] = df_copy["TP"].rolling(window=14).mean()
		df_copy["Mean_Deviation"] = df_copy["TP"].rolling(window=14).apply(lambda x: np.mean(np.abs(x - x.mean())))
		df_copy["CCI"] = (df_copy["TP"] - df_copy["TP_SMA"]) / (0.015 * df_copy["Mean_Deviation"])

		# 出来高の5日間単純移動平均
		df_copy["Volume_SMA5"] = df_copy["Volume"].rolling(5).mean()

		# 分析基準日（当日）とその前日（1営業日前）のデータ
		T = df_copy.iloc[-1]  # 当日
		T_minus_1 = df_copy.iloc[-2] if len(df_copy) >= 2 else df_copy.iloc[-1]  # 前日

		# 必要な値を取得
		cci_T = to_float_or_none(T["CCI"])
		cci_T_minus_1 = to_float_or_none(T_minus_1["CCI"])
		volume_T = to_float_or_none(T["Volume"])
		volume_sma5_T = to_float_or_none(T["Volume_SMA5"])
		open_T = to_float_or_none(T["Open"])
		close_T = to_float_or_none(T["Close"])
		high_T = to_float_or_none(T["High"])
		low_T = to_float_or_none(T["Low"])

		# スコア初期化
		total_score = 0

		# A. CCIシグナル（優先順位に基づき重複適用不可）
		if cci_T_minus_1 is not None and cci_T is not None:

			# 優先度1：トレンド継続（順張り・バンドウォーク）
			# +100以上で、さらに勢いが加速している（または高値圏を維持）
			if cci_T > 100:
				if cci_T > cci_T_minus_1:
					total_score = 5  # 買い（順張り・加速）
				else:
					total_score = 3  # 買い（順張り・維持）

			# -100以下で、さらに勢いが加速している（または安値圏を維持）
			elif cci_T < -100:
				if cci_T < cci_T_minus_1:
					total_score = -5 # 売り（順張り・加速）
				else:
					total_score = -3 # 売り（順張り・維持）

			# 優先度2：トレンド転換（反転シグナル）
			# 上記1の継続条件に該当しない場合のみ、反転をチェック
			# ±200ラインからの反転（最強の転換）
			elif cci_T_minus_1 < -200 and cci_T >= -200:
				total_score = 6  # 買い（反転）
			elif cci_T_minus_1 > 200 and cci_T <= 200:
				total_score = -6 # 売り（反転）

			# ±100ラインからの反転（通常の転換）
			elif cci_T_minus_1 < -100 and cci_T >= -100:
				total_score = 4  # 買い（反転）
			elif cci_T_minus_1 > 100 and cci_T <= 100:
				total_score = -4 # 売り（反転）

			# 優先度3：トレンド発生（ゼロライン・クロス）
			# 上記1, 2のいずれにも該当しない場合
			elif cci_T_minus_1 < 0 and cci_T >= 0:
				total_score = 3  # 買い（発生）
			elif cci_T_minus_1 > 0 and cci_T <= 0:
				total_score = -3 # 売り（発生）

		# B. ダイバージェンス（簡易実装）
		# 複雑なため、まずはスキップ

		# C. 補強要素：出来高（加点・減点）
		if volume_T is not None and volume_sma5_T is not None and volume_sma5_T > 0:
			volume_ratio = volume_T / volume_sma5_T
		else:
			volume_ratio = 1.0 # 出来高データがない場合は中立（1.0）として扱う

		# 判定1：CCIが加熱圏（±100超過）に「いる」場合
		if cci_T > 100: # 買い加熱圏
			if volume_ratio < 1.0: # 出来高が伴わない（ダマシ・天井警戒）
				total_score -= 4 # スコア減点 (例: +5点シグナルが +1点に)
			elif volume_ratio >= 1.5: # 出来高を伴う（強い順張り）
				total_score += 2 # スコア加点 (例: +5点シグナルが +7点に)
			# 1.0 <= ratio < 1.5 の場合は、ステップAのスコアのまま (加点・減点なし)

		elif cci_T < -100: # 売り加熱圏
			if volume_ratio < 1.0: # 出来高が伴わない（ダマシ・底値警戒）
				total_score += 4 # スコア加点（反転期待）(例: -5点シグナルが -1点に)
			elif volume_ratio >= 1.5: # 出来高を伴う（強い順張り）
				total_score -= 2 # スコア減点 (例: -5点シグナルが -7点に)
			# 1.0 <= ratio < 1.5 の場合は、ステップAのスコアのまま (加点・減点なし)

		# 判定2：CCIが「転換・発生シグナル」を出した場合（±100の内側）
		# (ステップAの優先度2, 3のスコアが対象)
		elif total_score in [6, 4, 3, -6, -4, -3]:
			if volume_ratio >= 1.5: # 転換時に出来高が多い
				if total_score > 0:
					total_score += 2 # 買い転換の信頼性強化
				elif total_score < 0:
					total_score -= 2 # 売り転換の信頼性強化

		# D. 補強要素：ローソク足（加点）
		if (open_T is not None and close_T is not None and
			high_T is not None and low_T is not None and
			high_T != low_T):  # ゼロ除算回避

			body = abs(close_T - open_T)
			range_val = high_T - low_T
			body_ratio = body / range_val

			if total_score > 0:  # 買いシグナル中
				# 大陽線または長い下ヒゲ
				if (close_T > open_T and body_ratio >= 0.7) or \
				   (min(open_T, close_T) - low_T) / range_val >= 0.5:
					total_score += 2
			elif total_score < 0:  # 売りシグナル中
				# 大陰線または長い上ヒゲ
				if (open_T > close_T and body_ratio >= 0.7) or \
				   (high_T - max(open_T, close_T)) / range_val >= 0.5:
					total_score -= 2

		# 最終判定
		if total_score >= 10:
			expectation = "非常に強い買い推奨"
		elif total_score >= 5:
			expectation = "買い推奨"
		elif total_score <= -10:
			expectation = "非常に強い売り推奨"
		elif total_score <= -5:
			expectation = "売り推奨"
		else:
			expectation = "中立（様子見）"

		return {
			"cci_score": total_score,
			"cci_expectation": expectation,
			"cci_value": cci_T,
			"cci_previous": cci_T_minus_1
		}

	except Exception as e:
		# エラー時はデフォルト値を返す
		return {
			"cci_score": 0,
			"cci_expectation": f"計算エラー: {str(e)}",
			"cci_value": None,
			"cci_previous": None
		}

# === MAIN ===
def main():
	if len(sys.argv) < 2:
		print(json.dumps({"error":"no code"}, ensure_ascii=False))
		return

	code = sys.argv[1].strip()
	symbol = f"{code}.T"

	# yfinanceのTickerオブジェクトから企業情報を取得
	ticker = yf.Ticker(symbol)
	info = ticker.info
	company_name = info.get("longName") or info.get("shortName") or code

	df = yf.download(symbol, period="90d", interval="1d", auto_adjust=False, progress=False)
	if df.empty:
		print(json.dumps({"error":"no data"}, ensure_ascii=False))
		return

	# データの最終日（最新日付）を取得
	last_date = df.index[-1].strftime("%Y-%m-%d")

	df["RSI"] = calc_rsi(df["Close"])
	df["RSI"] = pd.to_numeric(df["RSI"], errors="coerce")
	df["MA5"] = df["Close"].rolling(5).mean()
	df["MA25"] = df["Close"].rolling(25).mean()
	df["MACD"], df["Signal"] = calc_macd(df["Close"])

	last_row = df.iloc[-1]
	prev_row = df.iloc[-2] if len(df) >= 2 else df.iloc[-1]

	try:
		macd_value = last_row["MACD"]
		signal_value = last_row["Signal"]
		macd_prev = prev_row["MACD"]
		signal_prev = prev_row["Signal"]
		# 単一値（float）に確実に変換
		macd_value = macd_value.item() if hasattr(macd_value, "item") else float(macd_value)
		signal_value = signal_value.item() if hasattr(signal_value, "item") else float(signal_value)
		macd_prev = macd_prev.item() if hasattr(macd_prev, "item") else float(macd_prev)
		signal_prev = signal_prev.item() if hasattr(signal_prev, "item") else float(signal_prev)
	except Exception:
		macd_value = signal_value = macd_prev = signal_prev = None

	# RSI (robust scalar extraction)
	rsi_val_any = last_row["RSI"]
	try:
		# If it is a pandas Series/ndarray, take the last scalar
		if hasattr(rsi_val_any, "iloc"):
			rsi_scalar = rsi_val_any.iloc[-1]
		elif isinstance(rsi_val_any, (list, tuple, np.ndarray)):
			rsi_scalar = rsi_val_any[-1]
		else:
			rsi_scalar = rsi_val_any
		# Convert to float if possible
		rsi = round(float(rsi_scalar), 2)
	except Exception:
		rsi = None

	# MACD cross (already parsed to floats above)
	if macd_value is not None and signal_value is not None and macd_prev is not None and signal_prev is not None:
		macd_cross = "⭕️" if (macd_value > signal_value and macd_prev <= signal_prev) else "❌"
	else:
		macd_cross = "N/A"

	# Extract scalars safely
	last_close = to_float_or_none(last_row["Close"])
	prev_close = to_float_or_none(prev_row["Close"])
	last_ma25  = to_float_or_none(last_row["MA25"])
	prev_ma25  = to_float_or_none(prev_row["MA25"])
	last_vol   = to_float_or_none(last_row["Volume"])
	avg5_vol   = to_float_or_none(df["Volume"].rolling(5).mean().iloc[-1])

	# Volume change (%)
	if (last_vol is not None) and (avg5_vol is not None) and (avg5_vol != 0):
		vol_change = round((last_vol / avg5_vol - 1) * 100, 2)
	else:
		vol_change = None

	# 出来高情報の追加
	# 前日比出来高増減率
	prev_vol = to_float_or_none(prev_row["Volume"])
	if (last_vol is not None) and (prev_vol is not None) and (prev_vol != 0):
		volume_change_rate = round((last_vol / prev_vol - 1) * 100, 2)
	else:
		volume_change_rate = None

	# 陽線/陰線判定
	last_open = to_float_or_none(last_row["Open"])
	if (last_close is not None) and (last_open is not None):
		if last_close > last_open:
			candle_type = "陽線"
		elif last_close < last_open:
			candle_type = "陰線"
		else:
			candle_type = "寄引同値"
	else:
		candle_type = "不明"

	# 圧力評価
	if volume_change_rate is not None:
		if candle_type == "陽線":
			if volume_change_rate >= 50:
				pressure = "強"
			elif volume_change_rate >= 20:
				pressure = "中"
			else:
				pressure = "弱"
			pressure_comment = f"上昇圧力 {pressure}"
		elif candle_type == "陰線":
			if volume_change_rate >= 50:
				pressure = "強"
			elif volume_change_rate >= 20:
				pressure = "中"
			else:
				pressure = "弱"
			pressure_comment = f"下落圧力 {pressure}"
		else:
			pressure_comment = "圧力評価なし"
	else:
		pressure_comment = "データ不足"

	# トレンドパワー評価（移動平均線の向きと並び順）
	# 必要なデータを取得
	last_ma5 = to_float_or_none(last_row["MA5"])
	prev_ma5 = to_float_or_none(prev_row["MA5"])
	last_ma25 = to_float_or_none(last_row["MA25"])
	prev_ma25 = to_float_or_none(prev_row["MA25"])

	# トレンド評価ロジック
	if (last_ma5 is not None and prev_ma5 is not None and
		last_ma25 is not None and prev_ma25 is not None):

		# 移動平均線の向きを判定
		is_ma5_up = (last_ma5 > prev_ma5)
		is_ma25_up = (last_ma25 > prev_ma25)
		is_golden_cross = (last_ma5 > last_ma25)

		# トレンド評価
		if is_ma5_up and is_ma25_up and is_golden_cross:
			trend_power = "最強 (パーフェクトオーダー)"
		elif is_ma5_up and is_golden_cross:
			trend_power = "上昇トレンド (強)"
		elif (is_ma5_up != is_ma25_up) or (not is_ma5_up and not is_ma25_up):
			trend_power = "横ばい (レンジ)"
		elif not is_ma5_up and not is_golden_cross:
			trend_power = "下降トレンド (弱)"
		elif not is_ma5_up and not is_ma25_up and not is_golden_cross:
			trend_power = "最弱 (下降パーフェクトオーダー)"
		else:
			trend_power = "不明"
	else:
		trend_power = "不明"

	# ATR%によるボラティリティ計算
	try:
		# 14日間のATRを計算
		df["ATR"] = ta.atr(high=df["High"], low=df["Low"], close=df["Close"], length=14)

		# 最新のATR値と終値を取得
		latest_atr_value = to_float_or_none(df["ATR"].iloc[-1])
		latest_close_price = to_float_or_none(df["Close"].iloc[-1])

		# ATR%を計算
		if (latest_atr_value is not None) and (latest_close_price is not None) and (latest_close_price != 0):
			range_pct = round((latest_atr_value / latest_close_price) * 100, 2)
			range_yen = round(latest_atr_value, 2)
		else:
			range_pct = None
			range_yen = None
	except Exception as e:
		# ATR計算が失敗した場合は従来の方法で計算
		try:
			range_series = (df["High"] - df["Low"]).tail(5)
			range_yen_val = to_float_or_none(range_series.mean())
			if (range_yen_val is not None) and (last_close is not None) and (last_close != 0):
				range_yen = round(range_yen_val, 2)
				range_pct = round(range_yen / last_close * 100, 2)
			else:
				range_yen = None
				range_pct = None
		except Exception:
			range_yen = None
			range_pct = None

	index = yf.download("^N225", period="90d", interval="1d", auto_adjust=False, progress=False)
	beta = calc_beta(df["Close"], index["Close"]) if not index.empty else None

	# CCIスコア計算
	cci_score_data = calc_cci_score(df, code)

	out = {
		"symbol": code,
		"company_name": company_name,
		"last_date": last_date,
		"current_price": last_close,
		"RSI": rsi,
		"MACD_cross": macd_cross,
		"MA5_25_spread": trend_power,
		"volume_change_percent": vol_change,
		"volatility_5d": {"range_yen":range_yen, "range_percent":range_pct},
		"beta": (round(beta,3) if beta is not None else None),
		"earnings_date": fetch_earnings_date(code),
		"credit_ratio": fetch_credit_ratio(code),
		"news": fetch_minkabu_news(code),
		"volume_info": {
			"candle_type": candle_type,
			"volume_change_rate": volume_change_rate,
			"pressure_comment": pressure_comment
		},
		"cci_analysis": cci_score_data,
		"updated": datetime.now().strftime("%Y-%m-%d %H:%M:%S")
	}
	print(json.dumps(out, ensure_ascii=False))

if __name__ == "__main__":
	main()
