<?php

namespace App\Livewire\Logs;

use App\Models\SystemLog;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app', ['title' => 'Logs Sistem'])]
class Index extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $level = '';

    #[Url]
    public string $source = '';

    public function updating($name): void
    {
        if (in_array($name, ['search', 'level', 'source'])) {
            $this->resetPage();
        }
    }

    public function render()
    {
        $logs = SystemLog::query()
            ->when($this->search, fn ($q) => $q->where('message', 'like', "%{$this->search}%"))
            ->when($this->level, fn ($q) => $q->where('level', $this->level))
            ->when($this->source, fn ($q) => $q->where('source', $this->source))
            ->latest('logged_at')
            ->paginate(20);

        $counts = SystemLog::query()
            ->selectRaw('level, count(*) as total')
            ->groupBy('level')
            ->pluck('total', 'level');

        return view('livewire.logs.index', [
            'logs' => $logs,
            'counts' => $counts,
            'levels' => SystemLog::LEVELS,
            'sources' => SystemLog::SOURCES,
        ]);
    }
}
