#!/usr/bin/env python3
# coding: utf-8
"""
Tapo P110M 導通テストスクリプト

.env の DEVICES に列挙された3台へ順に接続し、モデル名・FWバージョン・
現在電力(生値[mW]と換算値[W]の両方)・today_energy(Wh) を表示する。
1台の接続/取得に失敗しても処理を止めず、エラー内容を表示して次のデバイスへ進む。

実行例:
    cd raspi
    python test_devices.py
"""

from __future__ import annotations

import asyncio

from collector import TapoDevice, load_config


async def test_one(device_key: str, ip: str, email: str, password: str) -> None:
    print(f"--- {device_key} ({ip}) ---")
    dev = TapoDevice(device_key, ip, email, password)
    try:
        await dev.connect()

        info = await dev.get_device_info()
        print(f"  model      : {info['model']}")
        print(f"  fw_ver     : {info['fw_ver']}")
        print(f"  device_on  : {info['device_on']}")

        raw_mw, power_w = await dev.get_current_power_detail()
        print(f"  current_power(生値) : {raw_mw}")
        print(f"  current_power(換算) : {power_w:.1f} W")

        today_wh = await dev.get_today_energy_wh()
        print(f"  today_energy : {today_wh} Wh")

        print(f"  OK: {device_key} 疎通確認できました")
    except Exception as e:
        print(f"  NG: {device_key} 接続/取得に失敗しました: {type(e).__name__}: {e}")


async def main() -> None:
    email, password, device_ips, _api_url, _api_key = load_config()

    if not device_ips:
        print("DEVICES が空です。.env を確認してください。")
        return

    for device_key, ip in device_ips.items():
        await test_one(device_key, ip, email, password)
        print()


if __name__ == "__main__":
    asyncio.run(main())
