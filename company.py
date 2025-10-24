import yfinance as yf
import requests, re, json, sys, math
import pandas as pd
import numpy as np
from bs4 import BeautifulSoup
from datetime import datetime

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

# === Kabutan決算発表日 ===
def fetch_earnings_date(code):
	url = f"https://kabutan.jp/stock/finance?code={code}"
	try:
		html = requests.get(url, headers={"User-Agent":"Mozilla/5.0"}, timeout=10).text
		soup = BeautifulSoup(html, "html.parser")
		dt = soup.find(string=re.compile("決算発表日"))
		if dt:
			dd = dt.find_parent().find_next_sibling()
			if dd:
				m = re.search(r"(\d{4})年(\d{1,2})月(\d{1,2})日", dd.text)
				if m:
					y,mn,d = map(int, m.groups())
					return f"{y:04d}-{mn:02d}-{d:02d}"
	except Exception:
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

		th = soup.find("th", string=lambda s: s and "信用倍率" in s)
		if not th:
			return None

		td = th.find_next("td")
		if not td:
			return None

		text = td.get_text(strip=True)
		val = re.sub(r"[^0-9.]", "", text)
		if val:
			return float(val)
	except Exception:
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

# === MAIN ===
def main():
	if len(sys.argv) < 2:
		print(json.dumps({"error":"no code"}, ensure_ascii=False))
		return

	code = sys.argv[1].strip()
	symbol = f"{code}.T"
	df = yf.download(symbol, period="90d", interval="1d", auto_adjust=False, progress=False)
	if df.empty:
		print(json.dumps({"error":"no data"}, ensure_ascii=False))
		return

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

	# Spread trend (拡大/縮小/不明)
	if (last_ma25 is not None) and (prev_ma25 is not None) and (last_close is not None) and (prev_close is not None):
		spread_curr = abs(last_close - last_ma25)
		spread_prev = abs(prev_close - prev_ma25)
		spread = "拡大" if spread_curr > spread_prev else "縮小"
	else:
		spread = "不明"

	# 5-day average (High-Low) range
	range_series = (df["High"] - df["Low"]).tail(5)
	range_yen_val = to_float_or_none(range_series.mean())
	if (range_yen_val is not None) and (last_close is not None) and (last_close != 0):
		range_yen = round(range_yen_val, 2)
		range_pct = round(range_yen / last_close * 100, 2)
	else:
		range_yen = None
		range_pct = None

	index = yf.download("^N225", period="90d", interval="1d", auto_adjust=False, progress=False)
	beta = calc_beta(df["Close"], index["Close"]) if not index.empty else None

	out = {
		"symbol": code,
		"RSI": rsi,
		"MACD_cross": macd_cross,
		"MA5_25_spread": spread,
		"volume_change_percent": vol_change,
		"volatility_5d": {"range_yen":range_yen, "range_percent":range_pct},
		"beta": (round(beta,3) if beta is not None else None),
		"earnings_date": fetch_earnings_date(code),
		"credit_ratio": fetch_credit_ratio(code),
		"news": fetch_minkabu_news(code),
		"updated": datetime.now().strftime("%Y-%m-%d %H:%M:%S")
	}
	print(json.dumps(out, ensure_ascii=False))

if __name__ == "__main__":
	main()
