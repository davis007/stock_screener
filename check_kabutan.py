import requests
import sys
from bs4 import BeautifulSoup

code = sys.argv[1] if len(sys.argv) > 1 else "285A"
url = f"https://kabutan.jp/stock/?code={code}"
headers = {"User-Agent": "Mozilla/5.0"}

try:
    res = requests.get(url, headers=headers, timeout=10)
    res.raise_for_status()
    soup = BeautifulSoup(res.text, "html.parser")
    
    # 「信用倍率」を含む全ての要素を探す
    all_elements = soup.find_all(string=lambda s: s and "信用倍率" in s)
    
    print(f"=== かぶたん {code} のページで「信用倍率」を含む要素 ===")
    print(f"見つかった要素数: {len(all_elements)}")
    print()
    
    for i, elem in enumerate(all_elements, 1):
        print(f"[{i}] 要素タイプ: {elem.parent.name}")
        print(f"    テキスト: {elem.strip()}")
        print(f"    親要素: {elem.parent}")
        
        # 次の兄弟要素を確認
        sibling = elem.parent.find_next_sibling()
        if sibling:
            print(f"    次の兄弟: {sibling.name} = {sibling.get_text(strip=True)}")
        
        # find_next("td")を確認
        next_td = elem.parent.find_next("td")
        if next_td:
            print(f"    次のtd: {next_td.get_text(strip=True)}")
        print()
    
    if not all_elements:
        print("「信用倍率」を含む要素が見つかりませんでした。")
        print("このページには信用倍率の情報がない可能性があります。")

except Exception as e:
    print(f"エラー: {e}")
