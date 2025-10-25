import requests
import sys
from bs4 import BeautifulSoup
import re

code = sys.argv[1] if len(sys.argv) > 1 else "285A"
url = f"https://kabutan.jp/stock/?code={code}"
headers = {"User-Agent": "Mozilla/5.0"}

try:
    res = requests.get(url, headers=headers, timeout=10)
    res.raise_for_status()
    soup = BeautifulSoup(res.text, "html.parser")
    
    print(f"=== かぶたん {code} の信用倍率を探索 ===\n")
    
    # 全テーブルを探索
    tables = soup.find_all("table")
    
    for i, table in enumerate(tables):
        if "信用倍率" in table.get_text():
            print(f"\nテーブル[{i}]に「信用倍率」が含まれています:")
            rows = table.find_all("tr")
            for j, row in enumerate(rows):
                cells = row.find_all(["th", "td"])
                if cells:
                    cell_texts = [f"{c.name}:'{c.get_text(strip=True)}'" for c in cells]
                    print(f"  行[{j}]: {' | '.join(cell_texts)}")
            
            # 信用倍率を探す
            print("\n  信用倍率の値を探索:")
            for row in rows:
                text = row.get_text()
                if "信用倍率" in text:
                    cells = row.find_all(["th", "td"])
                    print(f"    信用倍率を含む行のセル: {len(cells)}個")
                    for k, cell in enumerate(cells):
                        print(f"      [{k}] {cell.name}: '{cell.get_text(strip=True)}'")
                        # 数値を含むかチェック
                        val = re.sub(r"[^0-9.]", "", cell.get_text(strip=True))
                        if val and len(val) > 0:
                            print(f"          → 数値抽出: {val}")

except Exception as e:
    print(f"エラー: {e}")
    import traceback
    traceback.print_exc()
