<div class="space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h2 class="text-2xl font-extrabold text-gray-900 dark:text-white">Live Camera</h2>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Satu kamera webcam yang tersinkron dengan dashboard.</p>
        </div>
        @if ($mlOnline)
            <span class="inline-flex items-center gap-2 rounded-full bg-green-100 px-3 py-1.5 text-xs font-semibold text-green-700 dark:bg-green-500/15 dark:text-green-400">
                <span class="relative flex h-2 w-2">
                    <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-green-500 opacity-75"></span>
                    <span class="relative inline-flex h-2 w-2 rounded-full bg-green-500"></span>
                </span>
                AI Service Online
            </span>
        @else
            <span class="inline-flex items-center gap-2 rounded-full bg-red-100 px-3 py-1.5 text-xs font-semibold text-red-700 dark:bg-red-500/15 dark:text-red-400">
                <span class="h-2 w-2 rounded-full bg-red-500"></span>
                AI Service Offline
            </span>
        @endif
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <!-- Single live camera card -->
        <div class="lg:col-span-2">
            <div class="card overflow-hidden"
                 x-data="webcam()"
                 x-init="init()">
                <div class="relative aspect-video bg-gray-900">
                    <!-- Live webcam feed -->
                    <video x-ref="video" autoplay playsinline muted
                           class="h-full w-full object-cover" x-show="active" style="display:none;"></video>
                    <canvas x-ref="canvas" class="hidden"></canvas>

                    <!-- Idle / error placeholder -->
                    <div x-show="!active" class="absolute inset-0 flex flex-col items-center justify-center gap-3 text-gray-500">
                        <svg class="h-12 w-12" fill="none" viewBox="0 0 24 24" stroke-width="1.4" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m15.75 10.5 4.72-4.72a.75.75 0 0 1 1.28.53v11.38a.75.75 0 0 1-1.28.53l-4.72-4.72M4.5 18.75h9a2.25 2.25 0 0 0 2.25-2.25v-9a2.25 2.25 0 0 0-2.25-2.25h-9A2.25 2.25 0 0 0 2.25 7.5v9a2.25 2.25 0 0 0 2.25 2.25Z" /></svg>
                        <p class="text-sm font-medium" x-text="error || 'Kamera nonaktif'"></p>
                        <button @click="start()" x-show="!error" class="rounded-lg bg-brand-600 px-4 py-2 text-xs font-semibold text-white transition hover:bg-brand-700 focus:outline-none focus:ring-2 focus:ring-brand-400 focus:ring-offset-2 focus:ring-offset-gray-900">Aktifkan Kamera</button>
                    </div>

                    <!-- Inspecting overlay -->
                    <div x-show="uploading" class="absolute inset-0 flex items-center justify-center bg-black/40">
                        <span class="rounded-lg bg-white/90 px-4 py-2 text-sm font-semibold text-gray-800">Menganalisa frame…</span>
                    </div>

                    <!-- Overlay badges -->
                    <span class="absolute left-3 top-3 inline-flex items-center gap-1.5 rounded bg-black/60 px-2 py-1 text-[11px] font-semibold uppercase tracking-wider text-white">
                        <span class="h-1.5 w-1.5 rounded-full bg-red-500" :class="active && 'animate-pulse'"></span>
                        <span x-text="active ? 'LIVE' : 'OFF'"></span>
                    </span>
                    <span class="absolute right-3 top-3 rounded bg-black/60 px-2 py-1 font-mono text-[11px] text-white">{{ $camera }} · {{ $conveyor }}</span>
                </div>

                <div class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <p class="font-bold text-gray-900 dark:text-white">{{ $camera }} — Webcam Utama</p>
                        <p class="text-xs text-gray-400">Perangkat: <span x-text="deviceLabel || '—'"></span></p>
                    </div>
                    <div class="flex gap-2">
                        <button @click="capture()" x-show="active" :disabled="uploading" aria-label="Ambil frame dan periksa"
                                class="rounded-lg bg-brand-600 px-4 py-2 text-xs font-semibold text-white transition hover:bg-brand-700 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2 disabled:opacity-50 dark:focus:ring-offset-gray-800">
                            <span x-show="!uploading">Capture &amp; Inspect</span>
                            <span x-show="uploading">Inspecting…</span>
                        </button>
                        <button @click="start()" x-show="!active" class="rounded-lg bg-brand-600 px-4 py-2 text-xs font-semibold text-white transition hover:bg-brand-700 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800">Start</button>
                        <button @click="stop()" x-show="active" class="rounded-lg bg-gray-100 px-4 py-2 text-xs font-semibold text-gray-600 transition hover:bg-gray-200 focus:outline-none focus:ring-2 focus:ring-gray-400 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600">Stop</button>
                    </div>
                </div>
            </div>

            @error('frame')
                <p class="mt-3 rounded-lg bg-red-50 px-4 py-2 text-sm font-medium text-red-700 dark:bg-red-500/10 dark:text-red-400">{{ $message }}</p>
            @enderror

            <!-- Last inference verdict -->
            @if ($lastResult && ($lastResult['status'] ?? '') !== 'error')
                <div class="card mt-4 flex items-center justify-between p-4">
                    <div class="flex items-center gap-3">
                        <x-status-badge :color="$lastResult['color'] ?? 'gray'" :label="$lastResult['label'] ?? ucfirst($lastResult['status'])" />
                        <div>
                            <p class="text-sm font-bold text-gray-900 dark:text-white">Hasil Inspeksi Terakhir</p>
                            <p class="text-xs text-gray-400">Confidence {{ number_format((float) ($lastResult['confidence'] ?? 0), 1) }}%</p>
                        </div>
                    </div>
                    @if ($lastResult['rejected'] ?? false)
                        <span class="rounded-full bg-red-100 px-3 py-1 text-xs font-bold text-red-700 dark:bg-red-500/15 dark:text-red-400">AUTO-REJECTED</span>
                    @endif
                </div>
            @endif

            <!-- Today's aggregate for this single camera -->
            <div class="mt-4 grid grid-cols-3 gap-4" wire:poll.5s>
                <div class="card p-4 text-center">
                    <p class="text-2xl font-extrabold text-gray-900 dark:text-white">{{ number_format($stats['total']) }}</p>
                    <p class="text-xs text-gray-400">Scan hari ini</p>
                </div>
                <div class="card p-4 text-center">
                    <p class="text-2xl font-extrabold text-green-600 dark:text-green-400">{{ number_format($stats['passed']) }}</p>
                    <p class="text-xs text-gray-400">Passed</p>
                </div>
                <div class="card p-4 text-center">
                    <p class="text-2xl font-extrabold text-red-600 dark:text-red-400">{{ number_format($stats['failed']) }}</p>
                    <p class="text-xs text-gray-400">Defect</p>
                </div>
            </div>
        </div>

        <!-- Detection feed -->
        <div class="card flex flex-col">
            <div class="border-b border-gray-100 p-4 dark:border-gray-700">
                <h3 class="font-bold text-gray-900 dark:text-white">Detection Feed</h3>
                <p class="text-xs text-gray-400">
                    @if ($stats['last_seen'])
                        Terakhir {{ \Illuminate\Support\Carbon::parse($stats['last_seen'])->diffForHumans() }}
                    @else
                        Belum ada deteksi
                    @endif
                </p>
            </div>
            <div class="scrollbar-thin flex-1 divide-y divide-gray-100 overflow-y-auto dark:divide-gray-700">
                @forelse ($feed as $item)
                    <div class="flex items-center gap-3 px-4 py-3">
                        <x-status-badge :color="$item->statusColor()" :label="$item->statusLabel()" />
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium text-gray-800 dark:text-gray-200">{{ $item->product?->name ?? $item->code }}</p>
                            <p class="text-xs text-gray-400">{{ $item->camera }} &middot; {{ $item->detected_at?->diffForHumans() }}</p>
                        </div>
                        <span class="text-xs font-semibold text-gray-500 dark:text-gray-400">{{ number_format((float) $item->confidence * 100) }}%</span>
                    </div>
                @empty
                    <x-empty-state title="Belum ada deteksi" message="Deteksi akan muncul di sini saat kamera memindai produk." />
                @endforelse
            </div>
        </div>
    </div>
