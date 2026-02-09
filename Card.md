Принял. Я реально **пробежал весь архив** и разложил проект “по косточкам”: **что за что отвечает, какие файлы связаны, какие поля где нужны, какие функции кому отдают данные, и как всё течёт по пайплайну**. Ниже это описание в формате “карта проекта”, чтобы ты в любой момент мог ткнуть пальцем: *«вот это где?»*.

---

## 0) Каркас проекта (что это за приложение)

Это Laravel-приложение с:

* **Auth/Settings** на базе **Laravel Fortify + Livewire** (страницы логина/регистрации/2FA/профиль/настройки).
* **Dashboard** (за `auth + verified`) на Blade + Livewire + Filament (Tables/Widgets) для вывода торговых таблиц и KPI.
* **Trading Engine** (виртуальные сделки) + **Market Data** (quotes+ свечи) с TwelveData.

Точка входа UI:

* `routes/web.php` → `/dashboard` → `resources/views/dashboard.blade.php`

Планировщик:

* `routes/console.php` (Laravel Scheduler) каждые 5 минут гоняет market+trading.

---

## 1) Данные и таблицы БД (модели, поля, что для чего)

### 1.1 `symbols` → модель `App\Models\Symbol`

**Миграция:** `2026_02_03_122450_create_symbols_table.php`
**Поля:**

* `code` (unique) — ключ символа (`EURUSD`, `GBPUSD`…)
* `is_active` — участвует ли в синке/торговле
* `sort` — порядок на UI
* `point_size` — размер “пункта” (важнейшее для risk/SL/TP в points)
* `price_decimals` — для форматирования цены

**Связи:**

* `Symbol::quotes()` → `hasOne(SymbolQuote)` по `symbol_code = code`
* `Symbol::candles()` → `hasMany(Candle)` по `symbol_code = code`

---

### 1.2 `symbol_quotes` → модель `App\Models\SymbolQuote`

**Миграция:** `2026_02_03_122455_create_symbol_quotes_table.php`
**Поля:**

* `symbol_code` (unique) — тот же код, что в symbols
* `price` — последняя цена
* `source` — источник (`twelvedata`, `provider_error`, раньше мог быть binance и т.д.)
* `pulled_at` — когда реально тянули
* `updated_at` — стандартное

**Назначение:**

* единственный “живой heartbeat” цены для PnL и закрытия сделок.

---

### 1.3 `candles` → модель `App\Models\Candle`

**Миграция:** `2026_02_03_122502_create_candles_table.php`
**Поля:**

* `symbol_code`
* `timeframe_code` (`5m`, `15m`, `30m`, `1h`, `4h`, `1d`)
* `open_time_ms` (**главное поле хронологии**, unique в паре)
* `open/high/low/close`
* `volume` (nullable)
* `close_time_ms` (nullable)

**Ключевое:**

* уникальность: `unique(symbol_code, timeframe_code, open_time_ms)`
* индекс: `index(symbol_code, timeframe_code, open_time_ms)`
* везде сортировка должна быть через `open_time_ms`, **id свечи не существует как “время”**.

---

### 1.4 `trades` → модель `App\Models\Trade`

**Миграции:**

* `2026_02_02_111143_create_trades_table.php`
* `2026_02_07_225824_add_risk_management_to_trades.php`

**Поля:**

* идентификаторы: `symbol_code`, `timeframe_code`, `side`, `status`
* timestamps: `opened_at`, `closed_at`
* цены: `entry_price`, `exit_price`
* результаты: `realized_points`, `unrealized_points`
* `meta` (json) — огромная “память” причины/решения/риск-данных/exit-stop и т.п.
* risk: `stop_loss_points`, `take_profit_points`, `max_hold_minutes`

**Касты:**

* `status` кастится в enum `TradeStatus` (`open/closed`)
* `meta` → array

---

### 1.5 `trade_monitors` → модель `App\Models\TradeMonitor`

**Миграция:** `2026_02_08_033613_create_trade_monitors_table.php`
**Поля:**

* `symbol_code`, `timeframe_code` (unique пара)
* `expectation` (text) — человеко-описание статуса/ожидания
* `open_trade_id` (nullable) — если по этому symbol+tf есть открытая сделка

**Смысл:**

* это “таблица наблюдения”: **по каждому символу и каждому ТФ** хранится текст того, *что система ждёт*, даже если сделок нет.

---

## 2) Контракты и DI (кто что обещает)

### `App\Contracts\MarketDataProvider`

Методы:

* `source(): string`
* `lastPrice(symbol): ?float`
* `candles(symbol, timeframe, limit): array{open_time_ms, ohlc, volume, close_time_ms}`

