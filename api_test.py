import requests
from bs4 import BeautifulSoup
import re

def fetch_kabutan_credit_ratio(code: str):
	url = f"https://kabutan.jp/stock/?code={code}"
	headers = {"User-Agent": "Mozilla/5.0"}
	try:
		res = requests.get(url, headers=headers, timeout=10)
		res.raise_for_status()
		soup = BeautifulSoup(res.text, "html.parser")

		# 「信用倍率」という<th>を直接検索
		th = soup.find("th", string=lambda s: s and "信用倍率" in s)
		if not th:
			print("⚠️ <th> 信用倍率 が見つかりません。")
			return None

		td = th.find_next("td")
		if not td:
			print("⚠️ <td> 要素が見つかりません。")
			return None

		text = td.get_text(strip=True)
		val = re.sub(r"[^0-9.]", "", text)
		if val:
			return float(val)
		else:
			print(f"⚠️ 数値抽出に失敗しました。抽出対象: {text}")
	except Exception as e:
		print("Error:", e)
	return None

if __name__ == "__main__":
	code = input("銘柄コードを入力してください（例: 7203）: ").strip()
	ratio = fetch_kabutan_credit_ratio(code)
	if ratio is not None:
		print(f"信用倍率({code})：{ratio}")
	else:
		print("信用倍率の取得に失敗しました。")