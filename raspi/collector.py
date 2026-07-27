#!/usr/bin/env python3
# coding: utf-8
"""
Tapo P110M 電力データ収集サービス（常駐、asyncio）

3系統の周期タスクを並行実行する:
  - 60秒ごと  : 各デバイスの瞬時電力(W)を取得しローカルSQLiteバッファへ保存
  - 600秒ごと : sent=0 のバッファ行をまとめてVPSの power.php へPOST（成功したらsent=1）
  - 3600秒ごと: 各デバイスのhourly電力量履歴(Wh, 今日・昨日分)を取得しVPSの energy.php へupsert

タイムゾーンは Asia/Tokyo(JST) に統一する。SQLiteに保存する ts / hour_start、
VPSへ送るJSONの日時文字列はすべて JST のnaive文字列 "YYYY-MM-DD HH:MM:SS"。

デバイスへのアクセスは TapoDevice クラスに薄くラップしてある。中身は python-kasa
実装で、接続のたびに Discover.discover_single() を通すことでデバイスが広告している
暗号方式(KLAP / TPAP)を自動判別する。FW1.4系のP110Mは「サードパーティ互換性」設定が
ONならKLAP、OFFならTPAPを広告するが、どちらに転んでも収集側のコードは変わらない。
(TPAPはFW1.4.3以降のKLAP認証が壊れている個体の唯一の接続手段。詳細はREADME参照)

例外でプロセス全体が落ちないよう、デバイス個別のエラーは全て捕捉して
ログ出力のみ行い、次周期に再接続を試みる。それでも死んだ場合は
systemd の Restart=always が最後の砦。

DHCPでプラグのIPが変わっても収集が止まらないよう、接続/取得に失敗した周期でのみ
Discover.discover() によるLANブロードキャストdiscoveryを実行し、MACアドレスで
現在のIPを特定して ~/tapo/ip_cache.json を更新する。接続が成功している通常時は
ブロードキャストdiscoveryを一切トリガーしないため、平常運転時のオーバーヘッドはゼロ。
"""

from __future__ import annotations

import asyncio
import json
import os
import sqlite3
import time
from dataclasses import dataclass
from datetime import datetime, timedelta
from pathlib import Path
from zoneinfo import ZoneInfo

import aiohttp
from dotenv import load_dotenv

try:
    from kasa import Credentials, Discover
except ImportError:  # pip install前や導通確認前は未インストールの可能性がある
    Credentials = None  # type: ignore[assignment,misc]
    Discover = None  # type: ignore[assignment,misc]

JST = ZoneInfo("Asia/Tokyo")

BASE_DIR = Path(__file__).resolve().parent
DB_PATH = BASE_DIR / "buffer.db"
IP_CACHE_PATH = Path.home() / "tapo" / "ip_cache.json"

POWER_POLL_INTERVAL_SEC = 60
SEND_INTERVAL_SEC = 600
ENERGY_INTERVAL_SEC = 3600
SENT_ROW_RETENTION_DAYS = 7

DISCOVERY_TIMEOUT_SEC = 4
DISCOVERY_MIN_INTERVAL_SEC = 60.0  # 前回discoveryからの最低間隔（全台ダウン時の乱発防止）

HTTP_TIMEOUT = aiohttp.ClientTimeout(total=30)

CONNECT_DISCOVERY_TIMEOUT_SEC = 8  # 個別デバイスへのunicast discovery（connect時）

# get_energy_data の interval は「1区間あたりの分数」。60 = 1時間刻み。
ENERGY_DATA_INTERVAL_MIN = 60

# 前回discovery実行時刻（time.monotonic()）。モジュールレベルでレート制限を共有する。
_last_discovery_ts: float = 0.0

# 後始末中のdisconnectタスク。GCで途中キャンセルされないよう完了まで参照を保持する。
_pending_disconnects: set[asyncio.Task] = set()


# ---------------------------------------------------------------------------
# 時刻・設定ユーティリティ
# ---------------------------------------------------------------------------

def now_jst() -> datetime:
    return datetime.now(JST)


