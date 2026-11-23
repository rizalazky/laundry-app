<x-filament-panels::page>
    @php
        $analytics = $this->getAnalytics();
        $hasError = isset($analytics['error']);
        $summary = $analytics['summary'] ?? ['orders' => 0, 'completed_orders' => 0, 'turnover' => '0.00', 'average_order_value' => '0.00'];
        $outlets = $analytics['outlets'] ?? [];
        $services = $analytics['services'] ?? [];
        $employees = $analytics['employees'] ?? [];
        $maxOutletTurnover = max(1, (float) collect($outlets)->max(fn ($row) => (float) $row['turnover']));
        $maxServiceValue = max(1, (float) collect($services)->max(fn ($row) => (float) $row['gross_value']));
    @endphp

    <style>
        .business-performance { display: flex; flex-direction: column; gap: 1.25rem; }
        .business-performance__intro { display: flex; flex-direction: column; gap: .35rem; }
        .business-performance__intro h2 { margin: 0; font-size: 1rem; font-weight: 600; }
        .business-performance__intro p { margin: 0; color: var(--gray-500); font-size: .875rem; line-height: 1.5rem; }
        .business-performance__filters { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 1rem; align-items: end; }
        .business-performance__field label { display: block; margin-bottom: .45rem; font-size: .875rem; font-weight: 500; }
        .business-performance__metrics { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 1rem; }
        .business-performance__metric { min-width: 0; border: 1px solid var(--fi-border-color, #e5e7eb); border-radius: .75rem; padding: 1rem; }
        .business-performance__metric span { display: block; color: var(--gray-500); font-size: .75rem; }
        .business-performance__metric strong { display: block; margin-top: .4rem; font-size: clamp(1rem, 2vw, 1.35rem); font-weight: 650; font-variant-numeric: tabular-nums; overflow-wrap: anywhere; }
        .business-performance__metric small { display: block; margin-top: .35rem; color: var(--gray-500); font-size: .75rem; }
        .business-performance__grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1.25rem; }
        .business-performance__section-title { margin: 0; font-size: .9375rem; font-weight: 600; }
        .business-performance__section-note { margin: .3rem 0 1rem; color: var(--gray-500); font-size: .75rem; line-height: 1.25rem; }
        .business-performance__bar-list { display: flex; flex-direction: column; gap: 1rem; }
        .business-performance__bar-head { display: flex; justify-content: space-between; gap: 1rem; margin-bottom: .4rem; font-size: .8125rem; }
        .business-performance__bar-head strong { font-weight: 600; text-align: right; font-variant-numeric: tabular-nums; }
        .business-performance__track { height: .55rem; overflow: hidden; border-radius: 999px; background: var(--gray-100); }
        .dark .business-performance__track { background: rgb(255 255 255 / .1); }
        .business-performance__fill { height: 100%; border-radius: inherit; background: var(--primary-500); }
        .business-performance__table-wrap { overflow-x: auto; }
        .business-performance__table { width: 100%; border-collapse: collapse; font-size: .8125rem; }
        .business-performance__table th { padding: .7rem .8rem; text-align: left; color: var(--gray-500); font-size: .7rem; font-weight: 600; white-space: nowrap; }
        .business-performance__table td { padding: .75rem .8rem; border-top: 1px solid var(--fi-border-color, #e5e7eb); vertical-align: middle; }
        .business-performance__table .numeric { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
        .business-performance__empty { padding: 1.75rem 1rem; color: var(--gray-500); text-align: center; font-size: .875rem; }
        .business-performance__error { color: var(--danger-600); }
        .business-performance__legend { display: flex; flex-wrap: wrap; gap: .5rem 1rem; margin-top: .8rem; color: var(--gray-500); font-size: .7rem; }
        @media (max-width: 900px) { .business-performance__metrics { grid-template-columns: repeat(2, minmax(0, 1fr)); } .business-performance__grid { grid-template-columns: minmax(0, 1fr); } }
        @media (max-width: 600px) { .business-performance__filters { grid-template-columns: minmax(0, 1fr); } .business-performance__metric { padding: .8rem; } }
    </style>

    <div class="business-performance">
        <x-filament::section>
            <div class="business-performance__intro">
                <h2>Ringkasan performa operasional</h2>
                <p>Pantau pesanan, omzet, kontribusi outlet dan layanan, serta aktivitas produksi karyawan berdasarkan periode yang dipilih.</p>
            </div>
        </x-filament::section>

        <x-filament::section>
            <div class="business-performance__filters">
                <div class="business-performance__field">
                    <label for="performance-from-date">Dari tanggal</label>
                    <x-filament::input.wrapper>
                        <x-filament::input id="performance-from-date" type="date" wire:model.live="fromDate" />
                    </x-filament::input.wrapper>
                </div>
                <div class="business-performance__field">
                    <label for="performance-to-date">Sampai tanggal</label>
                    <x-filament::input.wrapper>
                        <x-filament::input id="performance-to-date" type="date" wire:model.live="toDate" />
                    </x-filament::input.wrapper>
                </div>
                @if (auth()->user()?->role === 'tenant_owner')
                    <div class="business-performance__field">
                        <label for="performance-branch">Outlet</label>
                        <x-filament::input.wrapper>
                            <x-filament::input.select id="performance-branch" wire:model.live="branchId">
                                <option value="all">Semua outlet</option>
                                @foreach ($this->getBranchOptions() as $id => $name)
                                    <option value="{{ $id }}">{{ $name }}</option>
                                @endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                    </div>
                @endif
            </div>
        </x-filament::section>

        @if ($hasError)
            <x-filament::section>
                <div class="business-performance__empty business-performance__error">{{ $analytics['error'] }}</div>
            </x-filament::section>
        @else
            <div class="business-performance__metrics">
                <div class="business-performance__metric">
                    <span>Total omzet pesanan</span>
                    <strong>{{ $this->formatMoney($summary['turnover']) }}</strong>
                    <small>Bukan jumlah pembayaran yang diterima</small>
                </div>
                <div class="business-performance__metric">
                    <span>Total pesanan</span>
                    <strong>{{ number_format($summary['orders'], 0, ',', '.') }}</strong>
                    <small>{{ number_format($summary['completed_orders'], 0, ',', '.') }} pesanan selesai</small>
                </div>
                <div class="business-performance__metric">
                    <span>Rata-rata nilai pesanan</span>
                    <strong>{{ $this->formatMoney($summary['average_order_value']) }}</strong>
                    <small>Omzet ÷ jumlah pesanan</small>
                </div>
                <div class="business-performance__metric">
                    <span>Outlet terpantau</span>
                    <strong>{{ number_format(count($outlets), 0, ',', '.') }}</strong>
                    <small>Outlet dengan pesanan pada periode ini</small>
                </div>
            </div>

            <div class="business-performance__grid">
                <x-filament::section>
                    <h3 class="business-performance__section-title">Performa outlet</h3>
                    <p class="business-performance__section-note">Perbandingan omzet pesanan per outlet. Pilih satu outlet untuk melihat cakupan yang lebih spesifik.</p>
                    @forelse ($outlets as $outlet)
                        <div class="business-performance__bar-list" style="margin-bottom: 1rem;">
                            <div>
                                <div class="business-performance__bar-head">
                                    <span>{{ $outlet['branch_name'] }}</span>
                                    <strong>{{ $this->formatMoney($outlet['turnover']) }}</strong>
                                </div>
                                <div class="business-performance__track">
                                    <div class="business-performance__fill" style="width: {{ min(100, ((float) $outlet['turnover'] / $maxOutletTurnover) * 100) }}%"></div>
                                </div>
                                <div class="business-performance__legend">
                                    <span>{{ number_format($outlet['orders'], 0, ',', '.') }} pesanan</span>
                                    <span>{{ number_format($outlet['completed_orders'], 0, ',', '.') }} selesai</span>
                                    <span>Rata-rata {{ $this->formatMoney($outlet['average_order_value']) }}</span>
                                </div>
                            </div>
                        </div>
                    @empty
                        <div class="business-performance__empty">Belum ada pesanan pada periode ini.</div>
                    @endforelse
                </x-filament::section>

                <x-filament::section>
                    <h3 class="business-performance__section-title">Performa layanan</h3>
                    <p class="business-performance__section-note">Nilai layanan memakai subtotal item sebelum diskon tingkat nota.</p>
                    @forelse ($services as $service)
                        <div class="business-performance__bar-list" style="margin-bottom: 1rem;">
                            <div>
                                <div class="business-performance__bar-head">
                                    <span>{{ $service['service_name'] }}</span>
                                    <strong>{{ $this->formatMoney($service['gross_value']) }}</strong>
                                </div>
                                <div class="business-performance__track">
                                    <div class="business-performance__fill" style="width: {{ min(100, ((float) $service['gross_value'] / $maxServiceValue) * 100) }}%"></div>
                                </div>
                                <div class="business-performance__legend">
                                    <span>{{ number_format($service['orders'], 0, ',', '.') }} pesanan</span>
                                    <span>Qty {{ $service['quantity'] }}</span>
                                </div>
                            </div>
                        </div>
                    @empty
                        <div class="business-performance__empty">Belum ada layanan terjual pada periode ini.</div>
                    @endforelse
                </x-filament::section>
            </div>

            <x-filament::section>
                <h3 class="business-performance__section-title">Produktivitas karyawan</h3>
                <p class="business-performance__section-note">Aktivitas dihitung dari penugasan tahap produksi. Komisi dihitung berdasarkan tanggal komisi diperoleh; karyawan tetap ditampilkan meski tahap produksinya dimulai di luar periode laporan.</p>
                <div class="business-performance__table-wrap">
                    <table class="business-performance__table">
                        <thead>
                            <tr>
                                <th>Karyawan</th>
                                <th class="numeric">Pesanan ditangani</th>
                                <th class="numeric">Tahap produksi</th>
                                <th class="numeric">Tahap selesai</th>
                                <th class="numeric">Catatan kehadiran</th>
                                <th class="numeric">Komisi diperoleh</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($employees as $employee)
                                <tr>
                                    <td><strong>{{ $employee['employee_name'] }}</strong></td>
                                    <td class="numeric">{{ number_format($employee['assigned_orders'], 0, ',', '.') }}</td>
                                    <td class="numeric">{{ number_format($employee['production_stages'], 0, ',', '.') }}</td>
                                    <td class="numeric">{{ number_format($employee['completed_stages'], 0, ',', '.') }}</td>
                                    <td class="numeric">{{ number_format($employee['attendance_records'], 0, ',', '.') }}</td>
                                    <td class="numeric">{{ $this->formatMoney($employee['commission_earned']) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="business-performance__empty">Belum ada aktivitas produksi atau komisi pada periode ini.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
