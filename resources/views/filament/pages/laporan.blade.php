@php
    $rp = fn ($v) => ($v < 0 ? '-' : '').number_format(abs($v), 0, ',', '.');
    $rpp = fn ($v) => 'Rp '.number_format($v, 0, ',', '.');
    $byDay = collect($ops['daily'])->groupBy('date')->map(fn ($g) => (int) $g->sum('total'))->sortKeys()->slice(-7);
    $maxDay = max($byDay->max() ?? 0, 1);
    $margin = $income['revenue'] > 0 ? round($income['net_income'] / $income['revenue'] * 100) : null;
    $oldest = collect($ops['aging']['rows'])->max('age_days');
    $sources = ['manual' => 'Catatan manual (modal, biaya, transfer uang)', 'purchase' => 'Pembelian dan retur', 'service' => 'Servis', 'pos' => 'Penjualan di kasir', 'transfer' => 'Pindah stok antar cabang', 'bank_recon' => 'Mutasi bank yang dicatat', 'tax' => 'Pajak usaha', 'payroll' => 'Gaji karyawan', 'payroll_payment' => 'Pembayaran gaji', 'kasbon' => 'Kasbon karyawan', 'reversal' => 'Catatan yang dibatalkan', 'payment' => 'Pembayaran utang ke pemasok'];
    $reasons = ['pembelian' => 'Beli dari pemasok', 'retur' => 'Retur ke pemasok', 'penjualan' => 'Terjual', 'garansi' => 'Klaim garansi', 'transfer keluar' => 'Dikirim ke cabang lain', 'transfer masuk' => 'Diterima dari cabang lain'];
    $buckets = ['0-30' => '0-30 hari', '31-60' => '31-60 hari', '61-90' => '61-90 hari', '>90' => 'Lebih dari 90 hari'];
    $bucketColors = ['0-30' => '#0B63F6', '31-60' => '#B45309', '61-90' => '#B45309', '>90' => '#B42318'];
    $bucketMax = max(array_sum($ops['aging']['buckets']), 1);
    $presets = ['bulan_ini' => 'Bulan ini', 'hari_ini' => 'Hari ini', 'bulan_lalu' => 'Bulan lalu', 'tanggal' => 'Pilih tanggal'];
    $questions = [
        'untung' => ['Untung atau rugi?', 'Penjualan dikurangi modal dan biaya'],
        'uang' => ['Berapa uang saya?', 'Uang, barang, dan utang saat ini'],
        'penjualan' => ['Penjualan dan servis', 'Per hari dan per teknisi'],
        'kas' => ['Uang masuk dan keluar', 'Dari mana uang datang dan pergi'],
        'utang' => ['Utang ke pemasok', 'Yang belum dibayar dan sudah berapa lama'],
        'masalah' => ['Refund dan komplain', 'Struk dibatalkan, servis batal, klaim garansi'],
        'bukti' => ['Bukti potong PPh 21', 'Pajak gaji karyawan setahun'],
    ];
    $salesTotal = collect($ops['daily'])->sum('total');
    if ($report === 'untung') { $hl = [$income['net_income'] < 0 ? 'Usaha Anda rugi' : 'Usaha Anda untung', $rpp(abs($income['net_income'])), $margin !== null ? 'Dari tiap Rp100 penjualan, Rp'.$margin.' jadi untung.' : 'Belum ada penjualan pada tanggal ini.', $income['net_income'] < 0]; }
    elseif ($report === 'uang') { $hl = ['Uang di kas dan bank', $rpp($ops['cashflow']['closing']), 'Posisi per '.$toLabel.'. Utang ke pemasok '.$rpp($ops['payables']).'.', false]; }
    elseif ($report === 'penjualan') { $hl = ['Penjualan di kasir', $rpp($salesTotal), $fromLabel.' sampai '.$toLabel, false]; }
    elseif ($report === 'kas') { $hl = ['Uang di kas dan bank sekarang', $rpp($ops['cashflow']['closing']), 'Saldo awal '.$rpp($ops['cashflow']['opening']), false]; }
    elseif ($report === 'utang') { $hl = ['Utang ke pemasok', $rpp($ops['payables']), $oldest !== null ? 'Yang terlama '.$oldest.' hari.' : 'Tidak ada utang.', $ops['payables'] > 0 && $oldest > 60]; }
    else { $hl = null; }
