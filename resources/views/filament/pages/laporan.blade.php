@php
    $rp = fn ($v) => ($v < 0 ? '(' : '').number_format(abs($v), 0, ',', '.').($v < 0 ? ')' : '');
    $rpp = fn ($v) => 'Rp '.number_format($v, 0, ',', '.');
    $byDay = collect($ops['daily'])->groupBy('date')->map(fn ($g) => (int) $g->sum('total'))->sortKeys()->slice(-7);
    $maxDay = max($byDay->max() ?? 0, 1);
    $margin = $income['revenue'] > 0 ? round($income['net_income'] / $income['revenue'] * 100) : null;
    $oldest = collect($ops['aging']['rows'])->max('age_days');
    $sources = ['manual' => 'Manual (modal, biaya, transfer)', 'purchase' => 'Pembelian dan retur', 'service' => 'Servis', 'pos' => 'Kasir', 'transfer' => 'Transfer stok'];
    $buckets = ['0-30' => '0-30 hari', '31-60' => '31-60 hari', '61-90' => '61-90 hari', '>90' => 'Lebih dari 90 hari'];
    $bucketColors = ['0-30' => '#0B63F6', '31-60' => '#B45309', '61-90' => '#B45309', '>90' => '#B42318'];
    $bucketMax = max(array_sum($ops['aging']['buckets']), 1);
