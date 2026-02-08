<div>
    <h3 class="text-lg font-semibold mb-4">Waiting trades (no open trade)</h3>

    <div class="mb-4 p-3 bg-gray-100 dark:bg-gray-800 rounded">
        <p class="text-sm text-gray-600 dark:text-gray-300">Debug: TradeMonitor count: {{ $this->debug_total_records }}</p>
        <p class="text-sm text-gray-600 dark:text-gray-300">Debug: Table query records count: {{ $this->debug_table_records_count }}</p>
        <p class="text-sm text-gray-600 dark:text-gray-300">Debug: Table records method count: {{ $this->getTableRecords()->count() }}</p>
    </div>

    {{ $this->table }}
</div>
