<?php

namespace App\Livewire\Dashboard;

use App\Models\Trade;
use Illuminate\Contracts\Pagination\Paginator;
use Livewire\Component;
use Livewire\WithPagination;

class TradesTable extends Component
{
    use WithPagination;

    protected string $paginationTheme = 'tailwind';

    public function getTradesProperty(): Paginator
    {
        return Trade::query()
                    ->latest('id')
                    ->simplePaginate(10);
    }

    public function render()
    {
        return view('livewire.dashboard.trades-table', [
            'trades' => $this->trades,
        ]);
    }
}
