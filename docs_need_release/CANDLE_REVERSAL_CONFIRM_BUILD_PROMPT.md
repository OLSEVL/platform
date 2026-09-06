# Промпт: собрать бота Candle Reversal + Confirm с нуля

Скопируй блок ниже целиком в другую модель. Промпт самодостаточный: без доступа к этому репозиторию можно собрать тот же бот **Candle Reversal + Confirm v3** (только bounce).

Источник логики: `CandleReversalLevelAnalyzer` / `CandleReversalLevelStrategy` (decision_version `candle_reversal_confirm_v3_level_bounce`).

---

```
Ты — опытный квант и программист бинарных опционов. Построй С НУЛЯ торгового бота «Candle Reversal + Confirm» (версия v3, только bounce). Не выдумывай другие стратегии (свип/рейд/поглощение/пробой не нужны). Не торгуй пробои. Реализуй полный пайплайн: данные → уровни → касание → подтверждение → CALL/PUT.

РЫНОК
- Бинарные опционы (Pocket Option / OTC FX). CALL = цена выше через expiry, PUT = ниже.
- Рабочий ТФ сигнала: закрытые M1. Подтверждение: M5. Уровни: M1 + M15 + H1.
- Экспирация по умолчанию 300 секунд (3–5 мин). Диапазон 120–600 с.
- Вход только на ЗАКРЫТЫХ свечах. Формирующаяся (partial) свеча не участвует в решении.
- Одна пара за скан. Мин. payout по умолчанию 82%. Пары без свежих котировок (stale > 45 с) пропускать.

ИДЕЯ ОДНОЙ ФРАЗОЙ
Сильный уровень = конфлюенс нескольких источников. Ждём касание цены зоны уровня. Если M5 уже закрылась ЗА уровнем — это пробой, ПРОПУСК. Если цена у уровня и есть подтверждение разворота (M5 rejection и/или N последних M1 в сторону отскока) — вход ПРОТИВ касания: от поддержки CALL, от сопротивления PUT.

ДАННЫЕ (контракт)
Каждая свеча: {time: unix_sec, open, high, low, close, volume}.
Нужно минимум:
- M1 closed: ≥ max(80, min_closed_bars), по умолчанию 200; грузить ~500
- M5: ≥ 20, брать 120
- M15: ≥ 5 для свингов, брать 80
- H1: ≥ 8 для bias, брать 12
Если ATR(M1,14)=0 или ATR(M5,14)=0 → нет сигнала.
M5/M15/H1 агрегируй из M1 (или из S5), если брокер не даёт готовые ТФ. Только закрытые бары.

ГЕОМЕТРИЯ СВЕЧИ (везде range = max(1e-8, high-low))
- bull: close > open
- bear: close < open
- body_ratio = |close-open| / range
- lower_wick_ratio = (min(open,close) - low) / range
- upper_wick_ratio = (high - max(open,close)) / range
ATR(period=14): среднее True Range по последним `period` барам.
TR = max(H-L, |H-prevClose|, |L-prevClose|). Игнорировать первый бар серии.

════════════════════════════════════════
ШАГ 0. КОНФИГ (дефолты — обязательны)
════════════════════════════════════════
count=500
min_closed_bars=200
expiry_sec=300
min_confidence=0.0
min_payout=82
stale_after_sec=45
min_level_strength=58          # 40..95
max_level_strength=0           # 0 = без потолка; 90 рекомендуется как потолок (90+ статистически хуже)
min_sources=1                  # 1..6
cluster_atr_mult_m5=0.28       # радиус склейки уровней
touch_atr_mult_m1=0.55         # зона касания (главный рычаг частоты)
require_h1_alignment=false     # CALL запрещён при H1=down, PUT при H1=up
block_h1_flat=false            # при H1=flat сделок нет
h1_flat_strength_extra=4       # если require_h1_alignment и H1=flat: нужен strength >= min+extra

# M5 rejection
m5_rejection_enabled=true
bounce_min_wick_ratio_m5=0.28  # 0.15..0.80
m5_rejection_require_direction=true  # CALL→бычья M5, PUT→медвежья

# M1 разворот
m1_reversal_enabled=true
m1_reversal_confirm_bars=1     # 1..3 последних закрытых M1
m1_reversal_min_wick_ratio=0.22
m1_reversal_min_body_ratio=0.25
m1_reversal_require_wick_dominance=true  # «своя» тень > противоположной
m1_reversal_wick_scope="last"  # "last" | "all"

ЖЁСТКО: нельзя выключить И M5, И M1 одновременно. Если оба false → всегда null (нет сигналов).

Пресеты:
- Агрессивный: M5=off, M1=1, touch=0.55+
- Сбалансированный (дефолт): M5+M1=1, wick M5=0.28, wick M1=0.22, scope=last
- Консервативный: M5 wick=0.35, M1=2–3, wick_scope=all

════════════════════════════════════════
ШАГ 1. СБОР СЫРЫХ УРОВНЕЙ
════════════════════════════════════════
Каждый сырой уровень: {price, kind: support|resistance|neutral, source, weight}.

Веса источников (0..1):
h1_swing=1.00
mxwll_1d=0.95
m15_swing=0.85
mxwll_4h=0.80
pa_dgt_sr=0.75
vp_poc=0.70
rs_sr=0.70
vp_vah=0.60
vp_val=0.60
three_br_sr=0.65

--- 1A. Swing H1 и M15 (фрактал 2+2) ---
На барах ТФ, индекс i от 2 до n-3:
  swing HIGH если high[i] >= high[i-1], high[i-2], high[i+1], high[i+2] → kind=resistance
  swing LOW  если low[i]  <= low[i-1],  low[i-2],  low[i+1],  low[i+2]  → kind=support
Взять последние 6 свингов каждого ТФ. source = h1_swing или m15_swing.
Цвет свечи фрактала не важен.

--- 1B. Maxwell rolling High/Low с M1 ---
Окно 4H = последние 240 M1. Окно 1D = последние 1440 M1 (если баров меньше — всё что есть, но 1D слабее без 1440).
В окне: max(high) → resistance (лейбл *H), min(low) → support (лейбл *L).
source: mxwll_4h или mxwll_1d. kind по H/L.

--- 1C. Price Action DGT S/R (Pine «S/R by DGT») ---
На M1:
volume_sma = SMA(volume, 89)
bull = close>open; bear = close<open
risingVol[i] = volume[i] >= volume[i-1]
lwst3[i] = min(low[i], low[i-1], low[i-2])   # i>=2
hst3[i]  = max(high[i], high[i-1], high[i-2])
Режим sr_source="volume" (дефолт):
  falling[i] = bear[i] AND bear[i-1] AND bear[i-2]
               AND volume[i] > volume_sma[i]
               AND risingVol[i] AND risingVol[i-1]
  rising[i]  = bull[i] AND bull[i-1] AND bull[i-2]
               AND volume[i] > volume_sma[i]
               AND risingVol[i] AND risingVol[i-1]
Если volume везде 0 (типичный OTC) — fallback sr_source="price":
  falling = 3 медвежьих подряд И close[i]<close[i-1]<close[i-2]
  rising  = 3 бычьих подряд И close[i]>close[i-1]>close[i-2]
Цена уровня: если falling → lwst3 якоря; если rising → hst3 якоря.
Якорь = последний бар, где falling|rising истинно.
При подряд одинаковых falling (или rising) заменять предыдущую линию, не копить дубли.
kind=neutral, source=pa_dgt_sr. Бери актуальные (последние) линии, не всю историю.

--- 1D. Volume Profile POC / VAH / VAL ---
Окно: все загруженные M1 (visible), либо последние 360 при fixed.
priceHighest = max(high), priceLowest = min(low) окна.
profile_levels=100, value_area_pct=68.
priceStep = (priceHighest-priceLowest)/100
Распредели volume бара пропорционально по бинам, которые бар пересекает:
  add = volume * (priceStep / max(high-low, epsilon)) в каждый пересечённый бин.
POC = бин с max volume. Цена POC = priceLowest + (pocLevel+0.5)*priceStep
Value Area: начиная с POC расширяй вверх/вниз, каждый шаг беря соседний бин с БОЛЬШИМ объёмом, пока сумма < 68% всего объёма.
VAH = priceLowest + (levelAbovePoc+1)*priceStep
VAL = priceLowest + levelBelowPoc * priceStep
kind=neutral. sources: vp_poc, vp_vah, vp_val.

--- 1E. Reversal S/R (TD-подобный 9-count) ---
На M1, i>=4:
  consecutiveDown = close[i] < close[i-4]
  если consecutiveDown: bScore = (bScore==9 ? 1 : bScore+1); sScore=0
  иначе: sScore = (sScore==9 ? 1 : sScore+1); bScore=0
  h9 = max(high[i-8..i]); l9 = min(low[i-8..i])
  если bScore==9: bResistance = h9  (сопротивление)
  если sScore==9: sSupport = l9     (поддержка)
  если close > bResistance → сбросить сопротивление
  если close < sSupport → сбросить поддержку
Активные уровни: source=rs_sr, kind=resistance|support.

--- 1F. Three Bar Reversal зоны ---
Базовый бычий 3BR (i — бар паттерна, нужны i-2,i-1,i):
  Бар A=i-2, B=i-1, C=i
  Bullish: low[B] < low[A] AND close[B] > open[B] AND close[C] > high[B]
           (медвежий заход → разворотная B с нижним выносом → C закрылась выше high B)
  Bearish: high[B] > high[A] AND close[B] < open[B] AND close[C] < low[B]
После подтверждения зона:
  bull zone: price_low = low паттерна (дно B), price_high = high подтверждающей структуры; mid = (hi+lo)/2; kind=support; source=three_br_sr
  bear zone: зеркало; kind=resistance
Бери последние 1–3 живые зоны. Если паттерн сломан (цена ушла сквозь зону против неё) — зону не использовать.

Если какой-то источник не из чего посчитать — просто пропусти его. Бот должен работать даже на одних swing H1/M15 + VP + Maxwell.

════════════════════════════════════════
ШАГ 2. КЛАСТЕРИЗАЦИЯ
════════════════════════════════════════
cluster_radius = cluster_atr_mult_m5 * ATR(M5,14)
Отсортируй сырые уровни по price ASC.
Жадный merge: если |price_new - cluster.price| <= radius — влить:
  cluster.price = (price*count + new) / (count+1)   # среднее
  count++
  sources[source]=true
  weight_sum += weight
  support_votes++ / resistance_votes++ по kind
Иначе новый кластер.
После склейки:
  kind = support если support_votes > resistance_votes
       = resistance если resistance_votes > support_votes
       = иначе neutral
  sourceCount = число уникальных источников
  maxSources = 10 (число ключей весов)
  strength0 = min(100,
      28 * (sourceCount / maxSources)
    + 24 * min(1, weight_sum / 2)
    + 20 * min(1, sourceCount / 2)
    + 28 * min(1, count / 3)
  )

════════════════════════════════════════
ШАГ 3. КАСАНИЯ И ДИСТАНЦИЯ
════════════════════════════════════════
touch_radius = touch_atr_mult_m1 * ATR(M1,14)
price = close последней закрытой M1
Для каждого кластера:
  distance = |price - level|
  touches = сколько M1 из последних 120 пересекают зону уровня:
    high >= level - touch_radius AND low <= level + touch_radius
  strength = min(100, strength0 + 10 * min(1, touches/5))

Сортировка кластеров: strength DESC, при равенстве distance ASC.

Выбрать ПЕРВЫЙ (ближайший сильный), у которого ВСЕ:
  strength >= min_level_strength
  если max_level_strength > 0: strength <= max_level_strength
  число источников >= min_sources
  distance <= touch_radius
Иначе null.

════════════════════════════════════════
ШАГ 4. H1 BIAS
════════════════════════════════════════
Если H1 баров < 8 → bias="flat"
Иначе последние 12 H1 (или сколько есть ≥8):
  ups = число close>open; downs = close<open
  up   если ups   >= downs + 3
  down если downs >= ups + 3
  иначе flat

Фильтры:
  если block_h1_flat AND bias==flat AND len(H1)>=10 → null
  если require_h1_alignment AND bias==flat AND len(H1)>=10
       AND strength < min_level_strength + h1_flat_strength_extra → null

════════════════════════════════════════
ШАГ 5. РЕЖИМ: ТОЛЬКО BOUNCE
════════════════════════════════════════
lastM5.close vs level, kind кластера, touch_radius:
  resistance AND close > level + touch_radius*0.15 → BREAKOUT → null
  support    AND close < level - touch_radius*0.15 → BREAKOUT → null
  neutral    AND |close-level| > touch_radius*0.20 → BREAKOUT → null
иначе BOUNCE. Пробои не торгуются никогда.

════════════════════════════════════════
ШАГ 6. СТОРОНА СДЕЛКИ
════════════════════════════════════════
lastM1.close = price, level, kind:
  CALL если kind==support ИЛИ (kind==neutral AND price >= level)
  PUT  если kind==resistance ИЛИ (kind==neutral AND price <= level)
иначе null.

если require_h1_alignment:
  CALL + H1=down → null
  PUT  + H1=up   → null

════════════════════════════════════════
ШАГ 7. ПОДТВЕРЖДЕНИЕ (хотя бы один блок включён)
════════════════════════════════════════

7A. M5 rejection (если m5_rejection_enabled)
Последняя закрытая M5:
  CALL: lower_wick_ratio >= bounce_min_wick_ratio_m5
  PUT:  upper_wick_ratio >= bounce_min_wick_ratio_m5
если m5_rejection_require_direction:
  CALL → M5 close >= open (бычья, doji ок из-за >=)
  PUT  → M5 close <= open
Иначе null.

7B. M1 reversal (если m1_reversal_enabled)
Взять последние N = m1_reversal_confirm_bars закрытых M1 (N=1..3).
Для КАЖДОЙ свечи блока:
  CALL → должна быть bull; PUT → bear
  body_ratio >= m1_reversal_min_body_ratio   (фильтр доджи)
Правила тени применяются:
  wick_scope="last" → только к ПОСЛЕДНЕЙ свече блока
  wick_scope="all"  → к каждой
На проверяемых:
  side_wick = CALL? lower_wick : upper_wick
  opp_wick  = CALL? upper_wick : lower_wick
  side_wick >= m1_reversal_min_wick_ratio
  если require_wick_dominance: side_wick > opp_wick
Иначе null.

Оба блока включены → нужны ОБА (логическое AND). Один выключен → достаточно включённого.

════════════════════════════════════════
ШАГ 8. CONFIDENCE И ВЫХОД
════════════════════════════════════════
confidence = 0.56 + min(0.22, (strength-50)/200)
           + min(0.06, touches * 0.012)
           + 0.03
           + (0.02 если H1 bias up или down)
clamp в [0.58, 0.88], округлить до 4 знаков.
Если confidence < min_confidence (из конфига) — снаружи отсечь.

Вернуть сигнал:
{
  side: "CALL"|"PUT",
  confidence: float,
  expiry_sec: int,
  reasons_short: [
    "Сильный уровень {поддержки|сопротивления|нейтральный} {price} (сила {N}, {sources}).",
    "Режим: отскок; сторона {CALL|PUT}.",
    опционально "M5 rejection (тень X%).",
    опционально "M1: N свечи подтверждают разворот.",
    "H1: {рост|падение|флэт}."
  ],
  features: {
    level_price, level_strength, level_kind, level_sources, level_touches,
    setup_type: "level_bounce_reversal",
    entry_regime: "bounce",
    h1_bias, atr_m1, atr_m5, distance_to_level,
    m5_rejection_enabled, m1_reversal_enabled, m1_reversal_confirm_bars,
    m1_reversal_wick_scope, signal_side, m5_reversal_wick?
  },
  decision_version: "candle_reversal_confirm_v3_level_bounce"
}
Нет сетапа → null. Никогда не входить «голым касанием» без M5 и/или M1 подтверждения.

RUNTIME
- Скан по списку инструментов с payout >= min_payout.
- Dedupe: один сигнал на пару на окно expiry_sec, ключ = side + level_price(5 знаков). Не дублировать тот же отскок.
- Не мартингейл, не усреднение, не переворот.
- Не торговать, пока не закрылась сигнальная M1 (и M5, если M5-фильтр включён).
- Paper/live — как удобно, но логика входа одна.

ЧТО НЕ ЯВЛЯЕТСЯ СЕТАПОМ
- Пробой (M5 close за уровнем) — никогда.
- Касание без rejection/разворота.
- Оба подтверждения выключены.
- Слабый уровень (strength < min или источников < min_sources).
- Цена далеко от уровня (distance > touch_radius).
- Недостаточно баров / нулевой ATR / stale котировки / низкий payout.
- H1 против входа, если включён require_h1_alignment.
- Вход до close.

ПРИЁМОЧНЫЕ ТЕСТЫ (обязательно напиши unit-тесты)
1) M5=off AND M1=off → всегда null.
2) Синтетический support: цена у уровня, M5 бычья с нижней тенью 40%, последняя M1 бычья с нижней тенью 30% > верхней, body 40% → CALL.
3) То же у resistance → PUT.
4) M5 close далеко выше resistance → null (breakout).
5) M1 doji (body_ratio < min) → null.
6) wick_scope=last: первая из 2 M1 без тени, последняя с тенью → OK; scope=all → null.
7) Кластер: два уровня ближе radius сливаются, strength растёт с числом источников.
8) distance > touch_radius → null.
9) require_h1_alignment + H1 down + CALL → null.
10) Недостаточно M1 (< min_closed_bars) → null.

РЕАЛИЗАЦИЯ
- Язык на твоё усмотрение (Python предпочтителен, если нет ограничений). Чистые функции, без скрытого состояния.
- Модули: candles/ohlc, atr, levels (каждый источник отдельно), cluster, bounce_entry, scanner.
- Комментарии на русском у нетривиальной логики.
- CLI: прогон по CSV OHLC (колонки time,open,high,low,close,volume) → печатает сигналы.
- Не подключай LLM к решению входа. Не рисуй TradingView. Не копируй чужой брокерский API, если его нет — сделай адаптер «подали свечи → получили decision».
- Не упрощай до «пинбар на M1». Уровень + конфлюенс + bounce + confirm — обязательны.

Сначала выпиши план модулей и формулы, затем пиши код, затем тесты. После тестов дай пример: 3 бара M5 + пачка M1 у support, пошаговый разбор почему CALL.
```