### `App\Contracts\FxQuotesProvider`

Методы:

* `source(): string`
* `batchQuotes(array codes): array code=>price`
* `isRateLimited(Throwable, ?Response): bool`

### `App\Contracts\StrategySettingsRepository`

Метод:

* `get(): array` (таймфреймы, веса, threshold, flat, entry, risk, points)

**Реализация настроек:**

* `App\Services\Trading\ConfigStrategySettingsRepository`

    * читает `config/trading.php` и нормализует дефолты.

---

## 3) Конфиг стратегии и риска (один файл, который рулит всем)

`config/trading.php`

### Strategy

* `timeframes`: `['5m','15m','30m','1h','4h','1d']`
* `weights`: веса по ТФ
* `total_threshold`: порог “есть перевес/нет”
* `flat.lookback_candles`
* `flat.range_pct_threshold`
* `entry.use_current_candle` (присутствует, но текущая логика решения всё равно стабилизирована через “last closed”)

### Risk

* SL/TP в points или процентах
* `max_hold_minutes`
* trailing stop: enabled + activation/distance (points или percent)

### Points normalization

* `points.mode` сейчас стоит `tick` (в коде сделки считаются в points через point_size)

---

## 4) Провайдеры рынка (TwelveData) и синк

### 4.1 `App\Services\MarketData\TwelveDataMarketDataProvider` (Candles + одиночный lastPrice)

* `lastPrice()` → GET `/price` (один символ)
* `candles()` → GET `/time_series` (interval mapping)

    * `1d` мапится в **`1day`** (фикс уже есть)
    * парс datetime в UTC → `open_time_ms`
    * `close_time_ms = open_time_ms + timeframeMs`
    * сортировка итогового массива: **oldest→newest**

Важно: внутри есть `requestJson()` с retry по 429 через `sleep(60)`.

---

### 4.2 `App\Services\MarketData\TwelveDataFxQuotesProvider` (batch quotes)

* `batchQuotes(['EURUSD','GBPUSD'])`
* собирает `EUR/USD,GBP/USD` в один запрос `/price`
* `Http::retry(2,200)->timeout(10)->get(...)->throw()`
* кеширует на 20 секунд (Cache key md5 списка)
* если 429 → возвращает `[]` (тихо, без обновлений)

---

### 4.3 `App\Services\MarketData\FxQuotesProviderPool`

Сейчас pool “формально”, но реально работает как single-provider:

* берёт `providers[0]`
* при 429 ставит cooldown на 15 минут (Cache)
* в cooldown возвращает пусто

---

### 4.4 `App\Services\MarketData\MarketDataSyncService` (оркестратор)

Ключевые методы:

#### `syncSymbol(symbol)`

* `syncSymbolQuote(symbol)`
* `syncSymbolCandles(symbol)`

#### Quotes: `syncSymbolQuote(symbol)`

* тянет `provider->lastPrice()`
* если ошибка/invalid → ставит в `symbol_quotes.source = 'provider_error'`, обновляет pulled_at/updated_at

⚠️ Но для forex основной поток quotes идёт не через это, а через batch:

#### Quotes batch: `syncFxQuotes(array symbolCodes)`

* делит на FX (`^[A-Z]{6}$`) и “прочие”
* FX → `fxQuotesProvider->batchQuotes()`

    * обновляет `SymbolQuote` **только если есть валидная цена**
    * если цены нет: **ничего не трогает**
* “прочие” → fallback `syncSymbolQuote()` (с provider_error логикой)

#### Candles: `syncSymbolCandles(symbol, limit=200)`

* сейчас **синхронизирует все ТФ всегда** (`5m,15m,30m,1h,4h,1d`)
* для каждого ТФ: `provider->candles()` → `upsertCandles()`

#### `upsertCandles()`

* `DB::table('candles')->upsert(...)` по `(symbol_code,timeframe_code,open_time_ms)`
* после upsert: **обрезает до последних 200**:

    * считает count
    * берёт `open_time_ms` на позиции `offset(199)` в `orderByDesc(open_time_ms)`
    * удаляет `< cutoffTime`

Это ровно твой принцип “храним только последние 200”.

---

## 5) Консольные команды (что запускает Scheduler и руками)

### 5.1 `market:sync` → `App\Console\Commands\MarketSync`

Опции:

* `--symbol=`
* `--only-quotes`
* `--only-candles`
* `--limit=200`

Логика:

* если без `--symbol`:

    * берёт активные symbols
    * quotes: `MarketDataSyncService->syncFxQuotes($codes)` (batch)
    * candles: для каждого symbol → `syncSymbolCandles(symbol, limit)`