def fmt_dt(dt: datetime) -> str:
    """DB/API向けのJSTナイーブ文字列 "YYYY-MM-DD HH:MM:SS" に整形する"""
    return dt.strftime("%Y-%m-%d %H:%M:%S")


def normalize_mac(mac: str) -> str:
    """MACアドレスを正規化する（区切り文字 : - を除去し大文字化）。比較はこの形式で行う"""
    return mac.replace(":", "").replace("-", "").upper()


@dataclass
class DeviceConfig:
    ip_hint: str  # .env記載のIP（キャッシュが無い場合のフォールバック）
    mac: str | None  # 正規化済みMAC。未設定ならdiscoveryによるIP追従の対象外


def parse_devices_full(devices_str: str) -> dict[str, DeviceConfig]:
    """
    DEVICES env をパースする。

    新形式(mac付き) : "plug1:192.168.1.100:AA-BB-CC-00-00-01,plug2:...:...,..."
    旧形式(mac無し) : "plug1:192.168.1.100,plug2:192.168.1.101,..." も引き続き受理する
                       （区切り文字は : / - のどちらでもよい）。
    mac無しのデバイスはdiscoveryによるIP追従の対象外（従来通り固定IP）となり、警告を出す。
    """
    result: dict[str, DeviceConfig] = {}
    for item in devices_str.split(","):
        item = item.strip()
        if not item:
            continue
        parts = item.split(":")
        if len(parts) == 2:
            key, ip = (p.strip() for p in parts)
            mac_norm = None
        elif len(parts) >= 3:
            key = parts[0].strip()
            ip = parts[1].strip()
            # mac自体が ":" 区切りで書かれている場合に備え、残りを再結合してから正規化する
            mac_raw = ":".join(p.strip() for p in parts[2:])
            mac_norm = normalize_mac(mac_raw)
        else:
            raise ValueError(f"DEVICES の書式が不正です: {item!r}")

        if not key or not ip:
            raise ValueError(f"DEVICES の書式が不正です: {item!r}")
        if mac_norm is None:
            print(f"[config] {key}: MAC未設定のためIP自動追従は無効です（固定IP {ip} を使用）")

        result[key] = DeviceConfig(ip_hint=ip, mac=mac_norm)
    return result


def parse_devices(devices_str: str) -> dict[str, str]:
    """
    DEVICES env を {device_key: ip} にパースする（後方互換用。test_devices.py が使用）。
    mac情報が必要な場合は parse_devices_full() / load_device_configs() を使うこと。
    """
    return {key: cfg.ip_hint for key, cfg in parse_devices_full(devices_str).items()}


def load_config() -> tuple[str, str, dict[str, str], str, str]:
    """.env を読み込み (email, password, {device_key: ip}, api_url, api_key) を返す"""
    load_dotenv()
    email = os.environ.get("TAPO_EMAIL", "")
    password = os.environ.get("TAPO_PASSWORD", "")
    api_url = os.environ.get("API_URL", "").rstrip("/")
    api_key = os.environ.get("API_KEY", "")
    devices_str = os.environ.get("DEVICES", "")

    if not email or not password:
        raise RuntimeError("TAPO_EMAIL / TAPO_PASSWORD が未設定です (.env を確認してください)")
    if not api_url or not api_key:
        raise RuntimeError("API_URL / API_KEY が未設定です (.env を確認してください)")
    if not devices_str:
        raise RuntimeError("DEVICES が未設定です (.env を確認してください)")

    return email, password, parse_devices(devices_str), api_url, api_key


def load_device_configs() -> dict[str, DeviceConfig]:
    """
    DEVICES env をmac情報付きでパースする（discoveryによるIP追従用）。
    load_config() 呼び出し後（.envがロード済みの状態）で使うこと。
    """
    devices_str = os.environ.get("DEVICES", "")
    if not devices_str:
        raise RuntimeError("DEVICES が未設定です (.env を確認してください)")
    return parse_devices_full(devices_str)


# ---------------------------------------------------------------------------
# IPキャッシュ（~/tapo/ip_cache.json）
# ---------------------------------------------------------------------------

