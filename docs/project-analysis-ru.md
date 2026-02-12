# StayCurrent — подробный архитектурный разбор по функциональным кластерам

## 1) Общий профиль проекта

- **Стек:** Laravel 12 + Livewire 4 + Filament 5 + Fortify.
- **Домены:**
  - аутентификация и настройки пользователя,
  - синхронизация рыночных котировок/тик-данных,
  - сигнализация и открытие сделок,
  - сопровождение/закрытие сделок,
  - мониторинг статусов и ожиданий по символам/ТФ.
- **Ключевая архитектурная идея:**
  - рыночные данные живут в `symbol_quotes` (текущая цена) и `price_ticks` (история тиков),
  - торговое решение формируется через `TradeDecisionService` на основе `PriceWindowService`,
  - исполнение входа организовано через `pending_orders` (отложки),
  - сопровождение и выход — через `TradeCloseService` (trailing/hard exit/реверс окна),
  - UI строится на Livewire-компонентах и Filament-таблицах.

---

## 2) Кластер «Контракты и DI-ядро»

### 2.1 Контракты

- `MarketDataProvider` — источник last price и свечей (`lastPrice`, `candles`).
- `FxQuotesProvider` — батчевые FX-котировки (`batchQuotes`) + признак rate-limit.
- `StrategySettingsRepository` — централизованная выдача стратегии и риск-параметров.

### 2.2 Привязки контейнера

В `AppServiceProvider`:
- `StrategySettingsRepository -> ConfigStrategySettingsRepository`.
- `MarketDataProvider -> TwelveDataMarketDataProvider` (по умолчанию).
- `FxQuotesProvider` реализован через пул `FxQuotesProviderPool`, внутри сейчас подключён `TwelveDataFxQuotesProvider`.

**Смысл:** сервисы торговли не завязаны напрямую на конкретный HTTP-провайдер, только на контракты.

---

## 3) Кластер «Данные и схема БД»

### 3.1 Основные таблицы торгового ядра

1. `symbols`
   - `code`, `is_active`, `sort`, `point_size`, `price_decimals`.
   - Справочник инструментов и их ценовой дискретности.

2. `symbol_quotes`
   - `symbol_code` (unique), `price`, `source`, `pulled_at`.
   - Последний слепок цены для каждого символа.

3. `price_ticks`
   - `symbol_code`, `price`, `pulled_at`.
   - Поток тиков (ограничивается в sync-сервисе до последних ~2000 на символ).

4. `candles`
   - `symbol_code`, `timeframe_code`, `open_time_ms`, OHLCV, `close_time_ms`.
   - Уникальность по (`symbol_code`,`timeframe_code`,`open_time_ms`).

5. `trades`
   - `symbol_code`, `timeframe_code`, `side`, `status`, `opened_at`, `closed_at`,
   - `entry_price`, `exit_price`, `realized_points`, `unrealized_points`, `meta`,
   - плюс риск-поля: `stop_loss_points`, `take_profit_points`, `max_hold_minutes`.

6. `pending_orders`
   - `symbol_code`, `timeframe_code`, `side`, `entry_price`, `meta`.
   - Unique (`symbol_code`,`timeframe_code`) — одна отложка на символ+ТФ.

7. `trade_monitors`
   - `symbol_code`, `timeframe_code`, `expectation`, `open_trade_id`, `last_notified_state`.
   - Материализованная матрица «что происходит/чего ждём» для UI.

### 3.2 Модельные связи

- `Symbol -> hasOne SymbolQuote` (`quotes`).
- `Symbol -> hasMany Candle`.
- `Trade -> belongsTo Symbol`.
- `TradeMonitor -> belongsTo Trade` через `open_trade_id`.
- `PendingOrder -> belongsTo Symbol`.
- `Candle -> belongsTo Symbol`.

### 3.3 Поля и cast-логика, критичная для поведения

- `Trade.status` cast в enum `TradeStatus`; есть `isOpen()/isClosed()/isLong()/isShort()`.
- `Trade.meta` — массив, туда пишутся подробности open/close/exit-stop/r-multiple/debug.
- `Symbol.point_size` — базовое поле для конвертации цены в «пункты» и обратно.
- `SymbolQuote.pulled_at` и `PriceTick.pulled_at` — временная ось валидности данных.

---

## 4) Кластер «Конфигурация стратегии и сессий»

### 4.1 `config/trading.php`