---

### 5.2 `candles:backfill {symbol?}` → `App\Console\Commands\BackfillCandles`

Идея: залить 200 свечей по всем ТФ и беречь лимиты (sleep).

⚠️ Нюанс реализации: внутри он циклом по таймфреймам вызывает `syncSymbolCandles(symbol, 200)`, а `syncSymbolCandles()` **и так** бежит по всем ТФ. То есть сейчас backfill делает лишние повторные запросы. Но функционально заполнение работает.

---

### 5.3 `trade:tick` → `App\Console\Commands\TradeTick` + `App\Services\Trading\TradeTickService`

**Открытие сделок.**

Пайплайн внутри `TradeTickService::process($limit)`:

1. берёт активные `Symbol` (опционально limit)
2. читает quote из `SymbolQuote`

    * если нет → skipped `missing_quote`
3. решает вход через `TradeDecisionService::decideOpen(symbol)`

    * если hold → skipped `decision_hold`
4. проверяет минимум свечей `MIN_CANDLES=50` на entry timeframe

    * если мало → skipped `not_enough_candles`
5. проверяет, нет ли уже `Trade OPEN` на `(symbol,timeframe)`

    * если есть → skipped `existing_open_trade`
6. рассчитывает SL/TP:

    * если percent > 0 → переводит в points через `point_size`
    * иначе fallback фиксированные points
7. создаёт `Trade::create(...)` с meta:

    * `meta.open.decision` хранит debug решающего сервиса
    * `meta.risk` фиксирует computed points/percents/point_size

---

### 5.4 `trade:close` → `App\Console\Commands\TradeClose` + `App\Services\Trading\TradeCloseService`

**Закрытие сделок.**

`TradeCloseService::process($limit)`:

* берёт открытые сделки, по каждой делает `DB::transaction + lockForUpdate` (защита от гонок)
* проверяет quote:

    * нет → skipped_missing_quote
    * если quote старше 10 минут (`QUOTE_FRESH_MINUTES=10`) → skipped_stale_quote
* грузит `Symbol` для `point_size` (кеширует на процесс)
* считает `unrealized_points` в **points** (через point_size, BUY/SELL)
* сохраняет unrealized в trade

Дальше 3 слоя выхода:

**0) Trailing stop (если включён)**

* активируется при `unrealized >= activationPoints`
* ставит/двигает `meta.exit_stop.stop_price` ближе к цене
* считает activation/distance либо percent→points, либо fallback points

**1) Если exit_stop есть и “хитнуло”**

* закрывает по уровню stop_price (режим `level`)
* пишет `meta.close` включая r_multiple

**2) Hard exits: SL / TP / Time-stop**

* SL/TP исполняется по уровню рассчитанной цены
* time-stop закрывает по market цене (`exit_price_mode=market`)

**3) Strategy exit: lower TF turned against**

* для trade TF вычисляет lower timeframe (лестница вниз)
* берёт HA dir по **текущей** свече lower TF (`haDirFromCurrentCandle`, без skip)
* если lower TF “против” позиции → ставит/двигает `meta.exit_stop` на основе previous trade TF candle (low/high) с clamp на min distance (через SL points)

---

### 5.5 `trading:rebuild-monitors` → `App\Console\Commands\Trading\RebuildTradeMonitors`

Это ключ к твоему “UI состояния рынка” уже сегодня.

Логика:

* берёт `StrategySettingsRepository->get()` → timeframes
* берёт все активные symbols
* берёт все open trades и мапит по `symbol|tf`
* **на каждый symbol один раз** вызывает `TradeDecisionService::decideOpen(symbol)`
* дальше для каждой пары `(symbol, tf)` upsert в `trade_monitors`:

    * если по паре есть open trade:

        * `computeExpectationForOpenTrade()`: текст выхода/удержания (HA на lower TF, уровни high/low, price vs level)
    * иначе:

        * `computeExpectationForNoTrade()`: текст “почему нет входа” или “ждём на TF”

---

## 6) Сердце стратегии: `TradeDecisionService` (рынок-индекс, флэт, выбор entry TF)

Файл: `app/Services/Trading/TradeDecisionService.php`

### 6.1 `decideOpen(symbol)` возвращает

```
[action: open|hold,
 reason: string,
 side?: buy|sell,
 timeframe_code?: entry_tf,
 debug?: {...}]
```

### 6.2 Как считается “индекс рынка”