def load_ip_cache() -> dict[str, str]:
    """device_key -> ip のキャッシュを読み込む。無ければ空dictを返す"""
    try:
        with open(IP_CACHE_PATH, "r", encoding="utf-8") as f:
            data = json.load(f)
        if isinstance(data, dict):
            return {str(k): str(v) for k, v in data.items()}
    except FileNotFoundError:
        pass
    except (json.JSONDecodeError, OSError) as e:
        print(f"[ip_cache] 読み込み失敗（無視して続行）: {type(e).__name__}: {e}")
    return {}


def save_ip_cache(cache: dict[str, str]) -> None:
    """device_key -> ip のキャッシュを書き込む（秘密情報ではないのでパーミッションは考慮しない）"""
    try:
        IP_CACHE_PATH.parent.mkdir(parents=True, exist_ok=True)
        tmp_path = IP_CACHE_PATH.with_suffix(".tmp")
        with open(tmp_path, "w", encoding="utf-8") as f:
            json.dump(cache, f, ensure_ascii=False, indent=2)
        tmp_path.replace(IP_CACHE_PATH)
    except OSError as e:
        print(f"[ip_cache] 書き込み失敗（無視して続行）: {type(e).__name__}: {e}")


# ---------------------------------------------------------------------------
# デバイスアクセス（薄いラッパー。後で python-kasa 実装に差し替え可能）
# ---------------------------------------------------------------------------

@dataclass
class HourlyEnergyPoint:
    hour_start: datetime  # JSTナイーブ
    wh: int


