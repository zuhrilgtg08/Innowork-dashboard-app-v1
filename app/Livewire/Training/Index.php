<?php

namespace App\Livewire\Training;

use App\Models\Detection;
use App\Models\Product;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app', ['title' => 'Training'])]
class Index extends Component
{
    public function render()
    {
        // Dataset readiness is derived from labelled detections per product.
        $labelled = Detection::count();
        $products = Product::count();

        $classes = Detection::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $dataset = collect(Detection::STATUSES)->map(fn ($meta, $key) => [
            'label' => $meta['label'],
            'color' => $meta['color'],
            'count' => $classes[$key] ?? 0,
        ])->values();

        // Static illustrative training runs — no jobs table yet.
        $runs = [
            ['name' => 'yolov8-qc-v4', 'status' => 'completed', 'color' => 'green', 'accuracy' => 98.4, 'epochs' => 120, 'finished' => now()->subDays(2)],
            ['name' => 'yolov8-qc-v3', 'status' => 'completed', 'color' => 'green', 'accuracy' => 96.1, 'epochs' => 100, 'finished' => now()->subDays(9)],
            ['name' => 'defect-classifier-v2', 'status' => 'running', 'color' => 'blue', 'accuracy' => 91.7, 'epochs' => 64, 'finished' => null],
            ['name' => 'qr-reader-v1', 'status' => 'failed', 'color' => 'red', 'accuracy' => 0, 'epochs' => 12, 'finished' => now()->subDays(1)],
        ];

        return view('livewire.training.index', [
            'labelled' => $labelled,
            'products' => $products,
            'dataset' => $dataset,
            'runs' => $runs,
        ]);
    }
}
