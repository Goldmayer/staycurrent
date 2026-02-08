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
            'range_pct_threshold' => 0.002,
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
             * Stop loss points.
             * Default: 20
             */
            'stop_loss_points' => 20,

            /*
             * Take profit points.
             * Default: 60
             */
            'take_profit_points' => 360,

            /*
             * Maximum hold time in minutes.
             * Default: 120
             */
            'max_hold_minutes' => 120,

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
            ],
        ],
    ],
];