class TapoDevice:
    """
    P110M への薄いアクセスラッパー。

    python-kasa 実装。connect() で Discover.discover_single() を通すため、
    デバイスが広告する暗号方式(KLAP / TPAP)は自動判別される。取得系は
    modules/update() を経由せず生クエリ(get_energy_usage / get_energy_data)を
    直接叩く。これは `tapo` ライブラリ実装時と同一のリクエストであり、
    値の意味・分解能が変わらないことを実機で確認済み(2026-07-25)。

    power_poll_loop と energy_loop は同じインスタンスを共有するため、デバイスへの
    アクセスは全てロックで直列化する。TPAPのPAKEハンドシェイクは状態を持ち、
    同時に2本張ると `pake_share failed: INTERNAL_UNKNOWN_ERROR(-100000)` で
    両方失敗する（KLAPは同時接続を許容していたので旧実装では顕在化しなかった）。

    呼び出し側は device_key/ip とこのクラスのpublicメソッドしか知らない。
    """

    def __init__(
        self,
        device_key: str,
        ip: str,
        email: str,
        password: str,
        mac: str | None = None,
    ):
        self.device_key = device_key
        self.ip = ip
        self.mac = mac  # 正規化済みMAC。Noneならdiscoveryによる追従対象外
        self._email = email
        self._password = password
        self._device = None  # kasa.Device
        self._lock = asyncio.Lock()  # デバイスへのアクセスを直列化する（TPAPのPAKE衝突対策）

    @property
    def connected(self) -> bool:
        return self._device is not None

    def reset(self) -> None:
        """接続状態をリセットし、次回アクセス時に再接続させる"""
        device, self._device = self._device, None
        if device is not None:
            # 旧デバイスが抱えるHTTPセッションを閉じる（同期メソッドなので投げっぱなしにする）
            _schedule_disconnect(device)

    def update_ip(self, new_ip: str) -> None:
        """discoveryで新IPが判明した際に呼ぶ。接続状態もリセットし次回再接続させる"""
        self.ip = new_ip
        self.reset()

    async def connect(self) -> None:
        """
        未接続なら接続する（接続済みなら何もしない）。

        discover_single() は対象IPへUDP 20002でTDPプローブを打ってから接続するため、
        (a) 暗号方式(KLAP/TPAP)の自動判別 (b) ローカルAPIを遅延起動する個体の
        叩き起こし、の両方を兼ねる。
        """
        if Discover is None or Credentials is None:
            raise RuntimeError("python-kasa が未インストールです (pip install -r requirements.txt)")

        async with self._lock:
            if self._device is not None:
                return  # ロック待ちの間に他方のループが繋いでいた（二重接続を作らない）

            device = await Discover.discover_single(
                self.ip,
                credentials=Credentials(self._email, self._password),
                discovery_timeout=CONNECT_DISCOVERY_TIMEOUT_SEC,
            )
            # ここで初めて認証が走る。失敗すれば例外が飛び、呼び出し側がreset()して次周期に再試行する。
            # self._device へ入る前に落ちた分は reset() の掃除対象にならないため、この場で閉じる
            # （認証不能な個体が1台あると毎分HTTPセッションを漏らし続けることになるため）
            try:
                await device.update()
            except BaseException:
                await _safe_disconnect(device)
                raise
            self._device = device

    async def _query(self, method: str, params: dict | None = None) -> dict:
        """生クエリを1回投げ、method直下のdictを返す"""
        async with self._lock:
            device = self._device
            assert device is not None, "connect() を先に呼んでください"
            response = await device.protocol.query({method: params or {}})
        result = response.get(method)
        if not isinstance(result, dict):
            raise RuntimeError(f"{method}() のレスポンスが不正です: {response!r}")
        return result

    async def get_device_info(self) -> dict:
        """モデル名・FWバージョン等を dict で返す（test_devices.py 用）"""
        async with self._lock:
            device = self._device
            assert device is not None, "connect() を先に呼んでください"
            await device.update()
            return {
                "model": device.model,
                "fw_ver": device.device_info.firmware_version,
                "device_on": device.is_on,
            }

    async def get_current_power_detail(self) -> tuple[int | None, float]:
        """
        瞬時電力を (生値[mW], 換算値[W]) のタプルで返す。

        実機検証済み(P110M(JP) FW 1.4.1, 2026-07-17):
          - get_current_power().current_power は W 直値(整数、例: 259)
          - get_energy_usage().current_power は mW(例: 259186)
        サブワット分解能が取れる get_energy_usage() 側を採用し、1000で割ってWに換算する。
        """
        result = await self._query("get_energy_usage")
        raw_mw = result.get("current_power")
        if raw_mw is None:
            raise RuntimeError("get_energy_usage() のレスポンスに current_power がありません")
        return raw_mw, raw_mw / 1000.0

    async def get_current_power_w(self) -> float:
        """瞬時電力をW単位で返す（収集ループ用）"""
        _raw_mw, watts = await self.get_current_power_detail()
        return watts

    async def get_today_energy_wh(self) -> int | None:
        """導通テスト表示用。get_energy_usage().today_energy (Wh) を返す"""
        result = await self._query("get_energy_usage")
        return result.get("today_energy")

    async def get_hourly_energy(self, day: datetime) -> list[HourlyEnergyPoint]:
        """
        指定日(JSTローカル日付)のhourly電力量履歴(Wh)を取得する。

        get_energy_data は start_timestamp / end_timestamp をepoch秒で受け、
        interval は1区間あたりの分数（60 = 1時間刻み）。レスポンスの data が
        Wh配列、start_timestamp が1件目の開始時刻。

        実機確認済み(P110M(JP) FW 1.4.1 / python-kasa, 2026-07-25):
          - 要求した start_timestamp がそのまま echo される（時刻解釈のズレなし）
          - 指定日の00:00始まりで24件返り、合計が get_energy_usage().today_energy と一致
          - 既存の energy_hourly（tapoライブラリ収集分）と24時間すべて完全一致
        """
        start = datetime(day.year, day.month, day.day)
        end = start + timedelta(days=1)

        result = await self._query(
            "get_energy_data",
            {
                "start_timestamp": int(start.timestamp()),
                "end_timestamp": int(end.timestamp()),
                "interval": ENERGY_DATA_INTERVAL_MIN,
            },
        )
        entries = result.get("data") or []
        # デバイスが返した開始時刻を基準にする（要求値と一致するが、ズレた場合はデバイス側を正とする）
        base_ts = result.get("start_timestamp")
        base = datetime.fromtimestamp(base_ts) if base_ts else start
        return [
            HourlyEnergyPoint(hour_start=base + timedelta(hours=i), wh=int(wh))
            for i, wh in enumerate(entries)
        ]


