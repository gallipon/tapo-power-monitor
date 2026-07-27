#!/usr/bin/env python3
# coding: utf-8
"""
デバイス本体に残っている粗い統計（日別・月別）を energy_daily / energy_monthly 用の
SQLとして書き出す。raspi 側（~/tapo/.env が読める環境）で実行する。

    python3 scripts/backfill_history.py > backfill.sql
    scp backfill.sql vps:~/ && ssh vps 'sudo mysql ... tapo < ~/backfill.sql'

P110M が保持している統計の窓は固定で、おおよそ:
  - 日別 (interval=1440) : 約3ヶ月
  - 月別 (interval=43200): 約1年
これより古い期間はデバイスから取得できない（Tapoアプリのエクスポートにしか残らない）。

get_energy_data の期間指定にはクセがあり、実機で確認した制約は次の通り:
  - interval=1440  : start/end を暦月の境界に揃える（月initialから翌月initialまで）。
    ただし要求した窓は尊重されず、保持している範囲をまるごと返してくることがある
    （レスポンスの start_timestamp が要求値と一致しない）。月をまたいで問い合わせると
    同じ日が何度も返るため、日付をキーに重複排除する。
  - interval=43200 : start/end を暦年の境界に揃える（1/1から翌年1/1まで）。
    揃っていないと PARAMS_ERROR(-1008) になる。
値が 0 の日/月は「デバイスに記録が無い」ことを意味するため出力しない
（既に投入済みのエクスポート由来データを 0 で上書きしてしまうのを防ぐ）。
進行中の月は月別テーブルに出力しない（常に途中集計であり、日別から再構成できるため）。
"""

from __future__ import annotations

import argparse
import asyncio
import os
import sys
from datetime import date, datetime, timedelta

from dotenv import load_dotenv

try:
    from kasa import Credentials, Discover
except ImportError:
    print("python-kasa が未インストールです (pip install -r raspi/requirements.txt)", file=sys.stderr)
    raise

DAILY_INTERVAL_MIN = 1440
MONTHLY_INTERVAL_MIN = 43200
DISCOVERY_TIMEOUT_SEC = 8


def month_start(d: date) -> date:
    return d.replace(day=1)


def next_month(d: date) -> date:
    return (d.replace(day=28) + timedelta(days=4)).replace(day=1)


def parse_devices(devices_str: str) -> dict[str, str]:
    """DEVICES env を {device_key: ip} にパースする（collector.py と同じ書式）"""
    out: dict[str, str] = {}
    for item in devices_str.split(","):
        item = item.strip()
        if not item:
            continue
        parts = item.split(":")
        out[parts[0].strip()] = parts[1].strip()
    return out


async def fetch_daily(device, months_back: int) -> list[tuple[date, int]]:
    """暦月単位で問い合わせて日別Whを集める（0の日は除外、日付で重複排除）"""
    found: dict[date, int] = {}
    cursor = month_start(date.today())
    for _ in range(months_back):
        start = datetime(cursor.year, cursor.month, 1)
        nxt = next_month(cursor)
        end = datetime(nxt.year, nxt.month, 1)
        try:
            res = await device.protocol.query({"get_energy_data": {
                "start_timestamp": int(start.timestamp()),
                "end_timestamp": int(end.timestamp()),
                "interval": DAILY_INTERVAL_MIN,
            }})
        except Exception as e:  # 保持期間外は素直にエラーが返ることがある
            print("-- daily {:%Y-%m}: {}: {}".format(start, type(e).__name__, str(e)[:80]))
            cursor = month_start(cursor - timedelta(days=1))
            continue
        ed = res["get_energy_data"]
        base = datetime.fromtimestamp(ed["start_timestamp"]).date()
        for i, wh in enumerate(ed.get("data") or []):
            if wh:
                d = base + timedelta(days=i)
                # 同じ日が複数回返る場合は大きい方を採る（途中集計を掴まないため）
                found[d] = max(found.get(d, 0), int(wh))
        cursor = month_start(cursor - timedelta(days=1))
    return sorted(found.items())


async def fetch_monthly(device, years: list[int]) -> list[tuple[date, int]]:
    """暦年単位で問い合わせて月別Whを集める（0の月と進行中の月は除外）"""
    current_month = month_start(date.today())
    found: dict[date, int] = {}
    for year in years:
        try:
            res = await device.protocol.query({"get_energy_data": {
                "start_timestamp": int(datetime(year, 1, 1).timestamp()),
                "end_timestamp": int(datetime(year + 1, 1, 1).timestamp()),
                "interval": MONTHLY_INTERVAL_MIN,
            }})
        except Exception as e:
            print("-- monthly {}: {}: {}".format(year, type(e).__name__, str(e)[:80]))
            continue
        ed = res["get_energy_data"]
        base = datetime.fromtimestamp(ed["start_timestamp"]).date()
        for i, wh in enumerate(ed.get("data") or []):
            if not wh:
                continue
            m = base.month - 1 + i
            month = date(base.year + m // 12, m % 12 + 1, 1)
            if month >= current_month:
                continue  # 進行中の月は途中集計なので採らない
            found[month] = max(found.get(month, 0), int(wh))
    return sorted(found.items())


def emit(table: str, col: str, device_key: str, rows: list[tuple[date, int]]) -> None:
    if not rows:
        print("-- {} {}: 取得できた行なし".format(table, device_key))
        return
    print("-- {} {}: {} 行  {} .. {}  合計 {} Wh".format(
        table, device_key, len(rows), rows[0][0], rows[-1][0], sum(w for _, w in rows)))
    print("INSERT INTO {} (device_key, `{}`, wh, source) VALUES".format(table, col))
    print(",\n".join("  ('{}', '{}', {}, 'device')".format(device_key, d, w) for d, w in rows))
    print("ON DUPLICATE KEY UPDATE wh = VALUES(wh), source = VALUES(source);")
    print()


async def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("--env", default=os.path.expanduser("~/tapo/.env"))
    ap.add_argument("--months-back", type=int, default=13, help="日別を遡る月数")
    args = ap.parse_args()

    load_dotenv(args.env)
    email = os.environ.get("TAPO_EMAIL", "")
    password = os.environ.get("TAPO_PASSWORD", "")
    devices = parse_devices(os.environ.get("DEVICES", ""))
    if not (email and password and devices):
        raise SystemExit("TAPO_EMAIL / TAPO_PASSWORD / DEVICES が未設定です")

    this_year = date.today().year
    print("-- generated by scripts/backfill_history.py at {:%Y-%m-%d %H:%M:%S}".format(datetime.now()))
    print()

    creds = Credentials(email, password)
    for key, ip in devices.items():
        device = None
        try:
            device = await Discover.discover_single(ip, credentials=creds,
                                                    discovery_timeout=DISCOVERY_TIMEOUT_SEC)
            await device.update()
            emit("energy_daily", "day", key, await fetch_daily(device, args.months_back))
            emit("energy_monthly", "month", key, await fetch_monthly(device, [this_year - 1, this_year]))
        except Exception as e:
            print("-- {} ({}): 接続/取得に失敗: {}: {}".format(key, ip, type(e).__name__, str(e)[:120]))
        finally:
            if device is not None:
                try:
                    await device.disconnect()
                except Exception:
                    pass


if __name__ == "__main__":
    asyncio.run(main())
