import sqlite3
from datetime import datetime
from pathlib import Path
from typing import Optional, List, Dict, Any

class DatabaseManager:
	"""SQLiteデータベース管理クラス"""

	DB_PATH = 'stock_analysis.db'

	def __init__(self):
		self.db_path = Path(self.DB_PATH)
		self.init_db()

	def init_db(self):
		"""データベースとテーブルを初期化"""
		with sqlite3.connect(self.db_path) as conn:
			cursor = conn.cursor()

			# 企業マスタテーブル
			cursor.execute('''
				CREATE TABLE IF NOT EXISTS companies (
					code TEXT PRIMARY KEY,
					name TEXT NOT NULL
				)
			''')

			# 三位一体モデル分析結果テーブル
			cursor.execute('''
				CREATE TABLE IF NOT EXISTS analysis_results (
					id INTEGER PRIMARY KEY AUTOINCREMENT,
					analysis_date TEXT NOT NULL,
					code TEXT NOT NULL,
					price REAL NOT NULL,
					score INTEGER NOT NULL,
					rsi_9 REAL NOT NULL,
					vwap REAL NOT NULL,
					bb_width REAL NOT NULL,
					profit_target REAL,
					stop_loss REAL,
					created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
					FOREIGN KEY (code) REFERENCES companies(code),
					UNIQUE(analysis_date, code)
				)
			''')

			# バンドウォーク検出結果テーブル
			cursor.execute('''
				CREATE TABLE IF NOT EXISTS bandwalk_results (
					id INTEGER PRIMARY KEY AUTOINCREMENT,
					analysis_date TEXT NOT NULL,
					code TEXT NOT NULL,
					is_bandwalk INTEGER NOT NULL,
					price REAL NOT NULL,
					bb_width REAL NOT NULL,
					profit_target REAL,
					stop_loss REAL,
					created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
					FOREIGN KEY (code) REFERENCES companies(code),
					UNIQUE(analysis_date, code)
				)
			''')

			# エクスパンション検出結果テーブル
			cursor.execute('''
				CREATE TABLE IF NOT EXISTS expansion_results (
					id INTEGER PRIMARY KEY AUTOINCREMENT,
					analysis_date TEXT NOT NULL,
					code TEXT NOT NULL,
					is_expansion INTEGER NOT NULL,
					price REAL NOT NULL,
					bb_width REAL NOT NULL,
					expansion_rate REAL NOT NULL,
					profit_target REAL,
					stop_loss REAL,
					created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
					FOREIGN KEY (code) REFERENCES companies(code),
					UNIQUE(analysis_date, code)
				)
			''')

			# インデックス作成（検索高速化）
			cursor.execute('''
				CREATE INDEX IF NOT EXISTS idx_analysis_date
				ON analysis_results(analysis_date)
			''')
			cursor.execute('''
				CREATE INDEX IF NOT EXISTS idx_analysis_code
				ON analysis_results(code)
			''')
			cursor.execute('''
				CREATE INDEX IF NOT EXISTS idx_bandwalk_date
				ON bandwalk_results(analysis_date)
			''')
			cursor.execute('''
				CREATE INDEX IF NOT EXISTS idx_bandwalk_code
				ON bandwalk_results(code)
			''')
			cursor.execute('''
				CREATE INDEX IF NOT EXISTS idx_expansion_date
				ON expansion_results(analysis_date)
			''')
			cursor.execute('''
				CREATE INDEX IF NOT EXISTS idx_expansion_code
				ON expansion_results(code)
			''')

			conn.commit()

	def upsert_company(self, code: str, name: str) -> None:
		"""企業情報を保存（存在しない場合は挿入、存在する場合は更新）"""
		with sqlite3.connect(self.db_path) as conn:
			cursor = conn.cursor()
			cursor.execute('''
				INSERT OR REPLACE INTO companies (code, name)
				VALUES (?, ?)
			''', (code, name))
			conn.commit()

	def save_analysis_result(self, analysis_date: str, result: Dict[str, Any]) -> None:
		"""三位一体モデルの分析結果を保存"""
		with sqlite3.connect(self.db_path) as conn:
			cursor = conn.cursor()
			cursor.execute('''
				INSERT OR REPLACE INTO analysis_results
				(analysis_date, code, price, score, rsi_9, vwap, bb_width, profit_target, stop_loss)
				VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
			''', (
				analysis_date,
				result['code'],
				result['price'],
				result['score'],
				result['RSI_9'],
				result['VWAP'],
				result['BB_Width'],
				result['profit_target'],
				result['stop_loss']
			))
			conn.commit()

	def save_bandwalk_result(self, analysis_date: str, result: Dict[str, Any]) -> None:
		"""バンドウォーク検出結果を保存"""
		with sqlite3.connect(self.db_path) as conn:
			cursor = conn.cursor()
			cursor.execute('''
				INSERT OR REPLACE INTO bandwalk_results
				(analysis_date, code, is_bandwalk, price, bb_width, profit_target, stop_loss)
				VALUES (?, ?, ?, ?, ?, ?, ?)
			''', (
				analysis_date,
				result['code'],
				1 if result['is_bandwalk'] else 0,
				result['price'],
				result['bb_width'],
				result['profit_target'],
				result['stop_loss']
			))
			conn.commit()

	def get_analysis_results(self, analysis_date: str) -> List[Dict[str, Any]]:
		"""指定日付の三位一体モデル分析結果を取得"""
		with sqlite3.connect(self.db_path) as conn:
			conn.row_factory = sqlite3.Row
			cursor = conn.cursor()
			cursor.execute('''
				SELECT ar.*, c.name as company_name
				FROM analysis_results ar
				LEFT JOIN companies c ON ar.code = c.code
				WHERE ar.analysis_date = ?
				ORDER BY ar.score DESC
			''', (analysis_date,))
			return [dict(row) for row in cursor.fetchall()]

	def save_expansion_result(self, analysis_date: str, result: Dict[str, Any]) -> None:
		"""エクスパンション検出結果を保存"""
		with sqlite3.connect(self.db_path) as conn:
			cursor = conn.cursor()
			cursor.execute('''
				INSERT OR REPLACE INTO expansion_results
				(analysis_date, code, is_expansion, price, bb_width, expansion_rate, profit_target, stop_loss)
				VALUES (?, ?, ?, ?, ?, ?, ?, ?)
			''', (
				analysis_date,
				result['code'],
				1 if result['is_expansion'] else 0,
				result['price'],
				result['bb_width'],
				result['expansion_rate'],
				result['profit_target'],
				result['stop_loss']
			))
			conn.commit()

	def get_bandwalk_results(self, analysis_date: str) -> List[Dict[str, Any]]:
		"""指定日付のバンドウォーク検出結果を取得"""
		with sqlite3.connect(self.db_path) as conn:
			conn.row_factory = sqlite3.Row
			cursor = conn.cursor()
			cursor.execute('''
				SELECT br.*, c.name as company_name
				FROM bandwalk_results br
				LEFT JOIN companies c ON br.code = c.code
				WHERE br.analysis_date = ?
				ORDER BY br.code
			''', (analysis_date,))
			return [dict(row) for row in cursor.fetchall()]

	def get_expansion_results(self, analysis_date: str) -> List[Dict[str, Any]]:
		"""指定日付のエクスパンション検出結果を取得"""
		with sqlite3.connect(self.db_path) as conn:
			conn.row_factory = sqlite3.Row
			cursor = conn.cursor()
			cursor.execute('''
				SELECT er.*, c.name as company_name
				FROM expansion_results er
				LEFT JOIN companies c ON er.code = c.code
				WHERE er.analysis_date = ?
				ORDER BY er.code
			''', (analysis_date,))
			return [dict(row) for row in cursor.fetchall()]

	def get_all_analysis_dates(self) -> List[str]:
		"""全ての分析日付を取得（降順）"""
		with sqlite3.connect(self.db_path) as conn:
			cursor = conn.cursor()
			cursor.execute('''
				SELECT DISTINCT analysis_date
				FROM analysis_results
				ORDER BY analysis_date DESC
			''')
			return [row[0] for row in cursor.fetchall()]

	def get_analysis_dates_by_year_month(self) -> Dict[str, Dict[str, List[str]]]:
		"""年月ごとにグループ化した分析日付を取得"""
		with sqlite3.connect(self.db_path) as conn:
			cursor = conn.cursor()
			cursor.execute('''
				SELECT DISTINCT analysis_date
				FROM analysis_results
				ORDER BY analysis_date DESC
			''')

			date_groups = {}
			for row in cursor.fetchall():
				date_str = row[0]  # YYYY-MM-DD形式
				year, month, day = date_str.split('-')

				year_key = year
				month_key = f"{year}年{int(month)}月"
				day_key = f"{int(day)}日"

				if year_key not in date_groups:
					date_groups[year_key] = {}
				if month_key not in date_groups[year_key]:
					date_groups[year_key][month_key] = []

				date_groups[year_key][month_key].append({
					'day': day_key,
					'date_str': date_str
				})

			return date_groups