</div>

@script
<script>
    Alpine.data('webcam', () => ({
        active: false,
        error: '',
        deviceLabel: '',
        uploading: false,
        stream: null,
        init() {
            // Auto-start on mount; browser will prompt for permission.
            this.start();
            // Release the camera when navigating away (Livewire SPA nav).
            document.addEventListener('livewire:navigating', () => this.stop(), { once: true });
        },
        async start() {
            this.error = '';
            if (!navigator.mediaDevices?.getUserMedia) {
                this.error = 'Browser tidak mendukung kamera';
                return;
            }
            try {
                this.stream = await navigator.mediaDevices.getUserMedia({ video: true, audio: false });
                this.$refs.video.srcObject = this.stream;
                this.deviceLabel = this.stream.getVideoTracks()[0]?.label || 'Webcam';
                this.active = true;
            } catch (e) {
                this.error = e.name === 'NotAllowedError' ? 'Akses kamera ditolak' : 'Kamera tidak tersedia';
                this.active = false;
            }
        },
        capture() {
            if (!this.active || this.uploading) return;
            const video = this.$refs.video;
            const canvas = this.$refs.canvas;
            canvas.width = video.videoWidth || 640;
            canvas.height = video.videoHeight || 480;
            canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
            this.uploading = true;
            canvas.toBlob((blob) => {
                const file = new File([blob], 'frame.jpg', { type: 'image/jpeg' });
                // Livewire temporary upload, then run inference on the stored frame.
                $wire.upload('frame', file, () => {
                    $wire.inferFrame().then(() => { this.uploading = false; });
                }, () => { this.uploading = false; });
            }, 'image/jpeg', 0.9);
        },
        stop() {
            this.stream?.getTracks().forEach(t => t.stop());
            this.stream = null;
            this.active = false;
        },
    }));
</script>
@endscript
