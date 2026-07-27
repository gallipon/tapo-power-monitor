-- Tapo P110M 電力データ収集システム - 電気料金モデル
-- 実行例: mysql -u root -p < setup_tariff.sql
--
-- プラグの消費電力量に「平均単価」を掛けても実際の電気代にはならない。
--   - 基本料金は使用量に関わらず定額（プラグの消費とは無関係）
--   - 電力量料金は段階制で、エアコンを使った分は一番高い段階に乗る
--   - 燃料費調整額・再エネ発電賦課金は kWh 比例だが単価が月ごとに変わる
-- そのため「そのプラグが無かったら請求がいくら減るか」= 増分コストで計算する。
-- 段階を判定するには家全体の使用量が要る（house_usage_daily / billing_period）。
--
-- 単価は全て税込。検針票の表記（円・銭）に合わせて小数2桁で保持する。
--
-- 注意: kwh 系の列は INT UNSIGNED なので、段階の切り出しで `kwh - 300` のような引き算を
-- そのまま書くと 300 未満のときに BIGINT UNSIGNED のアンダーフローでエラーになる。
-- 必ず CAST(kwh AS SIGNED) してから引くこと。

USE tapo;

-- 基本料金（契約アンペア別）。料金改定に備えて valid_from を持つ。
CREATE TABLE IF NOT EXISTS tariff_base (
    valid_from  DATE            NOT NULL,
    ampere      SMALLINT UNSIGNED NOT NULL,
    yen         DECIMAL(8,2)    NOT NULL,
    PRIMARY KEY (valid_from, ampere)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 電力量料金の段階単価。upto_kwh はその段階の上限（NULL = 上限なし）。
CREATE TABLE IF NOT EXISTS tariff_tier (
    valid_from  DATE            NOT NULL,
    tier_no     TINYINT UNSIGNED NOT NULL,
    upto_kwh    INT UNSIGNED    NULL,
    yen_per_kwh DECIMAL(6,2)    NOT NULL,
    PRIMARY KEY (valid_from, tier_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 月ごとに変わる kWh 比例の単価。検針月（「令和8年7月分」なら 2026-07-01）で引く。
-- 燃料費調整額はマイナスになることがある（2026年7月時点は -7円19銭）。
CREATE TABLE IF NOT EXISTS tariff_monthly (
    ym                     DATE         NOT NULL,
    fuel_adj_yen_per_kwh   DECIMAL(6,2) NOT NULL,
    renewable_yen_per_kwh  DECIMAL(6,2) NOT NULL,
    PRIMARY KEY (ym)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 検針票の実績。段階判定は暦月ではなく「検針期間」単位なので、期間の境界を持つ。
-- 推定と実請求額の突き合わせにも使う。1ヶ月1行で、入力は任意。
-- next_reading_date は検針票の「次回検針予定日」。進行中の期間の終わりを知るために使う
-- （検針票の使用期間は「前回検針日〜今回検針日の前日」なので、進行中の期間は
--   前期の period_end の翌日から next_reading_date の前日まで）。
CREATE TABLE IF NOT EXISTS billing_period (
    ym                DATE            NOT NULL,  -- 「令和8年7月分」→ 2026-07-01
    period_start      DATE            NOT NULL,
    period_end        DATE            NOT NULL,
    kwh               INT UNSIGNED    NOT NULL,
    amount_yen        INT UNSIGNED    NOT NULL,
    ampere            SMALLINT UNSIGNED NULL,
    next_reading_date DATE            NULL,
    PRIMARY KEY (ym)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 家全体の日別使用量（くらしTEPCO web の一括ダウンロードCSVから投入）。
-- プラグ3台では家全体を測れないため、段階判定にはこれが要る。
CREATE TABLE IF NOT EXISTS house_usage_daily (
    `day`   DATE           NOT NULL,
    kwh     DECIMAL(10,3)  NOT NULL,
    PRIMARY KEY (`day`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- 初期値（東京電力エナジーパートナー 従量電灯B の例）
--
-- **自分の契約・検針票の値に置き換えること。** 下記は動作確認済みの一例にすぎない。
--
-- 単価の求め方: 検針票の「料金内訳」の各行を単価 × kWh に分解すると、段階単価と
-- 燃料費調整の単価が逆算できる。内訳の合計が請求予定金額と一致すれば読み取りは正しい。
--
--     基本料金（契約アンペア）           ← 検針票にそのまま載っている
--     1段料金 ÷ 120kWh                  = 第1段階の単価
--     2段料金 ÷ (使用量 - 120)          = 第2段階の単価   ※300kWh以下の月の場合
--     燃料費調整額 ÷ 使用量             = 燃調の単価（検針票の「燃料費調整のお知らせ」と一致する）
--     再エネ発電賦課金 ÷ 使用量         = 再エネの単価（下記のとおり円未満切捨のため範囲でしか出ない）
--
-- 検算はダッシュボードの「電気代」セクションが自動で表示する（api/data.php の
-- bill_yen と billing_period.amount_yen の比較）。一致していれば単価は正しい。
--
-- 第3段階(40.49)は300kWhを超えた月の検針票が無いと検証できない。従量電灯Bの標準単価を
-- 入れてあるが、超過した月が来たら検算が通るか確認すること。
-- valid_from は料金改定日ではなく「この単価を適用したい期間の開始」として置いている。
-- 収集開始より前の期間にこの単価を当てるのは近似であり、当時の実単価とはずれる。
-- ---------------------------------------------------------------------------

INSERT INTO tariff_base (valid_from, ampere, yen) VALUES
    ('2025-01-01', 40, 1247.00)
ON DUPLICATE KEY UPDATE yen = VALUES(yen);

INSERT INTO tariff_tier (valid_from, tier_no, upto_kwh, yen_per_kwh) VALUES
    ('2025-01-01', 1,  120, 29.80),
    ('2025-01-01', 2,  300, 36.40),
    ('2025-01-01', 3, NULL, 40.49)
ON DUPLICATE KEY UPDATE upto_kwh = VALUES(upto_kwh), yen_per_kwh = VALUES(yen_per_kwh);

-- 燃料費調整は検針票の「燃料費調整のお知らせ」に当月分と翌月分の2つが載るので、
-- 検針票が届くたびに2行ずつ先まで埋められる。前月の検針票の「翌月分」と当月の検針票の
-- 「当月分」が一致するはずなので、読み取りのクロスチェックになる。
--
-- 再エネ賦課金は年度単位（5月検針分〜翌年4月検針分）で改定される。検針票では円未満が
-- 切り捨てられるため単価そのものは載っていないが、複数月の制約を重ねると絞り込める。
-- 使用量 K kWh に対する表示額が Y 円なら Y ≦ K・x < Y+1、つまり Y/K ≦ x < (Y+1)/K。
-- 月をまたいで共通範囲を取れば小数2桁まで確定できる。
--
-- 下記は一例。**自分の検針票の値に置き換えること。**
INSERT INTO tariff_monthly (ym, fuel_adj_yen_per_kwh, renewable_yen_per_kwh) VALUES
    ('2026-06-01',  -7.30, 4.18),
    ('2026-07-01',  -7.19, 4.18),
    ('2026-08-01', -10.27, 4.18)
ON DUPLICATE KEY UPDATE fuel_adj_yen_per_kwh = VALUES(fuel_adj_yen_per_kwh),
                        renewable_yen_per_kwh = VALUES(renewable_yen_per_kwh);

-- 検針票の実績。段階の判定は暦月ではなく検針期間単位なので、期間の境界ごと転記する。
-- 実際の使用量・請求額は個人情報なのでリポジトリには入れない（下記は書式の例）。
-- 検針票が届いたらこの形で投入すること。手順は docs/deploy.md のフェーズ4を参照。
--
-- INSERT INTO billing_period (ym, period_start, period_end, kwh, amount_yen, ampere, next_reading_date) VALUES
--     ('2026-07-01', '2026-06-11', '2026-07-12', 250, 8800, 40, '2026-08-12')
-- ON DUPLICATE KEY UPDATE period_start = VALUES(period_start), period_end = VALUES(period_end),
--                         kwh = VALUES(kwh), amount_yen = VALUES(amount_yen), ampere = VALUES(ampere),
--                         next_reading_date = VALUES(next_reading_date);

-- tapo_app には setup.sql で `GRANT SELECT, INSERT, UPDATE ON tapo.*` を与えてあるため
-- 追加の権限付与は不要。
