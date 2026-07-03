<?php

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;

/**
 * (Re)generates QR SVGs for products. Useful after the QR payload format
 * changed to the public scan URL (/p/{token}) — run once to refresh old codes.
 */
class RegenerateQrCommand extends Command
{
    protected $signature = 'sortvision:regenerate-qr {--missing : Only products without an existing QR}';

    protected $description = 'Regenerate product QR codes as SVG on the public disk';

    public function handle(): int
    {
        $query = Product::query();

        if ($this->option('missing')) {
            $query->whereNull('qr_path');
        }

        $products = $query->get();

        if ($products->isEmpty()) {
            $this->info('No products to process.');

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($products->count());
        $bar->start();

        foreach ($products as $product) {
            $product->regenerateQr();
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("Regenerated QR for {$products->count()} product(s).");

        return self::SUCCESS;
    }
}
