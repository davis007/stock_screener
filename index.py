from playwright.sync_api import sync_playwright
from bs4 import BeautifulSoup
from datetime import date
import os

# Playwrightのログを抑制
os.environ['DEBUG'] = ''
today = date.today()
print(today)

with sync_playwright() as p:
	browser = p.chromium.launch(headless=True, args=['--disable-blink-features=AutomationControlled'])
	page = browser.new_page()
	page.goto("https://finance.yahoo.co.jp/stocks/ranking/up", wait_until="networkidle")
	html = page.content()
	soup = BeautifulSoup(html, "html.parser")

	rows = soup.select("tr.RankingTable__row__1Gwp")
	for row in rows:
		try:
			# 企業名
			name = row.select_one("td:nth-of-type(1) a").get_text(strip=True)
			# 証券コード
			code = row.select_one("td:nth-of-type(1) ul li:nth-of-type(1)").get_text(strip=True)
			# 取引値
			price = row.select_one("td:nth-of-type(2) .StyledNumber__value__3rXW").get_text(strip=True)
			# 前日比
			change = row.select_one("td:nth-of-type(3) .StyledNumber__value__3rXW").get_text(strip=True)
			# 出来高
			volume = row.select_one("td:nth-of-type(4) .StyledNumber__value__3rXW").get_text(strip=True)
			print(name, code, price, change, volume)
		except AttributeError:
			continue
	browser.close()