Определяет:
- `strategy.timeframes` и `weights` для голосования направления.
- `total_threshold` — порог силы сигнала.
- `flat.range_pct_threshold` — фильтр флэта.
- `entry.pending_distance_points` — отступ от цены для выставления pending.
- `entry_confirm`:
  - разрешённые entry ТФ,
  - минимум старших ТФ в нужном направлении,
  - требование подтверждения младшим ТФ.
- `risk`:
  - SL/TP в points и/или процентах,
  - `max_hold_minutes`,
  - trailing-параметры.
- `price_windows` — размер окна по ТФ для PriceWindowService.
- `exit` — параметры реверсного/ценового выхода.

### 4.2 Репозиторий настроек

`ConfigStrategySettingsRepository` нормализует config и отдает единый массив для сервисов.

### 4.3 FX-сессии

`FxSessionScheduler`:
- определяет, открыт ли торговый window для символа по валютным сессиям,
- учитывает warmup/cooldown,
- маппит символ на сессии через base/quote валюты,
- рассчитывает частоту синка (`fast_interval_minutes` vs `slow_interval_minutes`),
- проверяет, пора ли обновить quote (`isQuoteDue`).

`FxSyncModeService` строит карточки FAST/SLOW для UI и прогноз времени смены режима.

---

## 5) Кластер «Провайдеры данных и отказоустойчивость»

### 5.1 `MarketDataProvider` реализации

- `TwelveDataMarketDataProvider` (основной):
  - `lastPrice`, `candles` с маппингом символов (`EURUSD -> EUR/USD`) и ТФ (`5m -> 5min`),
  - опирается на `TwelveDataApiKeyPool` для failover по ключам,
  - при ошибках пушит уведомления через `SignalNotificationService` + Filament DB notifications.

- `BinanceMarketDataProvider` и `BinanceMarketDataClient`:
  - альтернативный источник,
  - команды smoke/backfill ориентированы на Binance-поток.

### 5.2 Пул ключей TwelveData

`TwelveDataApiKeyPool`:
- читает список ключей (поддержка comma-separated),
- round-robin выбор ключа,
- cooldown на ключ после 429,
- `withFailover` повторяет вызов на следующем ключе,
- при исчерпании ключей бросает runtime-исключение (важный stop-сигнал).

### 5.3 FX Quotes pool

`FxQuotesProviderPool`:
- единая точка для батчевых FX-котировок,
- в текущей конфигурации использует первого провайдера,
- при 429 ставит cooldown в cache и временно возвращает пустой набор.

---

## 6) Кластер «Синк рынка и жизненный цикл данных»

### 6.1 `MarketDataSyncService`

`syncSymbolQuote(symbol)`:
1. получает `lastPrice` у провайдера,
2. upsert в `symbol_quotes`,
3. добавляет запись в `price_ticks`,
4. подрезает хвост тиков старше лимита,
5. при ошибке помечает `source=provider_error` и репортит исключение.

### 6.2 Команда `market:sync`

- В single-symbol режиме:
  - синхронизирует quote,
  - если ок, вызывает торговый тик `TradeTickService::processSymbols` для этого символа.

- В all-symbol режиме:
  - берёт активные `symbols`,
  - для каждого символа решает, пора ли тянуть quote (через `FxSessionScheduler` + факт открытой сделки),
  - собирает только обновлённые символы,
  - **одним батч-вызовом** отправляет их в `TradeTickService`.

**Итог:** цепочка `market:sync -> sync quotes -> trade tick` реализует «событийную» реакцию на новые данные.

---

## 7) Кластер «Принятие решения на вход»

### 7.1 `PriceWindowService`

Для символа и окна времени:
- берёт последние N тиков текущего и предыдущего окна,
- считает avg/min/max/range/count,
- выводит направление `up/down/flat/no_data` и силу `dir_pct`.

Это фундамент для всех directional-решений.

### 7.2 `TradeDecisionService::decideOpen`

Алгоритм:
1. По каждому ТФ считает направление окна.
2. Формирует взвешенный vote total.
3. Если `abs(total) < threshold` → `hold:no_edge`.
4. Определяет wantedDir и side.
5. `pickEntryTimeframe` выбирает entry ТФ по правилам:
   - ТФ в разрешённом наборе,
   - entry ТФ в wantedDir,
   - хватает старших подтверждений,
   - (опционально) младший ТФ в ту же сторону,
   - entry ТФ не «flat».
6. Если entry найден, дополнительно проверяет M5-confirmation.

Возможные причины hold:
- `no_edge`,
- `waiting_lower_reversal`,
- `waiting_m5_reversal`,
- `all_candidates_flat`,
- `no_entry_timeframe`,
- `missing_timeframe_config`.

