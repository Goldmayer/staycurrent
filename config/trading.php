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
        'total_threshold' => 6,

        /*
         * Flat market detection settings.
         */
        'flat' => [

            /*
             * Number of recent candles to look back for flat detection.
             * Default: 12
             */
            'lookback_candles' => 12,

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
            'use_current_candle' => true,
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
];
