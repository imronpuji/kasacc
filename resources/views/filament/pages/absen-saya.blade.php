<x-filament-panels::page>
    <div class="iz-row">
        <section class="iz-card iz-grow-1" style="text-align:center">
            <div class="iz-task-sub">{{ $employee->name }} · {{ $employee->branch->name }}</div>
            <div style="font-size:15px;color:#5B6472;margin-top:4px">{{ $now->locale('id')->translatedFormat('l, d F Y') }}</div>

            @if (! $branchReady)
                <div class="lp-note" style="background:#FEF3E2;color:#B45309;margin-top:16px">Lokasi cabang belum diatur, jadi absen belum bisa dipakai. Minta pemilik mengisi koordinat di menu Data master, Cabang.</div>
            @endif

            <div style="margin:22px 0 6px;font-size:15px;font-weight:600">
                @if (! $today) Anda belum absen masuk hari ini.
                @elseif (! $today->check_out_at) Masuk pukul {{ $today->check_in_at->setTimezone($tz)->format('H:i') }}@if ($today->late_minutes), terlambat {{ $today->late_minutes }} menit @endif. Belum absen pulang.
                @else Masuk {{ $today->check_in_at->setTimezone($tz)->format('H:i') }}, pulang {{ $today->check_out_at->setTimezone($tz)->format('H:i') }}. Terima kasih, sampai besok.
                @endif
            </div>

            <div x-data="{ busy: false, go(action) {
                    if (!navigator.geolocation) { $wire.geoError('Peramban ini tidak mendukung lokasi. Pakai HP dengan Chrome atau Safari.'); return; }
                    this.busy = true;
                    navigator.geolocation.getCurrentPosition(
                        p => { $wire[action](p.coords.latitude, p.coords.longitude, p.coords.accuracy).finally(() => this.busy = false); },
                        e => { this.busy = false; $wire.geoError(e.code === 1 ? 'Izin lokasi ditolak. Buka pengaturan peramban dan izinkan lokasi untuk situs ini.' : 'Lokasi belum terbaca. Nyalakan GPS lalu coba lagi.'); },
                        { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 });
                } }" style="display:flex;flex-wrap:wrap;gap:12px;justify-content:center;margin-top:12px">
                <x-filament::button size="xl" x-on:click="go('clockIn')" x-bind:disabled="busy" :disabled="$today !== null || ! $branchReady">Absen masuk</x-filament::button>
                <x-filament::button size="xl" color="gray" x-on:click="go('clockOut')" x-bind:disabled="busy" :disabled="$today === null || $today->check_out_at !== null">Absen pulang</x-filament::button>
            </div>
            <p class="iz-task-sub" style="margin-top:14px">Lokasi hanya dipakai saat tombol ditekan, untuk memastikan Anda berada di toko.</p>
        </section>

        <section class="iz-card iz-grow-15">
            <h2 style="margin-bottom:8px">10 hari terakhir</h2>
            <table class="lp-t lp-grid">
                <tr class="h"><td>Tanggal</td><td>Masuk</td><td>Pulang</td><td>Keterangan</td></tr>
                @forelse ($logs as $l)
                    <tr>
                        <td>{{ $l->work_date->format('d/m/Y') }}</td>
                        <td>{{ $l->check_in_at->setTimezone($tz)->format('H:i') }}</td>
                        <td>{{ $l->check_out_at ? $l->check_out_at->setTimezone($tz)->format('H:i') : '-' }}</td>
                        <td>@if ($l->late_minutes)<span class="iz-pill" style="background:#FEF3E2;color:#B45309">Terlambat {{ $l->late_minutes }} menit</span>@else<span class="iz-pill" style="background:#E6F4EC;color:#0E7A4F">Tepat waktu</span>@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="iz-empty">Belum ada riwayat absen.</td></tr>
                @endforelse
            </table>
        </section>
    </div>
</x-filament-panels::page>
