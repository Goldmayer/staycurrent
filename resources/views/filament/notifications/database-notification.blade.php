@php
    $title = (string) ($getTitle() ?? '');
    $body = (string) ($getBody() ?? '');
    $statusRaw = (string) ($getStatus() ?? '');
    $status = strtolower(trim($statusRaw));

    $action = 'INFO';
    $symbol = '';
    $tf = '';
    $side = '';
    $price = '';
    $reason = '';
    $msg = '';

    // Action from title
    if (preg_match('/^(OPEN|WAITING|DATA PROVIDER ERROR|INFO)\b/i', $title, $m)) {
        $action = strtoupper($m[1]);
    }

    // Symbol + TF from title like: "OPEN EURUSD M5" / "WAITING EURUSD M15"
    if (preg_match('/\b([A-Z]{6})\s+([A-Z0-9]+)\b/i', $title, $m)) {
        $symbol = strtoupper($m[1]);
        $tf = strtoupper($m[2]);
    }

    // Side + Price from body like: "Side: BUY | Price: 1.0812"
    if ($body !== '') {
        if (preg_match('/\bSide:\s*(BUY|SELL)\b/i', $body, $m)) {
            $side = strtoupper($m[1]);
        }
        if (preg_match('/\bPrice:\s*([0-9]+(?:\.[0-9]+)?)\b/', $body, $m)) {
            $price = $m[1];
        }
        if (preg_match('/\bReason:\s*(.+)\s*$/i', $body, $m)) {
            $reason = trim($m[1]);
        }
        $msg = $body;
    }

    // Normalize "DATA PROVIDER ERROR" into error-ish styling
    if ($action === 'DATA PROVIDER ERROR' && $status === '') {
        $status = 'danger';
    }

    $chip = match ($status) {
        'success' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
        'info' => 'bg-sky-50 text-sky-700 ring-sky-200',
        'warning' => 'bg-amber-50 text-amber-800 ring-amber-200',
        'danger', 'error' => 'bg-rose-50 text-rose-700 ring-rose-200',
        default => 'bg-slate-100 text-slate-600 ring-slate-200',
    };

    $label = match (true) {
        $action === 'OPEN' => 'Open',
        $action === 'WAITING' => 'Waiting',
        $action === 'DATA PROVIDER ERROR' => 'Provider error',
        $status === 'warning' => 'Warning',
        $status === 'danger' || $status === 'error' => 'Error',
        default => 'Info',
    };

    $tagText = strtoupper($status !== '' ? $status : 'INFO');

    $icon = match (true) {
        $action === 'OPEN' => 'trend',
        $action === 'WAITING' => 'clock',
        $action === 'DATA PROVIDER ERROR' || $status === 'danger' || $status === 'error' => 'x',
        $status === 'warning' => 'alert',
        default => 'info',
    };

    $primary = $price !== '' ? $price : (($symbol !== '' && $tf !== '') ? "{$symbol} {$tf}" : ($title !== '' ? $title : 'Notification'));

    $secondary = '';
    if ($action === 'OPEN') {
        $parts = array_values(array_filter([$symbol ?: null, $tf ?: null, $side ?: null]));
        $secondary = implode(' • ', $parts);
    } elseif ($action === 'WAITING') {
        $parts = array_values(array_filter([$symbol ?: null, $tf ?: null]));
        $secondary = implode(' • ', $parts);
        if ($reason !== '') {
            $secondary = trim($secondary . ' • ' . \Illuminate\Support\Str::limit($reason, 64));
        } elseif ($msg !== '') {
            $secondary = trim($secondary . ' • ' . \Illuminate\Support\Str::limit($msg, 64));
        }
    } else {
        $secondary = $msg !== '' ? \Illuminate\Support\Str::limit($msg, 72) : '';
    }

    $showSymbolTf = $symbol !== '' && $tf !== '';



@endphp

@php
    $date = $getDate(); // может быть Carbon/DateTime/string/null
    $ago = '';

    if ($date instanceof \DateTimeInterface) {
        $ago = \Illuminate\Support\Carbon::instance($date)->diffForHumans();
    } elseif (is_string($date) && trim($date) !== '') {
        $ago = \Illuminate\Support\Carbon::parse($date)->diffForHumans();
    }


@endphp


<article class="rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
    <div class="flex items-center justify-between">
        <div class="flex items-center gap-2">
            <span class="inline-flex h-5 w-5 items-center justify-center rounded-md bg-slate-100 text-slate-700">
                @if ($icon === 'trend')
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-8 8"/>
                    </svg>
                @elseif ($icon === 'clock')
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                @elseif ($icon === 'alert')
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z"/>
                    </svg>
                @elseif ($icon === 'x')
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                @else
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                @endif
            </span>

            <p class="text-xs font-medium text-slate-500">
                {{ $label }}
            </p>

            @if ($showSymbolTf)
                <span class="text-xs text-slate-400">
                    {{ $symbol }} / {{ $tf }}
                </span>
            @endif
        </div>

        <div class="flex items-center gap-2">
            @if ($ago !== '')
                <span class="text-xs text-slate-400">{{ $ago }}</span>
            @endif

            <span class="rounded-full px-2 py-0.5 text-xs ring-1 {{ $chip }}">
                {{ $tagText }}
            </span>
        </div>
    </div>

    <p class="mt-2 text-lg font-semibold text-slate-900">
        {{ $primary }}
    </p>

    @if ($secondary !== '')
        <p class="mt-1 text-xs text-slate-500">
            {{ $secondary }}
        </p>
    @endif
</article>
