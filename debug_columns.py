import yfinance as yf
import pandas as pd
import pandas_ta as ta

ticker = "6920.T"
df = yf.download(ticker, period="120d", progress=False, auto_adjust=True)

if isinstance(df.columns, pd.MultiIndex):
	df.columns = df.columns.get_level_values(0)

df.ta.atr(length=14, append=True)

print("Columns:")
print(df.columns.tolist())
print("\nLast row:")
print(df.iloc[-1])

