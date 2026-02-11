@php
    // твой цикл обновления данных из schedule: everyFiveMinutes()
    $cycleSeconds = 150;
    // даём клиенту "якорь" времени с сервера, чтобы не зависеть от локальных часов
    $serverUnix = now('UTC')->timestamp;
@endphp

<div
    x-data="refreshProgress({{ $cycleSeconds }}, {{ $serverUnix }})"
    x-init="start()"
    class="w-full h-[3px] bg-gray-200 dark:bg-gray-800 overflow-hidden" style="height: 3px"
>
    <div
        class="h-full bg-red-600 transition-[width] duration-200"
        :style="`width: ${Math.min(100, Math.max(0, percent))}%`"
    ></div>
</div>

<script>
    function refreshProgress(cycleSeconds, serverUnixAtRender) {
        return {
            cycleSeconds,
            serverUnixAtRender,
            startedClientMs: Date.now(),
            percent: 0,

            start() {
                this.tick();
                setInterval(() => this.tick(), 200);
            },

            nowUnix() {
                // серверный unix + сколько прошло на клиенте с момента рендера
                const elapsed = Math.floor((Date.now() - this.startedClientMs) / 1000);
                return this.serverUnixAtRender + elapsed;
            },

            tick() {
                const now = this.nowUnix();
                const inCycle = ((now % this.cycleSeconds) + this.cycleSeconds) % this.cycleSeconds;
                this.percent = (inCycle / this.cycleSeconds) * 100;
            },
        };
    }
</script>