* читает `timeframes, weights, threshold`
* для каждого tf:

    * берёт **HA direction последней закрытой** свечи: `haDirFromLastClosedCandle()`

        * `orderByDesc(open_time_ms)->skip(1)->limit(2)` (берёт две закрытых)
        * вычисляет HA minimal-recursion (prev haClose + seed haOpen)
* конвертит dir в sign (+1/-1/0) и суммирует `sign*weight`

Если `abs(total) < threshold`:

* hold `no_edge`
* debug: `vote_total`, `threshold`, `dirs`

### 6.3 Выбор entry TF через кандидаты (пары current→entry)

* строит лестницу:

    * `1d→4h`, `4h→1h`, `1h→30m`, `30m→15m`, `15m→5m`
* исключает:

    * current=`1d` (нет “старших” для подтверждения)
    * entry=`5m` (не торгуем на 5m, нет lower TF для exit-логики)
* требует:

    * current dir == wantedDir
    * entry dir == wantedDir
    * у current есть хотя бы один senior TF тоже в wantedDir

### 6.4 Фильтр флэта

`isFlat(symbol, entry_tf, lookback, threshold, &flatDebug)`

* берёт последние N свечей `orderByDesc(open_time_ms)->limit(lookback)`
* range_pct = (maxHigh-minLow)/lastClose
* если range_pct < threshold → flat

Если все кандидаты flat → hold `all_candidates_flat` + debug кандидатов и flatDebug.

---

## 7) Dashboard/UI: что выводится сейчас и откуда берётся

### 7.1 `/dashboard` → `resources/views/dashboard.blade.php`

На странице:

1. `@livewire(TradingKpiWidget::class)`
2. progress bar цикла (`dashboard/_refresh-progress.blade.php`) на Alpine
3. `<livewire:dashboard.trades-monitor />` (открытые)
4. `<livewire:dashboard.trades-waiting />` (ожидания)
5. `<livewire:dashboard.trades-history />` (история)

---

### 7.2 KPI виджет: `App\Filament\Widgets\TradingKpiWidget`

* `Open P&L (pts)` = сумма unrealized_points по open trades
* `Closed today +` и `Closed today -`
* `Closed today (R)` и `ProfitFactor (R)` через JSON_EXTRACT `meta.close.r_multiple`

Обновление:

* слушает `dashboard-refresh` event (внизу страницы hidden poll каждые 60s)

---

### 7.3 Таблица открытых сделок: `Livewire/Dashboard/TradesMonitor`

Query:

* `Trade::where(status='open')->with(symbol.quotes)`

Колонки (важные для “состояния рынка”):

* side badge (BUY/SELL)
* reason из `meta.open.reason`
* opened_at, entry_price
* unrealized_points и unrealized R
* “SL left” считает оставшееся расстояние до SL/exit_stop (если armed)
* expectation (локально вычисляет HA на lower TF и пишет “Exit: lower TF reversed”)

Это уже почти полноценный монитор позиции.

---

### 7.4 Таблица ожиданий: `Livewire/Dashboard/TradesWaiting`

Query:

* `TradeMonitor::whereNull(open_trade_id)`

Колонки:

* symbol, TF
* expectation (текст из rebuild-monitors)
* market_summary: на каждый symbol вызывает `TradeDecisionService::decideOpen(symbol)` и выводит

    * “Market index: X (BUY/SELL)” или “No edge”

⚠️ Важно: тут уже есть кэш на символ в рамках рендера (`static $marketCache`).

---

### 7.5 История: `Livewire/Dashboard/TradesHistory`

Query:

* `Trade::query()` + фильтр default status=closed
  Колонки:
* entry/exit, realized_points (с суммой), r_multiple из meta.close.r_multiple (без summarize/sort, чтобы Filament не пытался лезть в SQL по виртуальному полю)

---

## 8) DI/Bindings (почему всё заводится без ручного new)

`app/Providers/AppServiceProvider.php`

* `StrategySettingsRepository` → `ConfigStrategySettingsRepository`
* `MarketDataProvider` → `TwelveDataMarketDataProvider`
* `FxQuotesProvider` → `FxQuotesProviderPool([TwelveDataFxQuotesProvider])`

---

## 9) Scheduler (что крутится “само”)

`routes/console.php`:

* `market:sync` everyFiveMinutes → log `schedule-market-sync.log`
* `trade:tick` everyFiveMinutes → log `schedule-trade-tick.log`
* `trade:close` everyFiveMinutes → log `schedule-trade-close.log`
* `trading:rebuild-monitors` everyFiveMinutes → log `schedule-trade-monitors.log`

То есть UI живёт на данных, которые постоянно пересобираются.

---