# ---------------------------------------------------------------------------
# LAN discovery によるIP追従（接続失敗時のみ発火。成功パスには一切絡まない）
# ---------------------------------------------------------------------------

def _schedule_disconnect(device) -> None:
    """
    デバイスのHTTPセッションを閉じるタスクを投げる（後始末専用。同期文脈から呼ぶ）。
    イベントループ外から呼ばれた場合は何もしない（プロセス終了時など）。
    """
    try:
        loop = asyncio.get_running_loop()
    except RuntimeError:
        return
    task = loop.create_task(_safe_disconnect(device))
    _pending_disconnects.add(task)
    task.add_done_callback(_pending_disconnects.discard)


async def rediscover_ips(devices: dict[str, TapoDevice]) -> dict[str, str]:
    """
    Discover.discover() を1回呼び、LAN上で応答した機器の
    {正規化MAC: IP} を返す。例外時・未インストール時は空dictを返しログのみ出す。
    devices引数は探索対象台数のログ表示にのみ使う（discover()自体はLAN全体をbroadcastする）。
    """
    if Discover is None:
        print("[discovery] python-kasa が未インストールのためLAN discoveryをスキップします")
        return {}

    try:
        found_devices = await Discover.discover(timeout=DISCOVERY_TIMEOUT_SEC)
    except Exception as e:
        print(f"[discovery] LAN discovery失敗: {type(e).__name__}: {e}")
        return {}

    result: dict[str, str] = {}
    for ip, kdev in found_devices.items():
        mac = getattr(kdev, "mac", None)
        if mac:
            result[normalize_mac(mac)] = ip

    # discover()で開いた接続は使い切ったら閉じる（放置してもプロセスは落ちないが後始末する）
    await asyncio.gather(
        *(_safe_disconnect(kdev) for kdev in found_devices.values()), return_exceptions=True
    )

    print(f"[discovery] {len(result)}台の機器を発見しました（対象デバイス数: {len(devices)}）")
    return result


async def _safe_disconnect(kdev) -> None:
    try:
        await kdev.disconnect()
    except Exception:
        pass  # 後始末に失敗してもプロセスは継続する


async def maybe_rediscover_and_update(failed_devices: dict[str, TapoDevice]) -> None:
    """
    接続/取得に失敗したデバイスがある周期でのみ呼ばれるトリガー。
    mac未設定のデバイスはIP追従対象外なのでそもそもdiscoveryの理由にしない。
    レート制限（DISCOVERY_MIN_INTERVAL_SEC）を満たしていれば、失敗デバイス全体で
    discoveryを最大1回だけ実行し、IPが変わっていたデバイスのみ更新してキャッシュへ保存する。
    """
    global _last_discovery_ts

    targets = {key: dev for key, dev in failed_devices.items() if dev.mac}
    if not targets:
        return  # mac未設定デバイスしか失敗していなければdiscoveryする意味がない

    now = time.monotonic()
    if now - _last_discovery_ts < DISCOVERY_MIN_INTERVAL_SEC:
        return  # レート制限中。次周期以降に持ち越す
    _last_discovery_ts = now

    print(f"[discovery] 接続失敗を検知。LAN discoveryを実行します（対象: {list(targets.keys())}）")
    found = await rediscover_ips(targets)
    if not found:
        return

    cache = await asyncio.to_thread(load_ip_cache)
    cache_updated = False
    for key, dev in targets.items():
        new_ip = found.get(dev.mac)
        if new_ip is None:
            continue
        if new_ip != dev.ip:
            print(f"[discovery] {key}: IP変更を検知 {dev.ip} -> {new_ip}")
            dev.update_ip(new_ip)
            cache[key] = new_ip
            cache_updated = True
        else:
            print(f"[discovery] {key}: IPは変わっていません({dev.ip})。デバイス側の別要因の可能性があります")

    if cache_updated:
        await asyncio.to_thread(save_ip_cache, cache)


