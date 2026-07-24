<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Collects the webcam frames captured by LiveCamera (storage/app/public/frames)
 * into a clean folder for upload to Roboflow — the raw material to retrain the
 * ultra-milk model on the real camera domain (closes the domain gap where the
 * Roboflow-only model detects nothing on the webcam feed).
 *
 * Blank/near-black frames (a warm-up capture artefact) are skipped so the
 * training set isn't polluted.
 */
class ExportFramesCommand extends Command
{
    protected $signature = 'sortvision:export-frames
        {--min-brightness=8 : Lewati frame lebih gelap dari nilai ini (0-255) — buang frame blank}
        {--min-size=6 : Lewati frame lebih kecil dari KB ini — buang capture rusak}';

    protected $description = 'Kumpulkan frame webcam (LiveCamera) ke storage/app/dataset-webcam untuk upload ke Roboflow';

    public function handle(): int
    {
        $source = storage_path('app/public/frames');
        $dest = storage_path('app/dataset-webcam');

        if (! File::isDirectory($source)) {
            $this->error("Folder frame tidak ada: {$source}");

            return self::FAILURE;
        }

        File::ensureDirectoryExists($dest);

        $files = collect(File::files($source))
            ->filter(fn ($f) => in_array(strtolower($f->getExtension()), ['jpg', 'jpeg', 'png'], true));

        if ($files->isEmpty()) {
            $this->info('Tidak ada frame untuk diekspor.');

            return self::SUCCESS;
        }

        $minBrightness = (float) $this->option('min-brightness');
        $minBytes = (int) $this->option('min-size') * 1024;

        $exported = 0;
        $skipped = 0;

        foreach ($files as $file) {
            if ($file->getSize() < $minBytes || $this->brightness($file->getPathname()) < $minBrightness) {
                $skipped++;

                continue;
            }

            File::copy($file->getPathname(), $dest.DIRECTORY_SEPARATOR.$file->getFilename());
            $exported++;
        }

        $this->info("Ekspor selesai: {$exported} frame → {$dest} ({$skipped} dilewati sebagai blank/rusak).");
        $this->line('Upload folder ini ke Roboflow, labeli passed/damaged, generate versi baru, retrain (lihat COLAB_TRAINING.md).');

        return self::SUCCESS;
    }

    /**
     * Mean brightness (0-255) of an image, downscaled for speed. Returns 0 if it
     * can't be read (treated as blank → skipped).
     */
    private function brightness(string $path): float
    {
        $img = @imagecreatefromstring((string) @file_get_contents($path));
        if ($img === false) {
            return 0.0;
        }

        $w = imagesx($img);
        $h = imagesy($img);
        $small = imagescale($img, 32, max(1, (int) (32 * $h / $w)));
        imagedestroy($img);
        if ($small === false) {
            return 0.0;
        }

        $sw = imagesx($small);
        $sh = imagesy($small);
        $sum = 0;
        for ($y = 0; $y < $sh; $y++) {
            for ($x = 0; $x < $sw; $x++) {
                $rgb = imagecolorat($small, $x, $y);
                $sum += (($rgb >> 16) & 0xFF) + (($rgb >> 8) & 0xFF) + ($rgb & 0xFF);
            }
        }
        imagedestroy($small);

        return $sum / ($sw * $sh * 3);
    }
}