`debug` содержит богатую трассировку (`dirs`, `windows`, `waiting_entries`, `rejected_entries`, и т.д.).

---

## 8) Кластер «Исполнение входа и pending-логика»

### 8.1 `TradeTickService` — центральный движок входа

Поток `processSymbols`:
1. Нормализация symbolCodes.
2. Предзагрузка:
   - активных символов,
   - quotes,
   - открытых trades,
   - существующих pending_orders.
3. По каждому символу:
   - если нет quote → skip,
   - получить decision (или force-open режим),
   - сохранить WAIT-мониторы через `persistWaitingMonitorsFromDecision`,
   - если торговое окно закрыто (fx session) → skip,
   - если decision=hold → отмена pending-ордеров по символу,
   - если уже есть open trade на symbol+tf → skip,
   - попытка fill существующего pending,
   - иначе ensure/update pending.

### 8.2 Pending pipeline

- `calculatePendingEntryPrice`:
  - buy: current + distance*point_size,
  - sell: current - distance*point_size.

- `ensurePendingOrder`:
  - создаёт pending, если его нет,
  - обновляет только при meaningful changes (side/entry),
  - отправляет сигнал-уведомления.

- `tryFillPendingOrder`:
  - проверяет достижение entry price,
  - рассчитывает SL/TP из процентов (или fallback points),
  - открывает trade (`openTrade`),
  - удаляет filled pending.

### 8.3 Open trade запись

`openTrade` создаёт `trades` c:
- статусом `open`,
- risk-параметрами,
- `meta.open` (source/reason/decision/hash/quote timestamp),
- `meta.risk`.

Параллельно — DB notification через Filament.

### 8.4 Монитор ожиданий

`persistWaitingMonitorsFromDecision`:
- для `waiting_lower_reversal` записывает `WAIT:*` ожидания в `trade_monitors` (open_trade_id = null),
- отправляет уведомление только при изменении состояния (`last_notified_state`),
- чистит устаревшие WAIT-строки.

`clearWaitingMonitorForSymbolTf` чистит WAIT конкретного symbol+tf после открытия.

---

## 9) Кластер «Сопровождение и закрытие сделок»

### 9.1 `TradeCloseService::process`

Работает по open trades (с lock внутри транзакции на запись):

1. Проверки данных:
   - quote существует,
   - quote свежая,
   - символ и `point_size` валидны.

2. Обновляет `unrealized_points`.

3. **Trailing stop** (если включен):
   - активация при достижении прибыли,
   - пересчёт candidate stop,
   - стоп только подтягивается в сторону улучшения.

4. **Exit stop hit**:
   - при пересечении стоп-цены закрывает сделку по stop level,
   - пишет `meta.close` (reason=`exit_stop_hit`, r_multiple и т.д.).

5. **Hard exits** (`detectHardExit`):
   - `time_exit` (max hold),
   - `stop_loss` по уровню,
   - `take_profit` по уровню.

6. **Price-window reversal exit**:
   - берёт lower TF,
   - считает окно через `PriceWindowService`,
   - если движение против позиции и выше порога силы — закрывает по market.

7. Возвращает агрегированные счётчики (`processed/closed/held/skipped/...`).

### 9.2 `TradeExitDecisionService`

Есть отдельный сервис логики выхода по HA-направлению/уровням предыдущей свечи.
Сейчас основная production-логика закрытия фактически сосредоточена в `TradeCloseService` (price-only + risk exits), а `TradeExitDecisionService` выступает скорее как альтернативный/исторический сценарий.

---

## 10) Кластер «Мониторы, дашборд и UI-срез»

### 10.1 Главный экран

`resources/views/dashboard.blade.php` включает:
- KPI/инфо-блоки,
- `dashboard.trades-monitor`,
- `dashboard.trades-waiting`,
- `dashboard.trades-history`,
- компонент Filament database notifications.

### 10.2 `TradesMonitor` (open positions)

- таблица open trades,
- вычисляет «здоровье данных» (stale quote/candle),
- показывает прогресс/ok-бэйджи,
- строит FX FAST/SLOW карточки через `FxSyncModeService`.

### 10.3 `TradesWaiting`

- читает `trade_monitors` c `expectation LIKE WAIT:%` и `open_trade_id IS NULL`,
- для каждой записи достраивает контекст через `TradeDecisionService` и price windows,
- отображает TF-карту направлений, индекс рынка (vote_total vs threshold), причину ожидания.

### 10.4 `TradesHistory`

