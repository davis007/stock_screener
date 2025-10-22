from playwright.sync_api import sync_playwright
from bs4 import BeautifulSoup

with sync_playwright() as p:
	browser = p.chromium.launch(headless=False)
	page = browser.new_page()
	page.goto("https://finance.yahoo.co.jp/stocks/ranking/up", wait_until="networkidle")
	html = page.content()
	soup = BeautifulSoup(html, "html.parser")

	# 現在のセレクタで取得できるか確認
	rows = soup.select("tr.RankingTable__row__1Gwp")
	print(f"Found {len(rows)} rows with selector 'tr.RankingTable__row__1Gwp'")

	if rows:
		# 最初の行を詳しく確認
		first_row = rows[0]
		print("\n--- First Row Structure ---")
		print(first_row.prettify())

		# 各tdを確認
		tds = first_row.select("td")
		print(f"\nTotal td elements: {len(tds)}")
		for i, td in enumerate(tds):
			print(f"\n--- TD {i} ---")
			print(td.prettify()[:300])

	browser.close()

