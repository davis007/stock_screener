from datetime import date
import holidays

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

# テスト
test_dates = [
    date(2025, 1, 1),   # 元日（祝日）
    date(2025, 1, 13),  # 成人の日（祝日）
    date(2025, 1, 14),  # 営業日
    date(2025, 10, 22), # 今日（営業日）
    date(2025, 10, 25), # 土曜日
    date(2025, 10, 26), # 日曜日
    date(2025, 12, 31), # 年末
    date(2026, 1, 1),   # 元日（祝日）
    date(2026, 1, 5),   # 営業日
]

print("市場開催日判定テスト:")
print("-" * 50)
for test_date in test_dates:
    is_open = is_market_open_day(test_date)
    weekday_name = ['月', '火', '水', '木', '金', '土', '日'][test_date.weekday()]
    status = "✓ 開場" if is_open else "✗ 休場"
    print(f"{test_date} ({weekday_name}): {status}")

