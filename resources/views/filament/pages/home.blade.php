<x-filament-panels::page>
    @if (! empty($quick))
        <div style="display:flex;flex-wrap:wrap;gap:12px">
            @foreach ($quick as $q)
                <x-filament::button tag="a" :href="$q['url']" size="xl">{{ $q['label'] }}</x-filament::button>
            @endforeach
        </div>
    @endif

    @if (! empty($setup))
        <section class="iz-card" style="border-color:#B9D2FF">
            <div class="iz-card-head"><h2>Mulai dari sini</h2><span>{{ collect($setup)->where('done', true)->count() }} dari {{ count($setup) }} selesai</span></div>
            <div class="iz-task-sub" style="margin-bottom:8px">Lengkapi langkah ini supaya toko Anda siap dipakai. Kerjakan berurutan dari atas.</div>
            @foreach ($setup as $i => $x)
                <div class="iz-task">
                    <span class="iz-pill" style="background:{{ $x['done'] ? '#E6F4EC' : '#E8F0FE' }};color:{{ $x['done'] ? '#0E7A4F' : '#0B4FC4' }}">{{ $x['done'] ? 'Sudah' : 'Langkah '.($i + 1) }}</span>
                    <div class="iz-task-body"><div class="iz-task-title">{{ $x['title'] }}</div><div class="iz-task-sub">{{ $x['sub'] }}</div></div>
                    @unless ($x['done'])<a href="{{ $x['url'] }}" class="iz-link">Isi sekarang</a>@endunless
                </div>
            @endforeach
        </section>
    @endif

    <div class="iz-kpis">
        @foreach ($kpis as $k)
            <div class="iz-card iz-kpi">
                <div class="iz-kpi-label">{{ $k['label'] }}</div>
                <div class="iz-kpi-value">{{ $k['value'] }}</div>
                <div class="iz-kpi-note" style="color:{{ $k['tone'] }}">{{ $k['note'] }}</div>
            </div>
        @endforeach
    </div>

    <div class="iz-row">
        <section class="iz-card iz-grow-15">
            <div class="iz-card-head"><h2>Yang perlu dikerjakan</h2><span>{{ count($tasks) }} tugas</span></div>
            @forelse ($tasks as $t)
                <div class="iz-task">
                    <span class="iz-pill" style="background:{{ $t['bg'] }};color:{{ $t['fg'] }}">{{ $t['tag'] }}</span>
                    <div class="iz-task-body"><div class="iz-task-title">{{ $t['title'] }}</div><div class="iz-task-sub">{{ $t['sub'] }}</div></div>
                    <a href="{{ $t['url'] }}" class="iz-link">{{ $t['cta'] }}</a>
                </div>
            @empty
                <div class="iz-empty">Tidak ada tugas yang menunggu. Semua beres.</div>
            @endforelse
        </section>

        @if ($aging)
        <section class="iz-card iz-grow-1">
            <h2 style="margin:0 0 4px">Umur utang ke pemasok</h2>
            <div class="iz-task-sub" style="margin-bottom:16px">Barang yang dibeli tapi belum dibayar</div>
            @if (collect($aging)->every(fn ($a) => $a['value'] === 'Rp 0'))
                <div class="iz-empty">Tidak ada utang ke pemasok. Semua pembelian sudah lunas.</div>
            @endif
            @foreach ($aging as $a)
                <div class="iz-mb-14">
                    <div class="iz-split"><span>{{ $a['label'] }}</span><span class="iz-num">{{ $a['value'] }}</span></div>
                    <div class="iz-bar"><div style="background:{{ $a['color'] }};width:{{ $a['pct'] }}"></div></div>
                </div>
            @endforeach
        </section>
        @endif
    </div>
</x-filament-panels::page>