@endphp
<x-filament-panels::page>
    <section class="iz-card lp-filter">
        <div class="lp-chips" role="group" aria-label="Pilih periode">
            @foreach ($presets as $k => $label)
                <button type="button" wire:click="setPreset('{{ $k }}')" class="{{ $preset === $k ? 'on' : '' }}">{{ $label }}</button>
            @endforeach
        </div>
        <div class="lp-field lp-branch">
            <label>Cabang</label>
            <x-filament::input.wrapper>
                <x-filament::input.select wire:model.live="branchId">
                    <option value="">Semua cabang (digabung)</option>
                    @foreach ($branches as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </div>
        @if ($preset === 'tanggal')
            <div class="lp-dates">
                <div class="lp-field"><label>Dari tanggal</label><x-filament::input.wrapper><x-filament::input type="date" wire:model.blur="from" /></x-filament::input.wrapper></div>
                <div class="lp-field"><label>Sampai tanggal</label><x-filament::input.wrapper><x-filament::input type="date" wire:model.blur="to" /></x-filament::input.wrapper></div>
            </div>
        @endif
    </section>
    @if ($dateWarning)
        <div class="lp-note" role="alert" style="background:#FDECEA;color:#B42318;margin-bottom:12px">{{ $dateWarning }}</div>
    @endif

    <div class="lp-q" role="tablist" aria-label="Pilih laporan">
        @foreach ($questions as $k => [$t, $s])
            <button type="button" wire:click="setReport('{{ $k }}')" class="{{ $report === $k ? 'on' : '' }}" aria-pressed="{{ $report === $k ? 'true' : 'false' }}"><b>{{ $t }}</b><span>{{ $s }}</span></button>
        @endforeach
        <button type="button" wire:click="setReport('{{ $report === 'admin' ? 'untung' : 'admin' }}')" class="lp-adm {{ $report === 'admin' ? 'on' : '' }}">Untuk admin: catatan per akun, riwayat stok, cek keseimbangan</button>
    </div>

    @if ($hl)
        <div class="lp-hero {{ $hl[3] ? 'bad' : '' }}">
            <span>{{ $fromLabel }} sampai {{ $toLabel }}</span>
            <strong>{{ $hl[0] }}</strong>
            <em>{{ $hl[1] }}</em>
            <small>{{ $hl[2] }}</small>
        </div>
    @endif

    @if ($report === 'untung')
        <section class="iz-card " id="laba-rugi">
            <h2>Untung rugi</h2>
            <div class="iz-task-sub" style="margin-bottom:12px">{{ $fromLabel }} sampai {{ $toLabel }}</div>
            <table class="lp-t">
                @foreach ($income['revenue_rows'] as $code => $v)<tr><td class="in">{{ $accounts[$code] ?? 'Lainnya' }}</td><td class="n">{{ $rp($v) }}</td></tr>@endforeach
                <tr class="tot"><td>Total penjualan</td><td class="n">{{ $rp($income['revenue']) }}</td></tr>
                <tr><td>Modal barang yang terjual</td><td class="n">{{ $rp(-$income['cogs']) }}</td></tr>
                <tr class="tot"><td>Untung sebelum biaya</td><td class="n">{{ $rp($income['gross_profit']) }}</td></tr>
                <tr><td colspan="2" class="lp-sub" style="padding-top:8px">Biaya-biaya</td></tr>
                @foreach ($income['expense_rows'] as $code => $v)<tr><td class="in">{{ $accounts[$code] ?? 'Lainnya' }}</td><td class="n">{{ $rp(-$v) }}</td></tr>@endforeach
                <tr class="tot big"><td>Untung bersih (kalau minus berarti rugi)</td><td class="n" style="color:{{ $income['net_income'] < 0 ? '#B42318' : '#0E7A4F' }}">{{ $rp($income['net_income']) }}</td></tr>
            </table>
            @if ($income['revenue'] > 0)
                <div class="lp-note" style="background:{{ $income['net_income'] < 0 ? '#FDECEA' : '#E6F4EC' }};color:{{ $income['net_income'] < 0 ? '#B42318' : '#0E7A4F' }}">
                    {{ $income['net_income'] < 0 ? 'Usaha rugi pada rentang ini. Cek biaya yang paling besar.' : 'Usaha untung pada rentang ini. Dari tiap Rp100 penjualan, Rp'.$margin.' jadi untung.' }}
                </div>
            @endif
        </section>
    @elseif ($report === 'uang')
        <section class="iz-card " id="neraca">
            <h2>Harta dan utang</h2>
            <div class="iz-task-sub" style="margin-bottom:12px">Posisi per {{ $toLabel }}</div>
            @php
                $lancar = collect($balance['asset_rows'])->filter(fn ($v, $c) => $c < '1-2000');
                $tetap = collect($balance['asset_rows'])->filter(fn ($v, $c) => $c >= '1-2000');
            @endphp
            <div class="lp-sub">Harta yang cepat jadi uang (kas, bank, barang dagangan)</div>
            <table class="lp-t" style="margin-bottom:14px">
                @foreach ($lancar as $code => $v)<tr><td>{{ $accounts[$code] ?? 'Lainnya' }}</td><td class="n">{{ $rp($v) }}</td></tr>@endforeach
                <tr class="tot"><td>Jumlah harta cepat jadi uang</td><td class="n">{{ $rp($lancar->sum()) }}</td></tr>
            </table>
            @if ($tetap->filter()->isNotEmpty())
                <div class="lp-sub">Harta tahan lama (tanah, kendaraan, peralatan)</div>
                <table class="lp-t" style="margin-bottom:14px">
                    @foreach ($tetap->filter() as $code => $v)<tr><td>{{ $accounts[$code] ?? 'Lainnya' }}</td><td class="n">{{ $rp($v) }}</td></tr>@endforeach
                    <tr class="tot"><td>Jumlah harta tahan lama</td><td class="n">{{ $rp($tetap->sum()) }}</td></tr>
                </table>
            @endif
            <table class="lp-t" style="margin-bottom:14px"><tr class="tot big"><td>Total harta</td><td class="n">{{ $rp($balance['assets']) }}</td></tr></table>
            <div class="lp-sub">Utang dan modal</div>
            <table class="lp-t">
                @foreach ($balance['liability_rows'] as $code => $v)<tr><td>{{ $accounts[$code] ?? 'Lainnya' }}</td><td class="n">{{ $rp($v) }}</td></tr>@endforeach
                @foreach ($balance['equity_rows'] as $code => $v)<tr><td>{{ $accounts[$code] ?? 'Lainnya' }}</td><td class="n">{{ $rp($v) }}</td></tr>@endforeach
                <tr><td>Untung (rugi) yang belum dibukukan sebagai modal</td><td class="n">{{ $rp($balance['current_earnings']) }}</td></tr>
                <tr class="tot"><td>Total utang dan modal</td><td class="n">{{ $rp($balance['liabilities_and_equity']) }}</td></tr>
            </table>
            @if ($balance['assets'] === $balance['liabilities_and_equity'])
                <div class="lp-note" style="background:#E8F0FE;color:#0B3A91">Seimbang: harta sama dengan utang ditambah modal.</div>
            @else
                <div class="lp-note" style="background:#FDECEA;color:#B42318">Angka tidak seimbang. Jangan kunci bulan ini dulu, hubungi admin atau teknisi aplikasi.</div>
            @endif
        </section>
    @elseif ($report === 'penjualan')
        <section class="iz-card ">
            <div class="iz-card-head"><h2>Penjualan di kasir per hari</h2><span>{{ $byDay->count() }} hari terakhir pada rentang ini</span></div>
            @if ($byDay->isNotEmpty())
                <div class="lp-bars">
                    @foreach ($byDay as $d => $v)
                        <div><span>{{ number_format($v / 1_000_000, 1, ',', '.') }} jt</span><i style="height:{{ max(6, round($v / $maxDay * 130)) }}px;background:{{ $v === $maxDay ? '#0B63F6' : '#C7DAFB' }}"></i><small>{{ \Carbon\Carbon::parse($d)->format('d/m') }}</small></div>
                    @endforeach
                </div>
            @endif
            <table class="lp-t lp-grid" style="margin-top:12px">
                <tr class="h"><td>Tanggal</td><td>Cara bayar</td><td class="n">Jumlah struk</td><td class="n">Total penjualan</td><td class="n">Biaya QRIS</td></tr>
                @forelse ($ops['daily'] as $r)
                    <tr><td>{{ $r['date'] }}</td><td>{{ ['kas'=>'Tunai','bank'=>'Transfer'][$r['method']] ?? \App\Domain\Accounting\MoneyAccounts::label($r['method']) }}</td><td class="n">{{ $r['receipts'] }}</td><td class="n">{{ $rp($r['total']) }}</td><td class="n">{{ $rp($r['mdr']) }}</td></tr>
                @empty
                    <tr><td colspan="5" class="iz-empty">Belum ada penjualan di kasir pada tanggal ini. Coba ganti tanggal di atas.</td></tr>
                @endforelse
            </table>
        </section>
        <section class="iz-card ">
            <h2 style="margin-bottom:8px">Servis selesai per teknisi</h2>
            <table class="lp-t lp-grid">
                <tr class="h"><td>Teknisi</td><td class="n">Jumlah servis</td><td class="n">Ongkos jasa</td><td class="n">Suku cadang</td><td class="n">Total</td></tr>
                @forelse ($ops['technicians'] as $r)
                    <tr><td style="font-weight:600">{{ $r['technician'] }}</td><td class="n">{{ $r['tickets'] }}</td><td class="n">{{ $rp($r['labor']) }}</td><td class="n">{{ $rp($r['parts']) }}</td><td class="n">{{ $rp($r['total']) }}</td></tr>
                @empty
                    <tr><td colspan="5" class="iz-empty">Belum ada servis selesai pada tanggal ini. Coba ganti tanggal di atas.</td></tr>
                @endforelse
            </table>
        </section>
    @elseif ($report === 'kas')
        <section class="iz-card " id="arus-kas">
            <h2 style="margin-bottom:4px">Arus kas (uang masuk dan keluar)</h2>
            <div class="iz-task-sub" style="margin-bottom:12px">Uang di kas dan bank. Saldo awal {{ $rpp($ops['cashflow']['opening']) }}</div>
            <table class="lp-t">
                <tr class="h"><td>Dari mana</td><td class="n">Masuk</td><td class="n">Keluar</td></tr>
                @foreach ($ops['cashflow']['rows'] as $r)
                    <tr><td>{{ $sources[$r['source']] ?? 'Lainnya' }}</td><td class="n">{{ $rp($r['inflow']) }}</td><td class="n">{{ $rp($r['outflow']) }}</td></tr>
                @endforeach
                <tr class="tot"><td colspan="2">Saldo akhir</td><td class="n">{{ $rp($ops['cashflow']['closing']) }}</td></tr>
            </table>
            <p class="iz-task-sub" style="margin:10px 0 0">Utang ke pemasok per {{ $toLabel }}: Rp {{ number_format($ops['payables'], 0, ',', '.') }}</p>
        </section>
    @elseif ($report === 'utang')
        <section class="iz-card " id="utang">
            <h2 style="margin-bottom:4px">Utang ke pemasok menurut lamanya</h2>
            <div class="iz-task-sub" style="margin-bottom:16px">Per {{ $toLabel }}. Pembayaran dianggap melunasi pembelian yang paling lama lebih dulu.</div>
            @foreach ($ops['aging']['buckets'] as $i => $v)
                <div style="margin-bottom:14px">
                    <div style="display:flex;justify-content:space-between;font-size:14px"><span>{{ $buckets[$i] ?? $i }}</span><span style="font-weight:600;font-variant-numeric:tabular-nums">{{ $rpp($v) }}</span></div>
                    <div class="iz-bar"><div style="background:{{ $bucketColors[$i] ?? '#0B63F6' }};width:{{ round($v / $bucketMax * 100) }}%"></div></div>
                </div>
            @endforeach
            @if ($ops['aging']['rows'])
                <table class="lp-t lp-grid" style="margin-top:8px">
                    <tr class="h"><td>Tanggal beli</td><td>Keterangan</td><td>Sudah berapa lama</td><td class="n">Belum dibayar</td></tr>
                    @foreach ($ops['aging']['rows'] as $r)
                        <tr><td>{{ $r['date'] }}</td><td>{{ $r['description'] }}</td><td>{{ $r['age_days'] }} hari</td><td class="n">{{ $rp($r['open']) }}</td></tr>
                    @endforeach
                </table>
            @endif
        </section>
    @elseif ($report === 'masalah')
        @php $pb = $ops['problems']; @endphp
        <section class="iz-card ">
            <h2 style="margin-bottom:4px">Refund dan komplain</h2>
            <div class="iz-task-sub" style="margin-bottom:12px">{{ $fromLabel }} sampai {{ $toLabel }}</div>
            <table class="lp-t">
                <tr class="h"><td>Jenis</td><td class="n">Jumlah</td><td class="n">Nilai</td></tr>
                <tr><td>Struk kasir dibatalkan (uang dikembalikan)</td><td class="n">{{ $pb['void_count'] }}</td><td class="n">{{ $rp($pb['void_total']) }}</td></tr>
                <tr><td>Servis batal (DP dikembalikan)</td><td class="n">{{ count($pb['cancels']) }}</td><td class="n">{{ $rp($pb['cancel_total']) }}</td></tr>
                <tr><td>Servis selesai yang kena garansi (komplain)</td><td class="n">{{ count($pb['warranty']) }}</td><td class="n">{{ $rp($pb['warranty_total']) }}</td></tr>
            </table>
            <p class="iz-task-sub" style="margin:10px 0 0">Dari {{ $pb['receipts'] + $pb['void_count'] > 0 ? $pb['receipts'] + $pb['void_count'] : 0 }} struk, {{ $pb['void_count'] }} dibatalkan. Dari {{ $pb['services_done'] }} servis selesai, {{ count($pb['warranty']) }} kena garansi.</p>
        </section>
        <section class="iz-card ">
            <h2 style="margin-bottom:8px">Struk yang dibatalkan</h2>
            <table class="lp-t lp-grid">
                <tr class="h"><td>Nomor struk</td><td>Dibatalkan</td><td>Alasan</td><td class="n">Nilai</td></tr>
                @forelse ($pb['voids'] as $r)
                    <tr><td>{{ $r['receipt_no'] }}</td><td>{{ \Carbon\Carbon::parse($r['voided_on'])->format('d/m/Y') }}</td><td>{{ $r['reason'] ?: '-' }}</td><td class="n">{{ $rp($r['total']) }}</td></tr>
                @empty
                    <tr><td colspan="4" class="iz-empty">Tidak ada struk dibatalkan pada tanggal ini.</td></tr>
                @endforelse
            </table>
        </section>
        <section class="iz-card ">
            <h2 style="margin-bottom:8px">Servis batal dan klaim garansi</h2>
            <table class="lp-t lp-grid">
                <tr class="h"><td>Kode</td><td>Pelanggan</td><td>Perangkat</td><td>Keterangan</td><td class="n">Nilai</td></tr>
                @foreach ($pb['cancels'] as $r)
                    <tr><td>{{ $r['code'] }}</td><td>{{ $r['customer'] }}</td><td>{{ $r['device'] }}</td><td>Batal, DP dikembalikan</td><td class="n">{{ $rp($r['refund']) }}</td></tr>
                @endforeach
                @foreach ($pb['warranty'] as $r)
                    <tr><td>{{ $r['code'] }}</td><td>{{ $r['customer'] }}</td><td>{{ $r['device'] }}</td><td>Klaim garansi</td><td class="n">{{ $rp($r['cost']) }}</td></tr>
                @endforeach
                @if (! $pb['cancels'] && ! $pb['warranty'])
                    <tr><td colspan="5" class="iz-empty">Tidak ada servis batal atau klaim garansi pada tanggal ini.</td></tr>
                @endif
            </table>
        </section>
    @elseif ($report === 'bukti')
        <section class="iz-card " id="bukti-potong">
            <h2 style="margin-bottom:4px">Bukti potong PPh 21 tahun {{ $buktiYear }}</h2>
            <div class="iz-task-sub" style="margin-bottom:16px">Dijumlah dari gaji yang sudah disetujui atau dibayar. Tahun diambil dari tanggal akhir periode. Bahan untuk mengisi bukti potong 1721-A1.</div>
            @if ($bukti)
                <table class="lp-t lp-grid">
                    <tr class="h"><td>Nama</td><td>NPWP</td><td>NIK</td><td>Status</td><td class="n">Bulan</td><td class="n">Penghasilan bruto</td><td class="n">Iuran JHT dan JP</td><td class="n">PPh 21 dipotong</td></tr>
                    @foreach ($bukti as $b)
                        <tr><td>{{ $b['name'] }}</td><td>{{ $b['npwp'] ?: 'Belum diisi' }}</td><td>{{ $b['nik'] ?: 'Belum diisi' }}</td><td>{{ $b['ptkp'] }}</td><td class="n">{{ $b['months'] }}</td><td class="n">{{ $rp($b['gross']) }}</td><td class="n">{{ $rp($b['emp_jht_jp']) }}</td><td class="n">{{ $rp($b['pph21']) }}</td></tr>
                    @endforeach
                    <tr class="tot"><td colspan="5">Total</td><td class="n">{{ $rp(collect($bukti)->sum('gross')) }}</td><td class="n">{{ $rp(collect($bukti)->sum('emp_jht_jp')) }}</td><td class="n">{{ $rp(collect($bukti)->sum('pph21')) }}</td></tr>
                </table>
            @else
                <div class="lp-note">Belum ada gaji yang disetujui pada tahun {{ $buktiYear }}.</div>
            @endif
        </section>
    @else
        <section class="iz-card ">
            <h2 style="margin-bottom:12px">Catatan per akun</h2>
            <div class="lp-field" style="margin-bottom:12px">
                <label>Pilih akun</label>
                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model.live="accountCode">
                        @foreach ($accounts as $code => $name)<option value="{{ $code }}">{{ $name }}</option>@endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
                <span class="lp-help">Akun adalah pos uang, contoh: Kas, Bank, Penjualan. Di sini tampil semua uang masuk dan keluar pada satu akun, urut tanggal.</span>
            </div>
            <table class="lp-t lp-grid">
                <tr class="h"><td>Tanggal</td><td>Keterangan</td><td class="n">Uang masuk ke akun</td><td class="n">Uang keluar dari akun</td><td class="n">Saldo</td></tr>
                <tr><td colspan="4">Saldo awal</td><td class="n">{{ $rp($ops['ledger']['opening']) }}</td></tr>
                @foreach ($ops['ledger']['rows'] as $r)
                    <tr><td>{{ $r['date'] }}</td><td>{{ $r['description'] }}</td><td class="n">{{ $r['debit'] ? $rp($r['debit']) : '' }}</td><td class="n">{{ $r['credit'] ? $rp($r['credit']) : '' }}</td><td class="n">{{ $rp($r['balance']) }}</td></tr>
                @endforeach
                <tr class="tot"><td colspan="4">Saldo akhir</td><td class="n">{{ $rp($ops['ledger']['closing']) }}</td></tr>
            </table>
        </section>
        <section class="iz-card ">
            <h2 style="margin-bottom:12px">Riwayat stok{{ $ops['stockBranchName'] ? ' — '.$ops['stockBranchName'] : '' }}</h2>
                        <div style="margin-bottom:12px">{{ $this->form }}</div>
            @if ($ops['stockCard'])
                <table class="lp-t lp-grid">
                    <tr class="h"><td>Tanggal</td><td>Alasan</td><td class="n">Masuk / keluar</td><td class="n">Nilai barang</td><td class="n">Sisa stok</td><td class="n">Nilai sisa stok</td></tr>
                    @foreach ($ops['stockCard'] as $r)
                        <tr><td>{{ $r['date'] }}</td><td>{{ $reasons[$r['reason']] ?? ucfirst($r['reason']) }}</td><td class="n" style="font-weight:600;color:{{ $r['direction'] === 'in' ? '#0E7A4F' : '#B42318' }}">{{ $r['direction'] === 'in' ? '+' : '-' }}{{ $r['qty'] }}</td><td class="n">{{ $rp($r['cost']) }}</td><td class="n">{{ $r['balance_qty'] }}</td><td class="n">{{ $rp($r['balance_value']) }}</td></tr>
                    @endforeach
                </table>
            @else
                <div class="iz-empty">Pilih barang di atas untuk melihat riwayat stoknya. Kalau barang sudah dipilih tapi kosong, berarti belum ada pergerakan stok.</div>
            @endif
        </section>
    <section class="iz-card" id="neraca-saldo">
        <h2 style="margin-bottom:4px">Cek keseimbangan catatan</h2>
        <div class="iz-task-sub" style="margin-bottom:8px">Daftar semua akun yang ada isinya. Kedua total harus sama. Kalau beda, ada catatan yang salah.</div>
        <table class="lp-t lp-grid">
            <tr class="h"><td>Akun</td><td class="n">Uang masuk ke akun</td><td class="n">Uang keluar dari akun</td></tr>
            @foreach ($trial['rows'] as $code => $r)
                @if ($r['debit'] || $r['credit'])
                    <tr><td>{{ $r['name'] }}</td><td class="n">{{ $r['debit'] ? $rp($r['debit']) : '' }}</td><td class="n">{{ $r['credit'] ? $rp($r['credit']) : '' }}</td></tr>
                @endif
            @endforeach
            <tr class="tot"><td>Total</td><td class="n">{{ $rp($trial['total_debit']) }}</td><td class="n">{{ $rp($trial['total_credit']) }}</td></tr>
        </table>
    </section>
    @endif
</x-filament-panels::page>
