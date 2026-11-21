<x-filament-panels::page>
    @php
        $reportData = $this->getReportData();
        $isError = is_array($reportData) && isset($reportData['error']);
        $tabs = [
            'general-journal' => 'Jurnal Umum',
            'general-ledger' => 'Buku Besar',
            'income-statement' => 'Laba Rugi',
            'balance-sheet' => 'Neraca',
        ];
    @endphp

    <style>
        .accounting-reports { display: flex; flex-direction: column; gap: 1.25rem; }
        .accounting-reports__intro { display: flex; flex-direction: column; gap: .5rem; }
        .accounting-reports__intro h2 { margin: 0; font-size: 1rem; font-weight: 600; }
        .accounting-reports__intro p { margin: 0; color: var(--gray-500); font-size: .875rem; line-height: 1.5rem; }
        .accounting-reports__filters { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 1rem; align-items: end; }
        .accounting-reports__field label { display: block; margin-bottom: .45rem; font-size: .875rem; font-weight: 500; }
        .accounting-reports__tabs { display: flex; flex-wrap: wrap; gap: .5rem; border-bottom: 1px solid var(--fi-border-color, #e5e7eb); padding-bottom: .75rem; }
        .accounting-reports__tab { border: 1px solid var(--fi-border-color, #e5e7eb); border-radius: .5rem; padding: .55rem .9rem; color: var(--gray-600); font-size: .875rem; font-weight: 500; }
        .accounting-reports__tab[aria-current="page"] { border-color: var(--primary-600); background: var(--primary-50); color: var(--primary-700); }
        .dark .accounting-reports__tab[aria-current="page"] { background: rgb(245 158 11 / .12); color: var(--primary-300); }
        .accounting-reports__table-wrap { overflow-x: auto; }
        .accounting-reports__table { width: 100%; border-collapse: collapse; font-size: .875rem; }
        .accounting-reports__table th { padding: .75rem 1rem; text-align: left; color: var(--gray-500); font-size: .75rem; font-weight: 600; white-space: nowrap; }
        .accounting-reports__table td { padding: .75rem 1rem; border-top: 1px solid var(--fi-border-color, #e5e7eb); vertical-align: top; }
        .accounting-reports__table .numeric { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
        .accounting-reports__table .subrow td { padding-top: .4rem; padding-bottom: .4rem; color: var(--gray-500); font-size: .8125rem; }
        .accounting-reports__totals { display: flex; flex-wrap: wrap; gap: 1rem; }
        .accounting-reports__total { flex: 1 1 12rem; min-width: 0; border: 1px solid var(--fi-border-color, #e5e7eb); border-radius: .75rem; padding: 1rem; }
        .accounting-reports__total span { display: block; color: var(--gray-500); font-size: .75rem; }
        .accounting-reports__total strong { display: block; margin-top: .35rem; font-size: 1.125rem; font-weight: 600; font-variant-numeric: tabular-nums; }
        .accounting-reports__section-title { margin: 0; font-size: .9375rem; font-weight: 600; }
        .accounting-reports__empty { padding: 2rem 1rem; color: var(--gray-500); text-align: center; font-size: .875rem; }
        .accounting-reports__error { color: var(--danger-600); font-size: .875rem; }
        .accounting-reports__status { display: flex; align-items: center; justify-content: space-between; gap: 1rem; }
        @media (max-width: 900px) { .accounting-reports__filters { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (max-width: 600px) { .accounting-reports__filters { grid-template-columns: minmax(0, 1fr); } .accounting-reports__status { align-items: flex-start; flex-direction: column; } }
    </style>

    <div class="accounting-reports">
        <x-filament::section>
            <div class="accounting-reports__intro">
                <h2>Laporan keuangan berbasis jurnal</h2>
                <p>
                    Seluruh laporan bersifat read-only dan dihitung dari jurnal yang sudah diposting.
                    Pilih periode untuk melihat posisi keuangan tenant dan cabang.
                </p>
            </div>
        </x-filament::section>

        <x-filament::section>
            <div class="accounting-reports__filters">
                <div class="accounting-reports__field">
                    <label for="report-from-date">Dari tanggal</label>
                    <x-filament::input.wrapper>
                        <x-filament::input id="report-from-date" type="date" wire:model.live="fromDate" />
                    </x-filament::input.wrapper>
                </div>

                <div class="accounting-reports__field">
                    <label for="report-to-date">{{ $reportType === 'balance-sheet' ? 'Posisi per tanggal' : 'Sampai tanggal' }}</label>
                    <x-filament::input.wrapper>
                        <x-filament::input id="report-to-date" type="date" wire:model.live="toDate" />
                    </x-filament::input.wrapper>
                </div>

                @if (auth()->user()?->role === 'tenant_owner')
                    <div class="accounting-reports__field">
                        <label for="report-branch">Cabang</label>
                        <x-filament::input.wrapper>
                            <x-filament::input.select id="report-branch" wire:model.live="branchId">
                                <option value="all">Semua cabang</option>
                                @foreach ($this->getBranchOptions() as $id => $name)
                                    <option value="{{ $id }}">{{ $name }}</option>
                                @endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                    </div>
                @endif

                @if ($reportType === 'general-ledger')
                    <div class="accounting-reports__field">
                        <label for="report-account">Akun</label>
                        <x-filament::input.wrapper>
                            <x-filament::input.select id="report-account" wire:model.live="accountId">
                                <option value="">Pilih akun</option>
                                @foreach ($this->getAccountOptions() as $id => $label)
                                    <option value="{{ $id }}">{{ $label }}</option>
                                @endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                    </div>
                @endif
            </div>
        </x-filament::section>

        <x-filament::section>
            <div class="accounting-reports__tabs" role="tablist" aria-label="Jenis laporan akuntansi">
                @foreach ($tabs as $key => $label)
                    <button
                        type="button"
                        class="accounting-reports__tab"
                        aria-current="{{ $reportType === $key ? 'page' : 'false' }}"
                        wire:click="$set('reportType', '{{ $key }}')"
                    >
                        {{ $label }}
                    </button>
                @endforeach
            </div>

            <div wire:loading.delay class="accounting-reports__empty">Memuat laporan...</div>

            <div wire:loading.remove>
                @if ($isError)
                    <p class="accounting-reports__empty accounting-reports__error">{{ $reportData['error'] }}</p>
                @elseif ($reportType === 'general-journal')
                    @if ($reportData->isEmpty())
                        <div class="accounting-reports__empty">Tidak ada jurnal pada periode yang dipilih.</div>
                    @else
                        <div class="accounting-reports__table-wrap">
                            <table class="accounting-reports__table">
                                <thead>
                                    <tr><th>Tanggal</th><th>Deskripsi / Akun</th><th>Referensi</th><th class="numeric">Debit</th><th class="numeric">Kredit</th></tr>
                                </thead>
                                <tbody>
                                    @foreach ($reportData as $journal)
                                        <tr>
                                            <td>{{ $journal->journal_date?->format('d/m/Y H:i') }}</td>
                                            <td><strong>{{ $journal->description ?: 'Jurnal #' . $journal->id }}</strong></td>
                                            <td>{{ $journal->reference_type ? $journal->reference_type . ' #' . $journal->reference_id : '—' }}</td>
                                            <td></td><td></td>
                                        </tr>
                                        @foreach ($journal->entries as $entry)
                                            <tr class="subrow">
                                                <td></td>
                                                <td>{{ $entry->account?->code }} — {{ $entry->account?->name ?? 'Akun tidak tersedia' }}</td>
                                                <td></td>
                                                <td class="numeric">{{ $this->formatMoney($entry->debit) }}</td>
                                                <td class="numeric">{{ $this->formatMoney($entry->credit) }}</td>
                                            </tr>
                                        @endforeach
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                @elseif ($reportType === 'general-ledger')
                    <div class="accounting-reports__status">
                        <div>
                            <h3 class="accounting-reports__section-title">{{ $reportData['account']->code ?? '' }} — {{ $reportData['account']->name ?? '' }}</h3>
                            <p class="text-sm text-gray-500">Periode {{ $fromDate }} sampai {{ $toDate }}</p>
                        </div>
                    </div>
                    <div class="accounting-reports__totals" style="margin-top: 1rem;">
                        <div class="accounting-reports__total"><span>Saldo awal</span><strong>{{ $this->formatMoney($reportData['opening_balance']) }}</strong></div>
                        <div class="accounting-reports__total"><span>Total debit</span><strong>{{ $this->formatMoney($reportData['total_debit']) }}</strong></div>
                        <div class="accounting-reports__total"><span>Total kredit</span><strong>{{ $this->formatMoney($reportData['total_credit']) }}</strong></div>
                        <div class="accounting-reports__total"><span>Saldo akhir</span><strong>{{ $this->formatMoney($reportData['closing_balance']) }}</strong></div>
                    </div>
                    @if ($reportData['entries']->isEmpty())
                        <div class="accounting-reports__empty">Tidak ada mutasi akun pada periode yang dipilih.</div>
                    @else
                        <div class="accounting-reports__table-wrap" style="margin-top: 1rem;">
                            <table class="accounting-reports__table">
                                <thead><tr><th>Tanggal</th><th>Jurnal</th><th class="numeric">Debit</th><th class="numeric">Kredit</th><th class="numeric">Saldo berjalan</th></tr></thead>
                                <tbody>
                                    @foreach ($reportData['entries'] as $entry)
                                        <tr>
                                            <td>{{ $entry->journal?->journal_date?->format('d/m/Y H:i') }}</td>
                                            <td>{{ $entry->journal?->description }}</td>
                                            <td class="numeric">{{ $this->formatMoney($entry->debit) }}</td>
                                            <td class="numeric">{{ $this->formatMoney($entry->credit) }}</td>
                                            <td class="numeric"><strong>{{ $this->formatMoney($entry->running_balance) }}</strong></td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                @elseif ($reportType === 'income-statement')
                    <div class="accounting-reports__totals" style="margin-bottom: 1rem;">
                        <div class="accounting-reports__total"><span>Total pendapatan</span><strong>{{ $this->formatMoney($reportData['total_revenue']) }}</strong></div>
                        <div class="accounting-reports__total"><span>Total biaya</span><strong>{{ $this->formatMoney($reportData['total_expenses']) }}</strong></div>
                        <div class="accounting-reports__total"><span>Laba / rugi bersih</span><strong>{{ $this->formatMoney($reportData['net_income']) }}</strong></div>
                    </div>
                    <div class="accounting-reports__table-wrap">
                        <table class="accounting-reports__table">
                            <thead><tr><th>Akun</th><th class="numeric">Jumlah</th></tr></thead>
                            <tbody>
                                <tr><td colspan="2"><strong>Pendapatan</strong></td></tr>
                                @forelse ($reportData['revenue_accounts'] as $row)
                                    <tr><td>{{ $row['account']->code }} — {{ $row['account']->name }}</td><td class="numeric">{{ $this->formatMoney($row['amount']) }}</td></tr>
                                @empty
                                    <tr><td colspan="2" class="accounting-reports__empty">Belum ada pendapatan.</td></tr>
                                @endforelse
                                <tr><td><strong>Total pendapatan</strong></td><td class="numeric"><strong>{{ $this->formatMoney($reportData['total_revenue']) }}</strong></td></tr>
                                <tr><td colspan="2"><strong>Biaya</strong></td></tr>
                                @forelse ($reportData['expense_accounts'] as $row)
                                    <tr><td>{{ $row['account']->code }} — {{ $row['account']->name }}</td><td class="numeric">{{ $this->formatMoney($row['amount']) }}</td></tr>
                                @empty
                                    <tr><td colspan="2" class="accounting-reports__empty">Belum ada biaya.</td></tr>
                                @endforelse
                                <tr><td><strong>Total biaya</strong></td><td class="numeric"><strong>{{ $this->formatMoney($reportData['total_expenses']) }}</strong></td></tr>
                                <tr><td><strong>Laba / rugi bersih</strong></td><td class="numeric"><strong>{{ $this->formatMoney($reportData['net_income']) }}</strong></td></tr>
                            </tbody>
                        </table>
                    </div>
                @elseif ($reportType === 'balance-sheet')
                    <div class="accounting-reports__status" style="margin-bottom: 1rem;">
                        <h3 class="accounting-reports__section-title">Posisi keuangan per {{ $toDate }}</h3>
                        <x-filament::badge color="{{ $reportData['is_balanced'] ? 'success' : 'danger' }}">
                            {{ $reportData['is_balanced'] ? 'Seimbang' : 'Belum seimbang' }}
                        </x-filament::badge>
                    </div>
                    <div class="accounting-reports__totals" style="margin-bottom: 1rem;">
                        <div class="accounting-reports__total"><span>Total aset</span><strong>{{ $this->formatMoney($reportData['total_assets']) }}</strong></div>
                        <div class="accounting-reports__total"><span>Total liabilitas</span><strong>{{ $this->formatMoney($reportData['total_liabilities']) }}</strong></div>
                        <div class="accounting-reports__total"><span>Total ekuitas</span><strong>{{ $this->formatMoney($reportData['total_equity']) }}</strong></div>
                        <div class="accounting-reports__total"><span>Selisih</span><strong>{{ $this->formatMoney($reportData['difference']) }}</strong></div>
                    </div>
                    <div class="accounting-reports__table-wrap">
                        <table class="accounting-reports__table">
                            <thead><tr><th>Akun</th><th class="numeric">Saldo</th></tr></thead>
                            <tbody>
                                <tr><td colspan="2"><strong>Aset</strong></td></tr>
                                @forelse ($reportData['asset_accounts'] as $row)
                                    <tr><td>{{ $row['account']->code }} — {{ $row['account']->name }}</td><td class="numeric">{{ $this->formatMoney($row['amount']) }}</td></tr>
                                @empty
                                    <tr><td colspan="2" class="accounting-reports__empty">Belum ada aset.</td></tr>
                                @endforelse
                                <tr><td><strong>Total aset</strong></td><td class="numeric"><strong>{{ $this->formatMoney($reportData['total_assets']) }}</strong></td></tr>
                                <tr><td colspan="2"><strong>Liabilitas</strong></td></tr>
                                @forelse ($reportData['liability_accounts'] as $row)
                                    <tr><td>{{ $row['account']->code }} — {{ $row['account']->name }}</td><td class="numeric">{{ $this->formatMoney($row['amount']) }}</td></tr>
                                @empty
                                    <tr><td colspan="2" class="accounting-reports__empty">Belum ada liabilitas.</td></tr>
                                @endforelse
                                <tr><td><strong>Total liabilitas</strong></td><td class="numeric"><strong>{{ $this->formatMoney($reportData['total_liabilities']) }}</strong></td></tr>
                                <tr><td colspan="2"><strong>Ekuitas</strong></td></tr>
                                @forelse ($reportData['equity_accounts'] as $row)
                                    <tr><td>{{ $row['account']->code }} — {{ $row['account']->name }}</td><td class="numeric">{{ $this->formatMoney($row['amount']) }}</td></tr>
                                @empty
                                    <tr><td colspan="2" class="accounting-reports__empty">Belum ada ekuitas tercatat.</td></tr>
                                @endforelse
                                <tr><td>Laba tahun berjalan <span class="text-xs text-gray-500">(terhitung, tanpa jurnal penutupan)</span></td><td class="numeric">{{ $this->formatMoney($reportData['current_year_earnings']) }}</td></tr>
                                <tr><td><strong>Total liabilitas dan ekuitas</strong></td><td class="numeric"><strong>{{ $this->formatMoney($reportData['total_liabilities_and_equity']) }}</strong></td></tr>
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