# ---------------------------------------------------------------------------
# SQLiteバッファ
# ---------------------------------------------------------------------------

def init_db() -> None:
    conn = sqlite3.connect(DB_PATH)
    try:
        conn.execute(
            """
            CREATE TABLE IF NOT EXISTS power_buffer (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                device_key TEXT NOT NULL,
                ts TEXT NOT NULL,
                power_w REAL NOT NULL,
                sent INTEGER NOT NULL DEFAULT 0
            )
            """
        )
        conn.execute("CREATE INDEX IF NOT EXISTS idx_power_buffer_sent ON power_buffer(sent)")
        conn.commit()
    finally:
        conn.close()


def insert_power_sample(device_key: str, ts: str, power_w: float) -> None:
    conn = sqlite3.connect(DB_PATH)
    try:
        conn.execute(
            "INSERT INTO power_buffer (device_key, ts, power_w, sent) VALUES (?, ?, ?, 0)",
            (device_key, ts, power_w),
        )
        conn.commit()
    finally:
        conn.close()


def fetch_unsent() -> list[sqlite3.Row]:
    conn = sqlite3.connect(DB_PATH)
    conn.row_factory = sqlite3.Row
    try:
        cur = conn.execute(
            "SELECT id, device_key, ts, power_w FROM power_buffer WHERE sent = 0 ORDER BY id"
        )
        return cur.fetchall()
    finally:
        conn.close()


def mark_sent(ids: list[int]) -> None:
    if not ids:
        return
    conn = sqlite3.connect(DB_PATH)
    try:
        placeholders = ",".join("?" for _ in ids)
        conn.execute(f"UPDATE power_buffer SET sent = 1 WHERE id IN ({placeholders})", ids)
        conn.commit()
    finally:
        conn.close()


def purge_old_sent(retention_days: int = SENT_ROW_RETENTION_DAYS) -> None:
    """送信済み(sent=1)でretention_days超過した行を削除する"""
    cutoff = fmt_dt(now_jst() - timedelta(days=retention_days))
    conn = sqlite3.connect(DB_PATH)
    try:
        conn.execute("DELETE FROM power_buffer WHERE sent = 1 AND ts < ?", (cutoff,))
        conn.commit()
    finally:
        conn.close()


# ---------------------------------------------------------------------------
# VPSへの送信
# ---------------------------------------------------------------------------

async def send_power_batch(
    session: aiohttp.ClientSession, api_url: str, api_key: str, rows: list[sqlite3.Row]
) -> bool:
    records = [
        {"device_key": r["device_key"], "ts": r["ts"], "power_w": r["power_w"]} for r in rows
    ]
    try:
        async with session.post(
            f"{api_url}/power.php",
            json={"records": records},
            headers={"X-API-Key": api_key},
            timeout=HTTP_TIMEOUT,
        ) as resp:
            if resp.status == 200:
                return True
            body = await resp.text()
            print(f"[send] power.php送信失敗 status={resp.status} body={body[:200]}")
            return False
    except (aiohttp.ClientError, asyncio.TimeoutError) as e:
        print(f"[send] power.php送信エラー: {e}")
        return False


async def send_energy_batch(
    session: aiohttp.ClientSession, api_url: str, api_key: str, records: list[dict]
) -> bool:
    try:
        async with session.post(
            f"{api_url}/energy.php",
            json={"records": records},
            headers={"X-API-Key": api_key},
            timeout=HTTP_TIMEOUT,
        ) as resp:
            if resp.status == 200:
                return True
            body = await resp.text()
            print(f"[energy] energy.php送信失敗 status={resp.status} body={body[:200]}")
            return False
    except (aiohttp.ClientError, asyncio.TimeoutError) as e:
        print(f"[energy] energy.php送信エラー: {e}")
        return False


# ---------------------------------------------------------------------------
# 周期タスク
# ---------------------------------------------------------------------------

