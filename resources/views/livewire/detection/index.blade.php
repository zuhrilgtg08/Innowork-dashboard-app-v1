<div class="space-y-6" x-data="window.detection()" x-init="init()">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h2 class="text-2xl font-extrabold text-gray-900 dark:text-white">Real-Time Object Detection</h2>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                Inferensi di browser menggunakan TensorFlow.js &amp; COCO-SSD.
            </p>
        </div>
        <div class="flex items-center gap-2">
            <span class="inline-flex items-center gap-2 rounded-full bg-green-100 px-3 py-1.5 text-xs font-semibold text-green-700 dark:bg-green-500/15 dark:text-green-400">
                <span class="relative flex h-2 w-2">
                    <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-green-500 opacity-75"></span>
                    <span class="relative inline-flex h-2 w-2 rounded-full bg-green-500"></span>
                </span>
                Client-Side AI
            </span>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-4">
        <!-- Camera + Overlay -->
        <div class="lg:col-span-3">
            <div class="card overflow-hidden">
                <div class="relative aspect-video bg-gray-900">
                    <div :class="mirrored ? '-scale-x-100' : ''" class="h-full w-full">
                        <video x-ref="video" autoplay playsinline muted
                               class="h-full w-full object-cover"
                               x-show="active" style="display:none;"></video>
                        <canvas x-ref="canvas"
                                class="absolute inset-0 h-full w-full"
                                x-show="active" style="display:none;"></canvas>
                    </div>

                    <!-- Idle / Error -->
                    <div x-show="!active" class="absolute inset-0 flex flex-col items-center justify-center gap-3 text-gray-500">
                        <template x-if="!error">
                            <svg class="h-12 w-12" fill="none" viewBox="0 0 24 24" stroke-width="1.4" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m15.75 10.5 4.72-4.72a.75.75 0 0 1 1.28.53v11.38a.75.75 0 0 1-1.28.53l-4.72-4.72M4.5 18.75h9a2.25 2.25 0 0 0 2.25-2.25v-9a2.25 2.25 0 0 0-2.25-2.25h-9A2.25 2.25 0 0 0 2.25 7.5v9a2.25 2.25 0 0 0 2.25 2.25Z"/>
                            </svg>
                        </template>
                        <p class="text-sm font-medium" x-text="error || 'Kamera nonaktif'"></p>
                        <button @click="start()" x-show="!error && !modelReady" disabled class="rounded-lg bg-brand-600 px-4 py-2 text-xs font-semibold text-white opacity-60">
                            Memuat model...
                        </button>
                        <button @click="start()" x-show="!error && modelReady" class="rounded-lg bg-brand-600 px-4 py-2 text-xs font-semibold text-white transition hover:bg-brand-700 focus:outline-none focus:ring-2 focus:ring-brand-400 focus:ring-offset-2 focus:ring-offset-gray-900">
                            Aktifkan Kamera
                        </button>
                        <p x-show="loading" class="text-xs text-gray-400">Sedang memuat COCO-SSD model...</p>
                    </div>

                    <!-- LIVE badge -->
                    <span x-show="active" class="absolute left-3 top-3 inline-flex items-center gap-1.5 rounded bg-black/60 px-2 py-1 text-[11px] font-semibold uppercase tracking-wider text-white">
                        <span class="h-1.5 w-1.5 animate-pulse rounded-full bg-red-500"></span> LIVE
                    </span>

                    <!-- Camera label -->
                    <span x-show="active" class="absolute right-3 top-3 rounded bg-black/60 px-2 py-1 font-mono text-[11px] text-white">
                        {{ $camera }} · {{ $conveyor }}
                    </span>

                    <!-- Bottom-left stats -->
                    <div x-show="active" class="absolute bottom-3 left-3 rounded-lg bg-black/60 px-3 py-1.5 text-xs font-semibold text-white">
                        FPS: <span x-text="stats.fps"></span>
                        <span class="mx-1.5 text-gray-400">|</span>
                        Inference: <span x-text="stats.inferenceMs + ' ms'"></span>
                        <span class="mx-1.5 text-gray-400">|</span>
                        Objek: <span x-text="stats.objects"></span>
                    </div>
                </div>

                <!-- Controls -->
                <div class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <p class="font-bold text-gray-900 dark:text-white">
                            Perangkat: <span x-text="deviceLabel || '—'"></span>
                        </p>
                        <p class="text-xs text-gray-400">
                            Confidence threshold: <span x-text="(minConfidence * 100) + '%'"></span>
                            <input type="range" min="10" max="100" step="5" x-model="minConfidence"
                                   class="ml-2 h-1 w-24 rounded-lg appearance-none bg-gray-200 dark:bg-gray-700 accent-brand-600">
                        </p>
                    </div>
                    <div class="flex gap-2">
                        <button @click="toggleMirror()" x-show="active"
                                class="rounded-lg bg-gray-100 px-4 py-2 text-xs font-semibold text-gray-600 transition hover:bg-gray-200 focus:outline-none focus:ring-2 focus:ring-gray-400 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600">
                            Flip
                        </button>
                        <button @click="stop()" x-show="active"
                                class="rounded-lg bg-red-50 px-4 py-2 text-xs font-semibold text-red-700 transition hover:bg-red-100 focus:outline-none focus:ring-2 focus:ring-red-400 dark:bg-red-500/10 dark:text-red-400 dark:hover:bg-red-500/20">
                            Stop
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sidebar: Detected objects list -->
        <div class="card flex flex-col">
            <div class="border-b border-gray-100 p-4 dark:border-gray-700">
                <h3 class="font-bold text-gray-900 dark:text-white">Deteksi Langsung</h3>
                <p class="text-xs text-gray-400">Objek terdeteksi di frame terbaru</p>
            </div>
            <div class="scrollbar-thin flex-1 divide-y divide-gray-100 overflow-y-auto dark:divide-gray-700">
                <template x-if="detections.length === 0">
                    <div class="p-4 text-xs text-gray-400">Belum ada objek terdeteksi.</div>
                </template>
                <template x-for="(d, i) in detections" :key="i">
                    <div class="flex items-center justify-between px-4 py-3">
                        <div class="flex items-center gap-2">
                            <span class="inline-flex h-2 w-2 rounded-full bg-brand-500"></span>
                            <span class="text-sm font-medium capitalize text-gray-800 dark:text-gray-200" x-text="d.class"></span>
                        </div>
                        <span class="text-xs font-semibold text-gray-500 dark:text-gray-400" x-text="Math.round(d.score * 100) + '%'"></span>
                    </div>
                </template>
            </div>
        </div>
    </div>
</div>