- фильтры по symbol/tf/status,
- вывод open/closed, вход/выход, PnL,
- вычисление `R` из `trade.meta.close.r_multiple`.

---

## 11) Кластер «Команды и оркестрация процессов»

- `market:sync` — синк котировок и триггер торгового тика.
- `trade:tick` — принудительный запуск движка входа, с `--force` режимом.
- `trade:close` — сопровождение и закрытие открытых сделок.
- `trading:rebuild-monitors` — полная регенерация `trade_monitors` по активным символам/ТФ.
- `candles:backfill` и `binance:smoke` — вспомогательные data-утилиты.

В `routes/console.php` эти задачи запланированы каждую минуту.

---

## 12) Кластер «Auth / Settings / админка»

- Fortify: login/register/reset/two-factor/challenge/confirm-password.
- Livewire settings-компоненты:
  - профиль,
  - пароль,
  - внешний вид,
  - 2FA + recovery codes,
  - удаление пользователя.
- Filament admin panel подключён через `AdminPanelProvider`, доступ разрешён всем аутентифицированным пользователям (`User::canAccessPanel() => true`).

---

## 13) Полный сквозной dataflow (от цены к закрытию)

1. Scheduler запускает `market:sync` каждую минуту.
2. Для due-символов тянется quote -> пишется в `symbol_quotes` + `price_ticks`.
3. Для обновлённых символов запускается `TradeTickService`:
   - решение по входу,
   - постановка/обновление/исполнение pending,
   - открытие trade,
   - обновление WAIT-мониторов.
4. Отдельно `trade:close` сопровождает open-trades:
   - trailing,
   - hard SL/TP/time,
   - reverse-window выход.
5. `trade_monitors` + `trades` + notifications отражаются в Livewire/Filament UI.

---

## 14) Матрица зависимостей «кто кого вызывает»

- `market:sync` -> `MarketDataSyncService` + `TradeTickService`.
- `TradeTickService` -> `TradeDecisionService` + `FxSessionScheduler` + `StrategySettingsRepository` + `SignalNotificationService` + модели (`Trade`, `PendingOrder`, `TradeMonitor`, `SymbolQuote`).
- `TradeDecisionService` -> `PriceWindowService` + `StrategySettingsRepository`.
- `TradeCloseService` -> `PriceWindowService` + `StrategySettingsRepository` + модели (`Trade`, `Symbol`, `SymbolQuote`) + notifications.
- `FxSyncModeService` -> `FxSessionScheduler` + `Symbol` + `Trade`.
- `MarketDataSyncService` -> `MarketDataProvider` + `SymbolQuote` + `PriceTick`.
- `TwelveDataMarketDataProvider`/`TwelveDataFxQuotesProvider` -> `TwelveDataApiKeyPool` -> HTTP API.

---

## 15) Важные нюансы и потенциальные точки внимания

1. **В `TradeDecisionService` создаётся `new PriceWindowService()` вместо DI** — не критично функционально, но затрудняет тестируемость/подмену.
2. **`TradeSide` enum (`long/short`) не совпадает с фактическим `Trade.side` (`buy/sell`)** — сейчас enum фактически не участвует в основном потоке.
3. **`BackfillCandles` вызывает `syncSymbolCandles`, которого нет в `MarketDataSyncService`** — вероятно, legacy-рассинхрон.
4. **`TradeExitDecisionService` частично дублирует идеи выхода, но основной runtime-путь — `TradeCloseService`.**
5. **`TradeMonitor` как materialized state** существенно упрощает UI, но требует дисциплины по актуализации (что здесь решено через `trade:tick` + `trading:rebuild-monitors`).

---

## 16) Кластеризация по бизнес-потокам (краткая карта)

- **Кластер A: Data ingestion**
  - `MarketDataProvider*`, `MarketDataSyncService`, `price_ticks`, `symbol_quotes`.
- **Кластер B: Signal engine**
  - `PriceWindowService`, `TradeDecisionService`, стратегия из `config/trading.php`.
- **Кластер C: Execution**
  - `TradeTickService`, `pending_orders`, `trades(open)`.
- **Кластер D: Risk & exit**
  - `TradeCloseService`, trailing/hard/reversal exits, `trades(closed)`.
- **Кластер E: State projection**
  - `trade_monitors`, `RebuildTradeMonitors`.
- **Кластер F: Observability/UI**
  - Livewire dashboard-компоненты, Filament notifications/widgets.
- **Кластер G: Platform/security**
  - Fortify auth+2FA, settings-компоненты, админ-панель.