async def power_poll_loop(devices: dict[str, TapoDevice]) -> None:
    """60秒ごとに各デバイスの瞬時電力を取得しSQLiteバッファへ保存する"""
    while True:
        ts = fmt_dt(now_jst())
        failed: dict[str, TapoDevice] = {}
        for device_key, dev in devices.items():
            try:
                if not dev.connected:
                    await dev.connect()
                power_w = await dev.get_current_power_w()
                await asyncio.to_thread(insert_power_sample, device_key, ts, power_w)
            except Exception as e:
                # デバイス個別のエラーはスキップし、次周期に再接続する。
                # プロセス全体を落とさないことを最優先する。
                print(f"[power] {device_key} 取得失敗: {type(e).__name__}: {e}")
                dev.reset()
                failed[device_key] = dev
        if failed:
            # 接続成功パスはここを一切通らない＝discoveryは失敗時のみ発火する
            await maybe_rediscover_and_update(failed)
        await asyncio.sleep(POWER_POLL_INTERVAL_SEC)


async def send_loop(api_url: str, api_key: str) -> None:
    """600秒ごとに未送信バッファをまとめてVPSへPOSTする"""
    async with aiohttp.ClientSession() as session:
        while True:
            await asyncio.sleep(SEND_INTERVAL_SEC)
            rows = await asyncio.to_thread(fetch_unsent)
            if rows:
                ok = await send_power_batch(session, api_url, api_key, rows)
                if ok:
                    ids = [r["id"] for r in rows]
                    await asyncio.to_thread(mark_sent, ids)
                    print(f"[send] {len(rows)}件送信完了")
                else:
                    print(f"[send] {len(rows)}件送信失敗。次回に再送します")
            await asyncio.to_thread(purge_old_sent)


async def energy_loop(devices: dict[str, TapoDevice], api_url: str, api_key: str) -> None:
    """3600秒ごとに各デバイスのhourly電力量履歴(今日・昨日)を取得しVPSへupsertする"""
    async with aiohttp.ClientSession() as session:
        while True:
            today = now_jst()
            yesterday = today - timedelta(days=1)
            records: list[dict] = []
            failed: dict[str, TapoDevice] = {}

            for device_key, dev in devices.items():
                try:
                    if not dev.connected:
                        await dev.connect()
                    for day in (yesterday, today):
                        points = await dev.get_hourly_energy(day)
                        for p in points:
                            records.append(
                                {
                                    "device_key": device_key,
                                    "hour_start": fmt_dt(p.hour_start),
                                    "wh": p.wh,
                                }
                            )
                except Exception as e:
                    print(f"[energy] {device_key} 取得失敗: {type(e).__name__}: {e}")
                    dev.reset()
                    failed[device_key] = dev

            if failed:
                await maybe_rediscover_and_update(failed)

            if records:
                ok = await send_energy_batch(session, api_url, api_key, records)
                if ok:
                    print(f"[energy] {len(records)}件upsert完了")
                else:
                    print(f"[energy] {len(records)}件送信失敗（次回周期でリトライされる）")

            await asyncio.sleep(ENERGY_INTERVAL_SEC)


# ---------------------------------------------------------------------------
# エントリポイント
# ---------------------------------------------------------------------------

async def main() -> None:
    email, password, _device_ips, api_url, api_key = load_config()
    device_configs = load_device_configs()
    await asyncio.to_thread(init_db)

    ip_cache = await asyncio.to_thread(load_ip_cache)

    devices: dict[str, TapoDevice] = {}
    for key, cfg in device_configs.items():
        # 起動時はキャッシュ済みIPを優先し、無ければ.envのip_hintをフォールバックにする
        ip = ip_cache.get(key, cfg.ip_hint)
        devices[key] = TapoDevice(key, ip, email, password, mac=cfg.mac)

    print(f"[main] 収集開始 devices={list(devices.keys())} api_url={api_url}")

    await asyncio.gather(
        power_poll_loop(devices),
        send_loop(api_url, api_key),
        energy_loop(devices, api_url, api_key),
    )


if __name__ == "__main__":
    try:
        asyncio.run(main())
    except KeyboardInterrupt:
        print("[main] 停止しました")