## 10) Самое важное про “Состояние рынка” (то, что ты просишь сегодня выводить)

В проекте уже есть готовые “примитивы рынка”, просто они разбросаны:

1. **Market index + dirs по всем ТФ**
   источник: `TradeDecisionService::decideOpen()` → `debug.vote_total`, `debug.dirs`, `debug.threshold`, `debug.flat`, `debug.candidates`

2. **Флэт по конкретному ТФ**
   источник: `TradeDecisionService::isFlat()` → `flatDebug[tf]`

3. **Смысловые тексты “почему нет входа / что ждём”**
   источник: `RebuildTradeMonitors` → `trade_monitors.expectation`

4. **Состояние сделок и риск-уровни**
   источник: `trades + meta.exit_stop + symbol.point_size + symbol_quotes.price`

То есть для нового UI “Market State” тебе не нужен новый расчётный слой: ты можешь собрать экран как “панель приборов”, которая агрегирует:

* quote freshness/price
* market index + dirs badge по 6 ТФ
* flat flags
* expectation текст
* если есть open trade: SL/exit_stop/Unrealized/R/время в позиции

---

Если хочешь, следующим шагом (без размазывания) я прямо возьму и **выделю конкретные “готовые данные для UI”**, то есть:

* *какой запрос к БД / какой вызов сервиса нужен для каждого блока интерфейса*,
* и где у тебя сейчас потенциально дублируются вычисления (чтобы UI не “долбил” decideOpen по 200 раз).

Скажи только одну вещь: ты хочешь “Состояние рынка” как

1. **новый блок сверху на dashboard**, или
2. **отдельная страница**, или
3. **расширение существующей TradesWaiting/TradesMonitor**?


🆕 Обновление состояния проекта (Market Data / TwelveData)
🔑 1️⃣ Ротация API-ключей TwelveData

Теперь система использует несколько API-ключей TwelveData с автоматической ротацией.

Как это работает

Компонент:
App\Services\MarketData\TwelveDataApiKeyPool

Механика:

Ключи читаются из

TWELVEDATA_API_KEY=key1,key2,key3,...


Используется round-robin выбор ключей

Если ключ получает 429 / out of credits:

он уходит в cooldown на 6 часов

система автоматически переключается на следующий ключ

Если все ключи exhausted → провайдер бросает ошибку
"TwelveData rate limit: all keys exhausted"

Логирование ротации

В логах видно:

[TwelveData] request attempt key_id=XXXX
[TwelveData] rate_limited key_id=XXXX -> failover
[TwelveData] cooldown set key_id=XXXX ttl_hours=6
[TwelveData] all_keys_exhausted


👉 В логах никогда не пишутся реальные ключи, только hash-id.

💱 2️⃣ Quotes и Candles теперь по-разному “едят” лимит
Quotes (/price)

Очень лёгкие по лимиту

Обновляются каждые 5 минут через market:sync

Даже при слабом лимите чаще всего продолжают работать

Candles (/time_series)

Очень дорогие по API credits

Backfill легко выбивает лимит

При exhausted всех ключей candles временно не обновляются

🔄 3️⃣ Новая стратегия работы со свечами
❌ Старый подход

candles:backfill на 200 свечей × 6 ТФ
→ быстро сжигает лимит TwelveData

✅ Новый рекомендуемый подход

Не делать массовый backfill при ограниченных лимитах.

Если таймфрейм активен, обычный цикл:

market:sync (каждые 5 минут)


будет:

подтягивать только новые закрытые свечи

делать это очень экономно

постепенно восстанавливать историю “вперёд во времени”

Пропущенные часы в прошлом не восстановятся автоматически, но для торговой логики это не критично, потому что стратегия работает на текущем рынке.

🧠 4️⃣ Поведение системы при отсутствии кредитов

Если у TwelveData закончились кредиты:

Тип данных	Поведение
Quotes	Возвращается пустой массив, система ждёт следующего цикла
Candles	Провайдер кидает исключение “all keys exhausted”, данные не обновляются

Это не ошибка логики, а нормальное поведение при лимитах API.

⚙️ 5️⃣ Добавление новых ключей

Чтобы увеличить лимит:

Добавить ключ в .env:

TWELVEDATA_API_KEY=key1,key2,key3,key4


Выполнить:

php artisan optimize:clear


Система автоматически начнёт использовать новый ключ в ротации.

📌 Итоговое текущее состояние

Рынок работает на TwelveData

Реализована устойчивая ротация API-ключей

Quotes стабильны даже при лимитах

Candles зависят от доступных credits

Предпочтительная модель — постепенное обновление через обычный sync, а не агрессивный backfill
