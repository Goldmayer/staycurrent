<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Strategy Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for the trading strategy.
    |
    */

    'strategy' => [

        /*
         * List of timeframes to consider for voting.
         * Default: ['5m', '15m', '30m', '1h', '4h', '1d']
         */
        'timeframes' => ['5m', '15m', '30m', '1h', '4h', '1d'],

        /*
         * Total threshold for opening a trade.
         * Default: 8
         */
        'total_threshold' => 8,

        /*
         * Flat market detection settings.
         */
        'flat' => [

            /*
             * Number of recent candles to look back for flat detection.
             * Default: 12
             */
            'lookback_candles' => 8,

            /*
             * Percentage threshold for flat detection.
             * Default: 0.002 (0.2%)
             */
            'range_pct_threshold' => 0.0008,
        ],

        /*
         * Entry settings.
         */
        'entry' => [

            /*
             * Whether to use the current candle for entry decisions.
             * Default: true
             */
            'use_current_candle' => false,
        ],

        /*
         * Weights for timeframes.
         * Default: [5m => 1, 15m => 2, 30m => 3, 1h => 4, 4h => 5, 1d => 6]
         */
        'weights' => [
            '5m' => 1,
            '15m' => 2,
            '30m' => 3,
            '1h' => 4,
            '4h' => 5,
            '1d' => 6,
        ],

        /*
         * Risk management settings.
         */
        'risk' => [
            /*
             * Stop loss points (fallback, used only if stop_loss_percent is 0).
             * Default: 20
             */
            'stop_loss_points' => 20,

            /*
             * Stop loss percent of ENTRY price.
             * Example: 0.003 = 0.30%
             * If > 0, stop_loss_points will be calculated as:
             *   (entry_price * stop_loss_percent) / symbol.point_size
             */
            'stop_loss_percent' => 0.003,

            /*
             * Take profit points (fallback, used only if take_profit_percent is 0).
             * Default: 60
             */
            'take_profit_points' => 0,

            /*
             * Take profit percent of ENTRY price.
             * If 0 => disabled (or fallback to take_profit_points).
             */
            'take_profit_percent' => 0.0,

            /*
             * Maximum hold time in minutes.
             * Default: 120
             */
            'max_hold_minutes' => 12000,

            /*
             * Trailing stop settings.
             */
            'trailing' => [
                /*
                 * Enable trailing stop.
                 * Default: false
                 */
                'enabled' => true,

                /*
                 * Activation profit threshold in points.
                 * Default: 30
                 */
                'activation_points' => 30,

                /*
                 * Trailing distance in points.
                 * Default: 25
                 */
                'distance_points' => 25,

                /*
                 * Activation profit threshold as percent of entry price.
                 * If > 0, activation_points will be calculated as:
                 *   round((entry_price * activation_percent) / symbol.point_size, 2)
                 * Default: 0.002 (0.2%)
                 */
                'activation_percent' => 0.002,

                /*
                 * Trailing distance as percent of entry price.
                 * If > 0, distance_points will be calculated as:
                 *   round((entry_price * distance_percent) / symbol.point_size, 2)
                 * Default: 0.0015 (0.15%)
                 */
                'distance_percent' => 0.0015,
            ],
        ],

        'price_windows' => [
            'dir_flat_threshold_pct' => 0.0001,
            'timeframes' => [
                '5m'  => ['minutes' => 5,    'points' => 5],
                '15m' => ['minutes' => 15,   'points' => 10],
                '30m' => ['minutes' => 30,   'points' => 20],
                '1h'  => ['minutes' => 60,   'points' => 40],
                '4h'  => ['minutes' => 240,  'points' => 160],
                '1d'  => ['minutes' => 1440, 'points' => 960],
            ],
        ],

        'exit' => [
            // quote freshness guard
            'quote_max_age_seconds' => 120,

            // hard stop from entry
            'hard_stop_pct' => 0.0020, // 0.20%

            // reversal detection via PriceWindowService
            'tfs' => ['5m', '15m', '30m'],
            'reversal_min_against_count' => 2,
            'reversal_min_strength_pct' => 0.00015,

            // trailing stop based on best_price stored in trade.meta
            'trailing_pct' => 0.0015, // 0.15%
            'min_profit_to_trail_pct' => 0.0005, // 0.05%
        ],


    ],



    /*
    |--------------------------------------------------------------------------
    | Points Normalization
    |--------------------------------------------------------------------------
    |
    | Configuration for normalizing points across all instruments.
    |
    */

    'points' => [
        /*
         * Mode for point calculation.
         * Allowed values: 'percent' or 'tick'
         * Default: 'percent'
         */
        'mode' => 'tick',

        /*
         * Percent per point of ENTRY price.
         * Default: 0.0001 (0.01%)
         */
        'percent_per_point' => 0.0001,
    ],

    /*
    |--------------------------------------------------------------------------
    | FX Session Scheduling
    |--------------------------------------------------------------------------
    |
    | Configuration for FX session-based quote sync frequency scheduling.
    |
    */

    'fx_sessions' => [
        /*
         * Timezone for session calculations (default: UTC)
         */
        'timezone' => 'UTC',

        /*
         * Fast interval in minutes when at least one mapped session is active
         * or when the symbol has open trades
         */
        'fast_interval_minutes' => 1,

        /*
         * Slow interval in minutes when no mapped sessions are active
         * and no open trades exist
         */
        'slow_interval_minutes' => 30,

        /*
         * Session definitions with per-session warmup and cooldown
         */
        'sessions' => [
            'sydney' => [
                'start' => '22:00',
                'end' => '07:00',
                'warmup_minutes' => 60,
                'cooldown_minutes' => 120,
            ],
            'tokyo' => [
                'start' => '00:00',
                'end' => '09:00',
                'warmup_minutes' => 30,
                'cooldown_minutes' => 60,
            ],
            'london' => [
                'start' => '08:00',
                'end' => '17:00',
                'warmup_minutes' => 60,
                'cooldown_minutes' => 120,
            ],
            'newyork' => [
                'start' => '13:00',
                'end' => '22:00',
                'warmup_minutes' => 60,
                'cooldown_minutes' => 120,
            ],
        ],

        /*
         * Currency to sessions mapping
         * Each currency code maps to an array of session names that affect it
         */
        'currencies' => [
            'JPY' => ['tokyo'],
            'EUR' => ['london'],
            'GBP' => ['london'],
            'USD' => ['newyork'],
            'CAD' => ['newyork'],
            'AUD' => ['sydney'],
            'NZD' => ['sydney'],
            'CHF' => ['london'],
        ],
    ],
];
