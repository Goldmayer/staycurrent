<div
    x-data="{
        isRefreshing: false,
        progress: 0,
        startRefresh() {
            this.isRefreshing = true;
            this.progress = 0;
            this.animateProgress();
        },
        animateProgress() {
            const interval = setInterval(() => {
                this.progress += 1;
                if (this.progress >= 100) {
                    clearInterval(interval);
                    this.isRefreshing = false;
                }
            }, 60); // 60 seconds total
        }
    }"
    x-on:dashboard-refresh.window="startRefresh()"
    class="fixed bottom-4 right-4 z-50"
>
    <div x-show="isRefreshing" class="flex items-center gap-3 rounded-lg border border-slate-200 bg-white p-3 shadow-lg">
        <div class="relative h-6 w-6">
            <div class="absolute inset-0 rounded-full border-2 border-slate-200"></div>
            <div
                class="absolute inset-0 rounded-full border-2 border-slate-600 border-t-transparent"
                :style="'transform: rotate(' + (progress * 3.6) + 'deg)'"
            ></div>
        </div>
        <div class="flex flex-col">
            <span class="text-sm font-medium text-slate-900">Refreshing data...</span>
            <span class="text-xs text-slate-500" x-text="progress + '% complete'"></span>
        </div>
    </div>
</div>
