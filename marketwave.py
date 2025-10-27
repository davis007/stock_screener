import yfinance as yf
import pandas as pd
from datetime import datetime, timedelta
import json
import pandas.tseries.offsets as offsets

class MarketWaveAnalyzer:
    def __init__(self, ticker):
        """
        銘柄コードを指定して初期化
        10日間の5分足データを取得
        """
        self.ticker = ticker
        self.data = None
        self.fetch_data()

    def fetch_data(self):
        """
        YFinanceから10営業日分の5分足データを取得
        """
        try:
            # 現在時刻から10営業日前までの期間を設定
            end_date = datetime.now()
            start_date = end_date - offsets.BDay(10)

            # 5分足データを取得
            stock = yf.Ticker(f"{self.ticker}.T")
            self.data = stock.history(
                start=start_date.strftime('%Y-%m-%d'),
                end=end_date.strftime('%Y-%m-%d'),
                interval='5m'
            )

            # データが空の場合のエラーハンドリング
            if self.data.empty:
                raise ValueError(f"データが取得できませんでした: {self.ticker}")

            # 日付カラムを一度だけ追加（pandas警告対策）
            self.data['date'] = self.data.index.date

        except Exception as e:
            print(f"データ取得エラー: {e}")
            self.data = None
            return  # 明示的に終了

    def uneri(self):
        """
        うねり指数を計算
        1. 1日単位で5分足を走査
        2. 始値を基準に何度始値を往復したかをカウント
        3. 10日間の平均を出力
        """
        if self.data is None or self.data.empty:
            return {"error": "データがありません"}

        try:
            # 日付ごとにグループ化（既にfetch_dataでdateカラムが追加済み）
            daily_groups = self.data.groupby('date')

            daily_results = []

            for date, day_data in daily_groups:
                if len(day_data) < 10:  # 最低10本の足が必要
                    continue

                # その日の始値（最初の5分足の始値）
                start_price = day_data.iloc[0]['Open']

                # 往復カウントと変動率を計算
                cross_count = 0
                current_direction = None  # 1:上昇, -1:下降, None:初期
                max_up_percent = 0
                max_down_percent = 0
                threshold = 0.3  # ±0.3%以内は"うねり"とみなさない

                for i in range(1, len(day_data)):
                    current_price = day_data.iloc[i]['Close']
                    price_diff_percent = ((current_price - start_price) / start_price) * 100

                    # 閾値以下の変動は無視（小さなブレを除外）
                    if abs(price_diff_percent) < threshold:
                        continue

                    # 最大上昇率・下降率を更新
                    if price_diff_percent > max_up_percent:
                        max_up_percent = price_diff_percent
                    if price_diff_percent < max_down_percent:
                        max_down_percent = price_diff_percent

                    # 方向の判定
                    if price_diff_percent > 0:
                        new_direction = 1
                    elif price_diff_percent < 0:
                        new_direction = -1
                    else:
                        new_direction = current_direction

                    # 方向が変わったらカウント
                    if current_direction is not None and new_direction != current_direction:
                        cross_count += 1

                    current_direction = new_direction

                # 1日の結果を保存
                daily_results.append({
                    'date': str(date),
                    'cross_count': cross_count,
                    'max_up_percent': round(max_up_percent, 2),
                    'max_down_percent': round(max_down_percent, 2)
                })

            # 10日間の平均を計算
            if daily_results:
                avg_cross_count = sum([r['cross_count'] for r in daily_results]) / len(daily_results)
                avg_max_up = sum([r['max_up_percent'] for r in daily_results]) / len(daily_results)
                avg_max_down = sum([r['max_down_percent'] for r in daily_results]) / len(daily_results)

                result = {
                    'ticker': self.ticker,
                    'analysis_type': 'うねり指数',
                    'daily_results': daily_results,
                    'average_cross_count': round(avg_cross_count, 2),
                    'average_max_up_percent': round(avg_max_up, 2),
                    'average_max_down_percent': round(avg_max_down, 2),
                    'total_days_analyzed': len(daily_results)
                }
            else:
                result = {"error": "分析可能なデータがありません"}

            return result

        except Exception as e:
            return {"error": f"うねり指数計算エラー: {e}"}

    def wave(self):
        """
        流動性指数を計算
        1. 1日ごとに5分足を走査して出来高の平均を出す
        2. 出来高0の本数を数える
        3. 流動性スコアを計算
        4. 10日分の平均を算出
        """
        if self.data is None or self.data.empty:
            return {"error": "データがありません"}

        try:
            # 日付ごとにグループ化（既にfetch_dataでdateカラムが追加済み）
            daily_groups = self.data.groupby('date')

            daily_results = []

            for date, day_data in daily_groups:
                if len(day_data) < 10:  # 最低10本の足が必要
                    continue

                # 出来高データ
                volumes = day_data['Volume']

                # 出来高の統計
                avg_volume = volumes.mean()
                max_volume = volumes.max()
                zero_volume_count = (volumes == 0).sum()
                zero_volume_rate = zero_volume_count / len(volumes)

                # 流動性スコア計算
                if max_volume > 0:
                    volume_ratio = avg_volume / max_volume
                    liquidity_score = volume_ratio * (1 - zero_volume_rate)
                else:
                    liquidity_score = 0

                # 1日の結果を保存
                daily_results.append({
                    'date': str(date),
                    'avg_volume': int(avg_volume),
                    'max_volume': int(max_volume),
                    'zero_volume_count': int(zero_volume_count),
                    'zero_volume_rate': round(zero_volume_rate, 4),
                    'liquidity_score': round(liquidity_score, 4)
                })

            # 10日間の平均を計算
            if daily_results:
                avg_liquidity_score = sum([r['liquidity_score'] for r in daily_results]) / len(daily_results)

                result = {
                    'ticker': self.ticker,
                    'analysis_type': '流動性指数',
                    'daily_results': daily_results,
                    'average_liquidity_score': round(avg_liquidity_score, 4),
                    'total_days_analyzed': len(daily_results)
                }
            else:
                result = {"error": "分析可能なデータがありません"}

            return result

        except Exception as e:
            return {"error": f"流動性指数計算エラー: {e}"}

    def get_analysis(self):
        """
        両方の指数をJSON形式で返却
        """
        uneri_result = self.uneri()
        wave_result = self.wave()

        combined_result = {
            'ticker': self.ticker,
            'timestamp': datetime.now().isoformat(),
            'uneri_index': uneri_result,
            'liquidity_index': wave_result
        }

        return json.dumps(combined_result, ensure_ascii=False, indent=2)


# テスト用
if __name__ == "__main__":
    # 4755 楽天グループでテスト
    analyzer = MarketWaveAnalyzer(4755)

    print("=== うねり指数 ===")
    uneri_result = analyzer.uneri()
    print(json.dumps(uneri_result, ensure_ascii=False, indent=2))

    print("\n=== 流動性指数 ===")
    wave_result = analyzer.wave()
    print(json.dumps(wave_result, ensure_ascii=False, indent=2))

    print("\n=== 統合結果 ===")
    full_result = analyzer.get_analysis()
    print(full_result)
