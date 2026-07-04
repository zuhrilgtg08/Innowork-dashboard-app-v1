<div class="space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h2 class="text-2xl font-extrabold text-gray-900 dark:text-white">Settings</h2>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Konfigurasi sistem QC &amp; model deteksi.</p>
        </div>
        @if ($saved)
            <span class="rounded-full bg-green-100 px-3 py-1.5 text-xs font-semibold text-green-700 dark:bg-green-500/15 dark:text-green-400">Tersimpan {{ $saved }}</span>
        @endif
    </div>

    <form wire:submit="save" class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <!-- General -->
        <div class="card p-5">
            <h3 class="font-bold text-gray-900 dark:text-white">General</h3>
            <p class="text-xs text-gray-400">Identitas &amp; lokal aplikasi.</p>
            <div class="mt-5 space-y-4">
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Application Name</label>
                    <input wire:model="appName" type="text" class="field" />
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Timezone</label>
                    <select wire:model="timezone" class="field py-2.5">
                        <option value="Asia/Jakarta">Asia/Jakarta (WIB)</option>
                        <option value="Asia/Makassar">Asia/Makassar (WITA)</option>
                        <option value="Asia/Jayapura">Asia/Jayapura (WIT)</option>
                        <option value="UTC">UTC</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- Detection -->
        <div class="card p-5">
            <h3 class="font-bold text-gray-900 dark:text-white">Detection Model</h3>
            <p class="text-xs text-gray-400">Ambang keputusan &amp; otomasi.</p>
            <div class="mt-5 space-y-4">
                <div>
                    <label class="mb-1 flex items-center justify-between text-sm font-medium text-gray-700 dark:text-gray-300">
                        <span>Confidence Threshold</span>
                        <span class="font-semibold text-brand-600 dark:text-brand-400">{{ number_format($confidenceThreshold * 100) }}%</span>
                    </label>
                    <input wire:model.live="confidenceThreshold" type="range" min="0.5" max="1" step="0.01" class="w-full accent-brand-600" />
                </div>
                <label class="flex items-center justify-between">
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Auto retrain nightly</span>
                    <input wire:model="autoRetrain" type="checkbox" class="h-5 w-5 rounded border-gray-300 text-brand-600 focus:ring-brand-500" />
                </label>
                <label class="flex items-center justify-between">
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Auto-reject on damage</span>
                    <input wire:model="rejectOnDamage" type="checkbox" class="h-5 w-5 rounded border-gray-300 text-brand-600 focus:ring-brand-500" />
                </label>
            </div>
        </div>

        <!-- Notifications -->
        <div class="card p-5">
            <h3 class="font-bold text-gray-900 dark:text-white">Notifications</h3>
            <p class="text-xs text-gray-400">Peringatan operasional.</p>
            <div class="mt-5 space-y-4">
                <label class="flex items-center justify-between">
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Email alerts</span>
                    <input wire:model="emailAlerts" type="checkbox" class="h-5 w-5 rounded border-gray-300 text-brand-600 focus:ring-brand-500" />
                </label>
                <p class="text-xs text-gray-400">Kirim ringkasan defect harian ke supervisor QC.</p>
            </div>
        </div>

        <!-- Actions -->
        <div class="card flex flex-col justify-between p-5">
            <div>
                <h3 class="font-bold text-gray-900 dark:text-white">Save Changes</h3>
                <p class="text-xs text-gray-400">Perubahan diterapkan ke seluruh stasiun.</p>
            </div>
            <div class="mt-5 flex gap-3">
                <button type="submit" class="btn-primary" wire:loading.attr="disabled" wire:target="save">
                    <span wire:loading.remove wire:target="save">Save Settings</span>
                    <span wire:loading wire:target="save">Saving…</span>
                </button>
                <button type="button" wire:click="resetForm" class="btn-secondary">Reset</button>
            </div>
        </div>
    </form>
</div>
