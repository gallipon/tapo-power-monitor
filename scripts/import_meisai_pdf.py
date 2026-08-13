#!/usr/bin/env python3
"""くらしTEPCO web の「電気料金等請求書」PDF から料金モデル用の SQL を書き出す。

使い方:
    python scripts/import_meisai_pdf.py meisai_xxx_202608.pdf > billing_202608.sql
    python scripts/import_meisai_pdf.py --check meisai_*.pdf     # 検算だけ実行

必要なもの: pdftotext（poppler-utils）

------------------------------------------------------------------------------
なぜ「ラベルの隣の数字を読む」実装にしていないか
------------------------------------------------------------------------------
この PDF を pdftotext -layout に通すと、料金内訳の**ラベルと金額が1行ずれる**。
実物の出力（2026年08月分, 374kWh）:

    基本料金        (うち消費税等相当額 1,099円)
    電力量料金                      1,247円00銭     ← これは基本料金の額
    ・1段料金                                       ← 空
    ・2段料金                      3,576円00銭      ← これは1段料金 (120×29.80)
    ・3段料金                      6,552円00銭      ← これは2段料金 (180×36.40)
    ・燃料費調整額                  2,996円26銭      ← これは3段料金 (74×40.49)
    再エネ発電賦課金               ‐3,840円98銭      ← これは燃料費調整額
                                   1,563円00銭      ← これは再エネ賦課金

同じ行のラベルを信じると**段階を1つずつ取り違える**。しかも使用量が300kWh以下の月は
3段料金の行自体が無くなるため、ずれ方が月によって変わる。

そこで、金額とラベルの対応づけは一切行わない。

  - 期間・使用量・請求金額は、ラベルと値が同じ行に出る（ここは信用できる）
  - 単価は**検算で同定する**。使用量を掛けて内訳のどれかと一致するものがその単価
  - 内訳の合計が請求金額と一致することを最後に必ず確認する

読み違えたら金額が合わなくなるので、静かに間違ったデータが入ることがない。
"""

from __future__ import annotations

import argparse
import re
import subprocess
import sys
from dataclasses import dataclass
from datetime import date
from decimal import Decimal
from pathlib import Path

# 検針票は U+2010 HYPHEN を負号に使う。全角マイナスや通常のハイフンも一応許容する。
MINUS = "‐−－-"
MONEY_RE = re.compile(rf"(?P<sign>[{MINUS}]?)(?P<yen>\d[\d,]*)円(?:(?P<sen>\d{{1,2}})銭)?")

# 内訳の合計と請求金額の許容誤差（円未満切捨のぶん）
TOTAL_TOLERANCE = Decimal("1")


@dataclass
class Meisai:
    """検針票1枚から読み取った値。"""

    ym: date                      # 「2026年08月」→ 2026-08-01
    period_start: date
    period_end: date
    kwh: int
    amount_yen: int
    next_reading_date: date | None
    fuel_adj_this_month: Decimal  # 燃料費調整 当月分（円/kWh）
    fuel_adj_next_month: Decimal | None
    renewable: Decimal            # 再エネ発電賦課金（円/kWh）


def run_pdftotext(pdf: Path) -> str:
    try:
        out = subprocess.run(
            ["pdftotext", "-layout", "-enc", "UTF-8", str(pdf), "-"],
            check=True, capture_output=True,
        )
    except FileNotFoundError:
        sys.exit("pdftotext が見つかりません。poppler-utils を入れてください。")
    except subprocess.CalledProcessError as e:
        sys.exit(f"pdftotext が失敗しました: {e.stderr.decode('utf-8', 'replace')}")
    # 全角スペースを潰しておくと以降の正規表現が素直に書ける
    return out.stdout.decode("utf-8").replace("　", " ")


def money_tokens(text: str) -> list[Decimal]:
    """「1,234円56銭」形式の金額をすべて拾う。"""
    values = []
    for m in MONEY_RE.finditer(text):
        v = Decimal(m.group("yen").replace(",", ""))
        if m.group("sen"):
            v += Decimal(m.group("sen")) / 100
        if m.group("sign"):
            v = -v
        values.append(v)
    return values


def breakdown_amounts(lines: list[str]) -> list[Decimal]:
    """料金内訳（基本料金〜合計の手前）の金額を拾う。

    「(うち消費税等相当額 1,099円)」のような括弧内・「うち」を含む行は
    内訳の再掲であって加算対象ではないので除外する。
    """
    start = end = None
    for i, ln in enumerate(lines):
        if start is None and "基本料金" in ln:
            start = i
        elif start is not None and ("合計" in ln or "託送料金" in ln):
            end = i
            break
    if start is None:
        sys.exit("料金内訳が見つかりません（PDFの書式が変わった可能性があります）")

    amounts: list[Decimal] = []
    for ln in lines[start : end if end is not None else len(lines)]:
        if "うち" in ln:
            continue
        amounts.extend(money_tokens(re.sub(r"[（(][^）)]*[）)]", "", ln)))
    return amounts