@endphp
<x-filament-panels::page>
    <section class="iz-card" style="display:flex;flex-wrap:wrap;gap:16px;align-items:flex-end">
        <div class="lp-field" style="flex:1 1 220px">
            <label>Cabang</label>
            <x-filament::input.wrapper>
                <x-filament::input.select wire:model.live="branchId">
                    <option value="">Semua cabang (konsolidasi)</option>
                    @foreach ($branches as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </div>
        <div class="lp-field" style="flex:1 1 180px"><label>Dari tanggal</label><x-filament::input.wrapper><x-filament::input type="date" wire:model.live="from" /></x-filament::input.wrapper></div>
        <div class="lp-field" style="flex:1 1 180px"><label>Sampai tanggal</label><x-filament::input.wrapper><x-filament::input type="date" wire:model.live="to" /></x-filament::input.wrapper></div>
        <div class="iz-task-sub" style="flex:2 1 260px;line-height:1.5;padding-bottom:4px">Neraca dihitung sampai tanggal akhir. Laba rugi dihitung antara dua tanggal. Laporan ikut berubah saat pilihan diganti.</div>
    </section>

    <nav class="lp-tabs" aria-label="Bagian laporan">
        <a href="#ringkasan" class="on">Ringkasan</a><a href="#laba-rugi">Laba rugi</a><a href="#neraca">Neraca</a><a href="#penjualan">Penjualan dan servis</a><a href="#arus-kas">Arus kas</a><a href="#utang">Utang</a><a href="#buku-besar">Buku besar dan stok</a><a href="#neraca-saldo">Neraca saldo</a>
    </nav>

    <div class="iz-kpis" id="ringkasan">
        <div class="iz-card iz-kpi"><div class="iz-kpi-label">Pendapatan</div><div class="iz-kpi-value">{{ $rpp($income['revenue']) }}</div><div class="iz-kpi-note" style="color:#5B6472">Selama rentang tanggal terpilih</div></div>
        <div class="iz-card iz-kpi"><div class="iz-kpi-label">{{ $income['net_income'] < 0 ? 'Rugi bersih' : 'Laba bersih' }}</div><div class="iz-kpi-value">{{ $rpp(abs($income['net_income'])) }}</div><div class="iz-kpi-note" style="color:{{ $income['net_income'] < 0 ? '#B42318' : '#0E7A4F' }}">{{ $margin !== null ? $margin.'% dari pendapatan' : 'Belum ada pendapatan' }}</div></div>
        <div class="iz-card iz-kpi"><div class="iz-kpi-label">Kas dan bank</div><div class="iz-kpi-value">{{ $rpp($ops['cashflow']['closing']) }}</div><div class="iz-kpi-note" style="color:#5B6472">Saldo akhir {{ $toLabel }}</div></div>
        <div class="iz-card iz-kpi"><div class="iz-kpi-label">Utang usaha</div><div class="iz-kpi-value">{{ $rpp($ops['payables']) }}</div><div class="iz-kpi-note" style="color:#B45309">{{ $oldest !== null ? 'Terlama '.$oldest.' hari' : 'Tidak ada utang' }}</div></div>
    </div>

    <div class="iz-row">
        <section class="iz-card iz-grow-1" id="laba-rugi">
            <h2>Laba rugi</h2>
            <div class="iz-task-sub" style="margin-bottom:12px">{{ $fromLabel }} sampai {{ $toLabel }}</div>
            <table class="lp-t">
                @foreach ($income['revenue_rows'] as $code => $v)<tr><td class="in">{{ $accounts[$code] }}</td><td class="n">{{ $rp($v) }}</td></tr>@endforeach
                <tr class="tot"><td>Total pendapatan</td><td class="n">{{ $rp($income['revenue']) }}</td></tr>
                <tr><td>Harga pokok penjualan</td><td class="n">{{ $rp(-$income['cogs']) }}</td></tr>
                <tr class="tot"><td>Laba kotor</td><td class="n">{{ $rp($income['gross_profit']) }}</td></tr>
                @foreach ($income['expense_rows'] as $code => $v)<tr><td class="in">{{ $accounts[$code] }}</td><td class="n">{{ $rp(-$v) }}</td></tr>@endforeach
                <tr class="tot big"><td>Laba (rugi) bersih</td><td class="n" style="color:{{ $income['net_income'] < 0 ? '#B42318' : '#0E7A4F' }}">{{ $rp($income['net_income']) }}</td></tr>
            </table>
            @if ($income['revenue'] > 0)
                <div class="lp-note" style="background:{{ $income['net_income'] < 0 ? '#FDECEA' : '#E6F4EC' }};color:{{ $income['net_income'] < 0 ? '#B42318' : '#0E7A4F' }}">
                    {{ $income['net_income'] < 0 ? 'Usaha rugi pada rentang ini. Cek beban yang paling besar.' : 'Usaha untung pada rentang ini. Dari tiap Rp100 penjualan, Rp'.$margin.' jadi laba.' }}
                </div>
            @endif
        </section>

        <section class="iz-card iz-grow-1" id="neraca">
            <h2>Neraca</h2>
            <div class="iz-task-sub" style="margin-bottom:12px">Posisi per {{ $toLabel }}</div>
            <div class="lp-sub">Yang dimiliki (aset)</div>
            <table class="lp-t" style="margin-bottom:14px">
                @foreach ($balance['asset_rows'] as $code => $v)<tr><td>{{ $accounts[$code] }}</td><td class="n">{{ $rp($v) }}</td></tr>@endforeach
                <tr class="tot"><td>Total aset</td><td class="n">{{ $rp($balance['assets']) }}</td></tr>
            </table>
            <div class="lp-sub">Yang harus dibayar dan modal</div>
            <table class="lp-t">
                @foreach ($balance['liability_rows'] as $code => $v)<tr><td>{{ $accounts[$code] }}</td><td class="n">{{ $rp($v) }}</td></tr>@endforeach
                @foreach ($balance['equity_rows'] as $code => $v)<tr><td>{{ $accounts[$code] }}</td><td class="n">{{ $rp($v) }}</td></tr>@endforeach
                <tr><td>Laba (rugi) berjalan</td><td class="n">{{ $rp($balance['current_earnings']) }}</td></tr>
                <tr class="tot"><td>Total liabilitas dan ekuitas</td><td class="n">{{ $rp($balance['liabilities_and_equity']) }}</td></tr>
            </table>
            @if ($balance['assets'] === $balance['liabilities_and_equity'])
                <div class="lp-note" style="background:#E8F0FE;color:#0B3A91">Seimbang: aset sama dengan kewajiban ditambah modal.</div>
            @else
                <div class="lp-note" style="background:#FDECEA;color:#B42318">Tidak seimbang. Hubungi konsultan IT, jangan lanjutkan tutup buku.</div>
            @endif
        </section>
    </div>

    <div class="iz-row" id="penjualan">
        <section class="iz-card iz-grow-15">
            <div class="iz-card-head"><h2>Penjualan kasir harian</h2><span>{{ $byDay->count() }} hari terakhir pada rentang ini</span></div>
            @if ($byDay->isNotEmpty())
                <div class="lp-bars">
                    @foreach ($byDay as $d => $v)
                        <div><span>{{ number_format($v / 1_000_000, 1, ',', '.') }} jt</span><i style="height:{{ max(6, round($v / $maxDay * 130)) }}px;background:{{ $v === $maxDay ? '#0B63F6' : '#C7DAFB' }}"></i><small>{{ \Carbon\Carbon::parse($d)->format('d/m') }}</small></div>
                    @endforeach
                </div>
            @endif
            <table class="lp-t lp-grid" style="margin-top:12px">
                <tr class="h"><td>Tanggal</td><td>Metode</td><td class="n">Struk</td><td class="n">Total</td><td class="n">Biaya QRIS</td></tr>
                @forelse ($ops['daily'] as $r)
                    <tr><td>{{ $r['date'] }}</td><td>{{ ['kas'=>'Tunai','bank'=>'Transfer','qris'=>'QRIS'][$r['method']] ?? $r['method'] }}</td><td class="n">{{ $r['receipts'] }}</td><td class="n">{{ $rp($r['total']) }}</td><td class="n">{{ $rp($r['mdr']) }}</td></tr>
                @empty
                    <tr><td colspan="5" class="iz-empty">Belum ada penjualan kasir pada rentang ini.</td></tr>
                @endforelse
            </table>
        </section>

        <section class="iz-card iz-grow-1" id="arus-kas">
            <h2 style="margin-bottom:4px">Arus kas</h2>
            <div class="iz-task-sub" style="margin-bottom:12px">Kas dan bank. Saldo awal {{ $rpp($ops['cashflow']['opening']) }}</div>
            <table class="lp-t">
                <tr class="h"><td>Sumber</td><td class="n">Masuk</td><td class="n">Keluar</td></tr>
                @foreach ($ops['cashflow']['rows'] as $r)
                    <tr><td>{{ $sources[$r['source']] ?? $r['source'] }}</td><td class="n">{{ $rp($r['inflow']) }}</td><td class="n">{{ $rp($r['outflow']) }}</td></tr>
                @endforeach
                <tr class="tot"><td colspan="2">Saldo akhir</td><td class="n">{{ $rp($ops['cashflow']['closing']) }}</td></tr>
            </table>
            <p class="iz-task-sub" style="margin:10px 0 0">Utang usaha per {{ $toLabel }}: Rp {{ number_format($ops['payables'], 0, ',', '.') }}</p>
        </section>
    </div>

    <div class="iz-row">
        <section class="iz-card iz-grow-15">
            <h2 style="margin-bottom:8px">Servis selesai per teknisi</h2>
            <table class="lp-t lp-grid">
                <tr class="h"><td>Teknisi</td><td class="n">Unit</td><td class="n">Jasa</td><td class="n">Sparepart</td><td class="n">Total</td></tr>
                @forelse ($ops['technicians'] as $r)
                    <tr><td style="font-weight:600">{{ $r['technician'] }}</td><td class="n">{{ $r['tickets'] }}</td><td class="n">{{ $rp($r['labor']) }}</td><td class="n">{{ $rp($r['parts']) }}</td><td class="n">{{ $rp($r['total']) }}</td></tr>
                @empty
                    <tr><td colspan="5" class="iz-empty">Belum ada servis selesai pada rentang ini.</td></tr>
                @endforelse
            </table>
        </section>

        <section class="iz-card iz-grow-1" id="utang">
            <h2 style="margin-bottom:4px">Umur utang usaha</h2>
            <div class="iz-task-sub" style="margin-bottom:16px">Per {{ $toLabel }}. Pembayaran melunasi pembelian kredit yang paling lama lebih dulu.</div>
            @foreach ($ops['aging']['buckets'] as $i => $v)
                <div style="margin-bottom:14px">
                    <div style="display:flex;justify-content:space-between;font-size:14px"><span>{{ $buckets[$i] ?? $i }}</span><span style="font-weight:600;font-variant-numeric:tabular-nums">{{ $rpp($v) }}</span></div>
                    <div class="iz-bar"><div style="background:{{ $bucketColors[$i] ?? '#0B63F6' }};width:{{ round($v / $bucketMax * 100) }}%"></div></div>
                </div>
            @endforeach
            @if ($ops['aging']['rows'])
                <table class="lp-t lp-grid" style="margin-top:8px">
                    <tr class="h"><td>Tanggal beli</td><td>Keterangan</td><td>Umur</td><td class="n">Sisa</td></tr>
                    @foreach ($ops['aging']['rows'] as $r)
                        <tr><td>{{ $r['date'] }}</td><td>#{{ $r['journal_id'] }} {{ $r['description'] }}</td><td>{{ $r['age_days'] }} hari</td><td class="n">{{ $rp($r['open']) }}</td></tr>
                    @endforeach
                </table>
            @endif
        </section>
    </div>

    <div class="iz-row" id="buku-besar">
        <section class="iz-card iz-grow-1">
            <h2 style="margin-bottom:12px">Buku besar</h2>
            <div class="lp-field" style="margin-bottom:12px">
                <label>Akun</label>
                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model.live="accountCode">
                        @foreach ($accounts as $code => $name)<option value="{{ $code }}">{{ $code }} {{ $name }}</option>@endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
                <span class="lp-help">Semua catatan keluar masuk pada satu akun, urut tanggal.</span>
            </div>
            <table class="lp-t lp-grid">
                <tr class="h"><td>Tanggal</td><td>Keterangan</td><td class="n">Debit</td><td class="n">Kredit</td><td class="n">Saldo</td></tr>
                <tr><td colspan="4">Saldo awal</td><td class="n">{{ $rp($ops['ledger']['opening']) }}</td></tr>
                @foreach ($ops['ledger']['rows'] as $r)
                    <tr><td>{{ $r['date'] }}</td><td>#{{ $r['journal_id'] }} {{ $r['description'] }}</td><td class="n">{{ $r['debit'] ? $rp($r['debit']) : '' }}</td><td class="n">{{ $r['credit'] ? $rp($r['credit']) : '' }}</td><td class="n">{{ $rp($r['balance']) }}</td></tr>
                @endforeach
                <tr class="tot"><td colspan="4">Saldo akhir</td><td class="n">{{ $rp($ops['ledger']['closing']) }}</td></tr>
            </table>
        </section>

        <section class="iz-card iz-grow-1">
            <h2 style="margin-bottom:12px">Kartu stok{{ $ops['stockBranchName'] ? ' — '.$ops['stockBranchName'] : '' }}</h2>
            <div class="lp-field" style="margin-bottom:12px">
                <label>Barang</label>
                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model.live="productId">
                        <option value="">Pilih barang…</option>
                        @foreach ($products as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
                <span class="lp-help">Riwayat barang masuk dan keluar. Kalau cabang "Semua", dipakai cabang pertama.</span>
            </div>
            @if ($ops['stockCard'])
                <table class="lp-t lp-grid">
                    <tr class="h"><td>Tanggal</td><td>Alasan</td><td class="n">Masuk / keluar</td><td class="n">Nilai</td><td class="n">Sisa</td><td class="n">Nilai sisa</td></tr>
                    @foreach ($ops['stockCard'] as $r)
                        <tr><td>{{ $r['date'] }}</td><td>{{ $r['reason'] }}</td><td class="n" style="font-weight:600;color:{{ $r['direction'] === 'in' ? '#0E7A4F' : '#B42318' }}">{{ $r['direction'] === 'in' ? '+' : '-' }}{{ $r['qty'] }}</td><td class="n">{{ $rp($r['cost']) }}</td><td class="n">{{ $r['balance_qty'] }}</td><td class="n">{{ $rp($r['balance_value']) }}</td></tr>
                    @endforeach
                </table>
            @else
                <div class="iz-empty">Pilih barang untuk melihat kartu stok.</div>
            @endif
        </section>
    </div>

    <section class="iz-card" id="neraca-saldo">
        <h2 style="margin-bottom:4px">Neraca saldo</h2>
        <div class="iz-task-sub" style="margin-bottom:8px">Daftar semua akun yang punya saldo. Total debit harus sama dengan total kredit.</div>
        <table class="lp-t lp-grid">
            <tr class="h"><td>Akun</td><td class="n">Debit</td><td class="n">Kredit</td></tr>
            @foreach ($trial['rows'] as $code => $r)
                @if ($r['debit'] || $r['credit'])
                    <tr><td>{{ $code }} {{ $r['name'] }}</td><td class="n">{{ $r['debit'] ? $rp($r['debit']) : '' }}</td><td class="n">{{ $r['credit'] ? $rp($r['credit']) : '' }}</td></tr>
                @endif
            @endforeach
            <tr class="tot"><td>Total</td><td class="n">{{ $rp($trial['total_debit']) }}</td><td class="n">{{ $rp($trial['total_credit']) }}</td></tr>
        </table>
    </section>
</x-filament-panels::page>
