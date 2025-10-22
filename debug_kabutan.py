#!/usr/bin/env python3
# -*- coding: utf-8 -*-

from bs4 import BeautifulSoup
import urllib.request
import urllib.error

url = "https://kabutan.jp/warning/?mode=2_1"

headers = {
    'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
}

try:
    req = urllib.request.Request(url, headers=headers)
    with urllib.request.urlopen(req, timeout=10) as response:
        html = response.read().decode('utf-8')

    soup = BeautifulSoup(html, "html.parser")

    # 全てのテーブルを探す
    tables = soup.find_all('table')
    print(f"全テーブル数: {len(tables)}")

    # 各テーブルの行数を確認
    for idx, table in enumerate(tables):
        tbody = table.find('tbody')
        if tbody:
            rows = tbody.find_all('tr')
            print(f"\nテーブル {idx}: {len(rows)} 行")

            # 最初の行を確認
            if rows:
                first_row = rows[0]
                # 銘柄コードを含むかチェック
                code_link = first_row.find('a', href=lambda x: x and '/stock/?code=' in x)
                if code_link:
                    print(f"  ✓ 銘柄ランキングテーブル")
                    print(f"  最初の行: {first_row.prettify()[:300]}")

                    # 全行の銘柄コードを抽出
                    print(f"\n  全 {len(rows)} 件の銘柄:")
                    for i, r in enumerate(rows, 1):
                        code_link = r.find('a', href=lambda x: x and '/stock/?code=' in x)
                        name_th = r.find('th', scope='row')
                        if code_link and name_th:
                            code = code_link.get_text(strip=True)
                            name = name_th.get_text(strip=True)
                            print(f"    {i:2d}. {code} - {name}")
                    break

except Exception as e:
    print(f"エラー: {str(e)}")

