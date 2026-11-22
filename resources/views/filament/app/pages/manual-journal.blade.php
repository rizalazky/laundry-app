<x-filament-panels::page>
    <form wire:submit="createJournal" class="space-y-6">
        <div class="grid gap-4 rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900 md:grid-cols-2">
            <div>
                <label class="mb-1 block text-sm font-medium">Tanggal Jurnal</label>
                <input type="datetime-local" wire:model="journalDate" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800">
                @error('journalDate') <p class="mt-1 text-sm text-danger-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium">Cabang</label>
                <select wire:model="branchId" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800">
                    @foreach ($this->getBranchOptions() as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
                @error('branchId') <p class="mt-1 text-sm text-danger-600">{{ $message }}</p> @enderror
            </div>
            <div class="md:col-span-2">
                <label class="mb-1 block text-sm font-medium">Deskripsi Jurnal</label>
                <input type="text" wire:model="description" maxlength="2000" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800" placeholder="Contoh: Penyesuaian saldo kas awal">
                @error('description') <p class="mt-1 text-sm text-danger-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium">Tipe Referensi (opsional)</label>
                <input type="text" wire:model="referenceType" maxlength="100" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800" placeholder="opening_balance">
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium">ID Referensi (opsional)</label>
                <input type="number" min="1" wire:model="referenceId" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800">
            </div>
        </div>

        <div class="overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 p-4 dark:border-gray-700">
                <div>
                    <h3 class="font-semibold">Rincian Debit & Kredit</h3>
                    <p class="text-sm text-gray-500 dark:text-gray-400">Total debit harus sama dengan total kredit.</p>
                </div>
                <x-filament::button type="button" color="gray" outlined icon="heroicon-o-plus" wire:click="addEntry">Tambah Baris</x-filament::button>
            </div>
            <div class="space-y-3 p-4">
                @foreach ($entries as $index => $entry)
                    <div wire:key="manual-journal-entry-{{ $index }}" class="grid gap-3 rounded-lg border border-gray-200 p-3 dark:border-gray-700 md:grid-cols-[minmax(0,2fr)_1fr_1fr_auto]">
                        <div>
                            <label class="mb-1 block text-xs font-medium">Akun</label>
                            <select wire:model="entries.{{ $index }}.account_code" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800">
                                <option value="">Pilih akun...</option>
                                @foreach ($this->getAccountOptions() as $code => $label)
                                    <option value="{{ $code }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            @error("entries.$index.account_code") <p class="mt-1 text-xs text-danger-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium">Debit</label>
                            <input type="number" min="0" step="0.01" wire:model="entries.{{ $index }}.debit" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800">
                            @error("entries.$index.debit") <p class="mt-1 text-xs text-danger-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium">Kredit</label>
                            <input type="number" min="0" step="0.01" wire:model="entries.{{ $index }}.credit" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800">
                            @error("entries.$index.credit") <p class="mt-1 text-xs text-danger-600">{{ $message }}</p> @enderror
                        </div>
                        <div class="flex items-end">
                            <x-filament::button type="button" color="danger" outlined icon="heroicon-o-trash" wire:click="removeEntry({{ $index }})" :disabled="count($entries) <= 2">Hapus</x-filament::button>
                        </div>
                    </div>
                @endforeach
                @error('entries') <p class="text-sm text-danger-600">{{ $message }}</p> @enderror
            </div>
            <div class="grid gap-3 border-t border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-800 sm:grid-cols-2">
                <div>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Total Debit</p>
                    <p class="text-lg font-semibold tabular-nums">{{ $this->formatMoney($this->totalDebit()) }}</p>
                </div>
                <div>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Total Kredit</p>
                    <p class="text-lg font-semibold tabular-nums">{{ $this->formatMoney($this->totalCredit()) }}</p>
                </div>
            </div>
        </div>

        <div class="flex justify-end">
            <x-filament::button type="submit" icon="heroicon-o-check" color="success">Simpan Jurnal</x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