def find_date(text: str, label: str) -> tuple[int, int] | None:
    """「label ... 9月10日」から (月, 日) を取る。"""
    m = re.search(rf"{label}\s*(\d{{1,2}})月\s*(\d{{1,2}})日", text)
    return (int(m.group(1)), int(m.group(2))) if m else None


def resolve_year(ym: date, month: int, *, before: bool) -> int:
    """月だけ分かっている日付の年を決める。

    before=True  … 検針期間のように ym 以前を指すもの（1月分の12月など）
    before=False … 次回検針日のように ym 以降を指すもの（12月分の1月など）
    """
    if before and month > ym.month:
        return ym.year - 1
    if not before and month < ym.month:
        return ym.year + 1
    return ym.year


def solve_unit_price(
    candidates: list[Decimal], amounts: list[Decimal], kwh: int, *, floor: bool
) -> tuple[Decimal, Decimal] | None:
    """単価候補のうち、使用量を掛けると内訳のどれかに一致するものを返す。

    燃料費調整額は円銭まで一致する。再エネ賦課金は円未満切捨で表示されるため
    floor=True で比較する。戻り値は (単価, 一致した内訳の金額)。
    """
    for rate in candidates:
        product = rate * kwh
        if floor:
            product = product.to_integral_value(rounding="ROUND_FLOOR")
        for amount in amounts:
            if abs(product - amount) < Decimal("0.01"):
                return rate, amount
    return None


def parse(pdf: Path) -> Meisai:
    text = run_pdftotext(pdf)
    lines = text.splitlines()

    m = re.search(r"(\d{4})年\s*(\d{1,2})月\s*$", text, re.MULTILINE) or re.search(
        r"^\s*(\d{4})年(\d{2})月\s", text, re.MULTILINE
    )
    if not m:
        sys.exit(f"{pdf.name}: 請求年月が読み取れません")
    ym = date(int(m.group(1)), int(m.group(2)), 1)

    m = re.search(r"ご使用期間\s*(\d{1,2})月\s*(\d{1,2})日\s*[~～]\s*(\d{1,2})月\s*(\d{1,2})日", text)
    if not m:
        sys.exit(f"{pdf.name}: ご使用期間が読み取れません")
    sm, sd, em, ed = (int(g) for g in m.groups())
    period_end = date(resolve_year(ym, em, before=True), em, ed)
    start_year = period_end.year - 1 if sm > em else period_end.year
    period_start = date(start_year, sm, sd)

    m = re.search(r"ご使用量\s*([\d,]+)", text) or re.search(r"使用電力量\s*([\d,]+)", text)
    if not m:
        sys.exit(f"{pdf.name}: ご使用量が読み取れません")
    kwh = int(m.group(1).replace(",", ""))

    m = re.search(r"請求金額\s*([\d,]+)円", text)
    if not m:
        sys.exit(f"{pdf.name}: 請求金額が読み取れません")
    amount_yen = int(m.group(1).replace(",", ""))

    # 「検針月日」の隣に出るが、値は実際には次回検針予定日（ラベルが1行ずれるため）。
    # 検針期間の終わりより後の日付であることを確かめて採用する。
    next_reading = None
    md = find_date(text, "検針月日") or find_date(text, "次回検針予定日")
    if md:
        cand = date(resolve_year(ym, md[0], before=False), md[0], md[1])
        if cand > period_end:
            next_reading = cand

    amounts = breakdown_amounts(lines)

    total = sum(amounts)
    if abs(total - Decimal(amount_yen)) > TOTAL_TOLERANCE:
        sys.exit(
            f"{pdf.name}: 内訳の合計 {total} 円が請求金額 {amount_yen} 円と一致しません。\n"
            "PDFの書式が変わったか、読み取りに失敗しています。"
        )

    # 単価候補: 1kWhあたりの金額なので小さい値だけを対象にする
    rates = [v for v in money_tokens(text) if abs(v) < 100 and v == v.quantize(Decimal("0.01"))]

    solved = solve_unit_price(rates, amounts, kwh, floor=False)
    if not solved:
        sys.exit(f"{pdf.name}: 燃料費調整の単価を同定できません")
    fuel_adj, fuel_amount = solved

    solved = solve_unit_price(
        [r for r in rates if r > 0], [a for a in amounts if a != fuel_amount], kwh, floor=True
    )
    if not solved:
        sys.exit(f"{pdf.name}: 再エネ発電賦課金の単価を同定できません")
    renewable, _ = solved

    # 翌月分の燃調。検針票には当月分・翌月分と、その差額が載る。
    # 「当月 + 差 = 翌月」が成り立つ組を探す。
    fuel_next = None
    for cand in rates:
        if cand == fuel_adj:
            continue
        if any(abs(fuel_adj + d - cand) < Decimal("0.005") for d in rates if d != cand):
            fuel_next = cand
            break

    return Meisai(
        ym=ym,
        period_start=period_start,
        period_end=period_end,
        kwh=kwh,
        amount_yen=amount_yen,
        next_reading_date=next_reading,
        fuel_adj_this_month=fuel_adj,
        fuel_adj_next_month=fuel_next,
        renewable=renewable,
    )


def add_month(d: date) -> date:
    return date(d.year + 1, 1, 1) if d.month == 12 else date(d.year, d.month + 1, 1)


def summary(m: Meisai) -> str:
    nxt = (
        f"  燃調 翌月({add_month(m.ym):%Y-%m})  {m.fuel_adj_next_month:>8} 円/kWh\n"
        if m.fuel_adj_next_month is not None
        else "  燃調 翌月            （読み取れず）\n"
    )
    return (
        f"{m.ym:%Y年%m月}分\n"
        f"  検針期間             {m.period_start} 〜 {m.period_end}\n"
        f"  次回検針予定日       {m.next_reading_date or '（読み取れず）'}\n"
        f"  使用量               {m.kwh:>8} kWh\n"
        f"  請求金額             {m.amount_yen:>8} 円   ← 内訳の合計と一致\n"
        f"  燃調 当月            {m.fuel_adj_this_month:>8} 円/kWh\n"
        + nxt
        + f"  再エネ               {m.renewable:>8} 円/kWh\n"
    )


def to_sql(items: list[Meisai], ampere: int | None) -> str:
    monthly: dict[date, Decimal] = {}
    for m in items:
        monthly[m.ym] = m.fuel_adj_this_month
        if m.fuel_adj_next_month is not None:
            monthly.setdefault(add_month(m.ym), m.fuel_adj_next_month)
    renewable = {m.ym: m.renewable for m in items}
    default_renewable = items[-1].renewable

    out = ["-- import_meisai_pdf.py が生成。検針票の実値なので公開リポジトリには入れないこと。", ""]

    out.append("INSERT INTO tariff_monthly (ym, fuel_adj_yen_per_kwh, renewable_yen_per_kwh) VALUES")
    rows = [
        f"    ('{ym:%Y-%m-%d}', {fuel:>7}, {renewable.get(ym, default_renewable)})"
        for ym, fuel in sorted(monthly.items())
    ]
    out.append(",\n".join(rows))
    out.append("ON DUPLICATE KEY UPDATE fuel_adj_yen_per_kwh = VALUES(fuel_adj_yen_per_kwh),")
    out.append("                        renewable_yen_per_kwh = VALUES(renewable_yen_per_kwh);")
    out.append("")

    out.append(
        "INSERT INTO billing_period "
        "(ym, period_start, period_end, kwh, amount_yen, ampere, next_reading_date) VALUES"
    )
    rows = []
    for m in sorted(items, key=lambda x: x.ym):
        nxt = f"'{m.next_reading_date:%Y-%m-%d}'" if m.next_reading_date else "NULL"
        amp = str(ampere) if ampere is not None else "NULL"
        rows.append(
            f"    ('{m.ym:%Y-%m-%d}', '{m.period_start:%Y-%m-%d}', '{m.period_end:%Y-%m-%d}', "
            f"{m.kwh}, {m.amount_yen}, {amp}, {nxt})"
        )
    out.append(",\n".join(rows))
    out.append("ON DUPLICATE KEY UPDATE period_start = VALUES(period_start), period_end = VALUES(period_end),")
    out.append("                        kwh = VALUES(kwh), amount_yen = VALUES(amount_yen),")
    out.append("                        ampere = VALUES(ampere), next_reading_date = VALUES(next_reading_date);")
    return "\n".join(out) + "\n"


def main() -> None:
    p = argparse.ArgumentParser(description="検針票PDFから料金モデル用のSQLを生成する")
    p.add_argument("pdf", nargs="+", type=Path)
    p.add_argument("--check", action="store_true", help="SQLを出力せず読み取り結果の確認だけ行う")
    p.add_argument("--ampere", type=int, default=40, help="契約アンペア（既定: 40）")
    args = p.parse_args()

    # Windows の既定は cp932 で、要約の日本語が化ける
    for stream in (sys.stdout, sys.stderr):
        if hasattr(stream, "reconfigure"):
            stream.reconfigure(encoding="utf-8")

    items = [parse(pdf) for pdf in args.pdf]
    for m in items:
        print(summary(m), file=sys.stderr)

    if not args.check:
        print(to_sql(items, args.ampere), end="")


if __name__ == "__main__":
    main()
