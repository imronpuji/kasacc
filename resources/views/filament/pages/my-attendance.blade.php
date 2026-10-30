<x-filament-panels::page>
    @if (! $employee)
        <section class="iz-card"><p>Akun Anda belum terhubung ke data karyawan, jadi absen belum bisa dipakai. Hubungi HR untuk menghubungkannya.</p></section>
    @else
    <div class="iz-row">
        <section class="iz-card iz-grow-1" style="text-align:center">
            <div class="iz-task-sub">{{ $employee->name }} · {{ $employee->branch?->name ?? '-' }}</div>
            <div style="font-size:15px;color:#5B6472;margin-top:4px">{{ $now->locale('id')->translatedFormat('l, d F Y') }}</div>

            @if (! $branchReady)
                <div class="lp-note" style="background:#FEF3E2;color:#B45309;margin-top:16px">Lokasi toko belum diisi. Hubungi pemilik atau HR untuk mengisinya.</div>
            @endif

            <div style="margin:22px 0 6px;font-size:15px;font-weight:600">
                @if (! $today) Anda belum absen masuk hari ini.
                @elseif (! $today->check_out_at) Masuk pukul {{ $today->check_in_at->setTimezone($tz)->format('H:i') }}@if ($today->late_minutes), terlambat {{ $today->late_minutes }} menit @endif. Belum absen pulang.
                @else Masuk {{ $today->check_in_at->setTimezone($tz)->format('H:i') }}, pulang {{ $today->check_out_at->setTimezone($tz)->format('H:i') }}. Terima kasih, sampai besok.
                @endif
            </div>

            <div x-data="{ busy: false, go(action) {
                    if (!navigator.geolocation) { $wire.geoError('Lokasi tidak didukung di sini. Buka lewat browser (Chrome atau Safari) di HP.'); return; }
                    this.busy = true;
                    navigator.geolocation.getCurrentPosition(
                        p => { $wire[action](p.coords.latitude, p.coords.longitude, p.coords.accuracy).finally(() => this.busy = false); },
                        e => { this.busy = false; $wire.geoError(e.code === 1 ? 'Izin lokasi ditolak. Buka pengaturan browser (Chrome atau Safari), izinkan lokasi untuk situs ini, lalu coba lagi.' : 'Lokasi belum terbaca. Nyalakan GPS, tunggu sebentar, lalu tekan Absen lagi.'); },
                        { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 });
                } }" style="display:flex;flex-wrap:wrap;gap:12px;justify-content:center;margin-top:12px">
                <x-filament::button size="xl" x-on:click="go('clockIn')" x-bind:disabled="busy || {{ ($today !== null || ! $branchReady) ? 'true' : 'false' }}"><span x-show="!busy">Absen masuk</span><span x-show="busy" x-cloak>Sedang membaca lokasi...</span></x-filament::button>
                <x-filament::button size="xl" color="gray" x-on:click="go('clockOut')" x-bind:disabled="busy || {{ ($today === null || $today->check_out_at !== null) ? 'true' : 'false' }}"><span x-show="!busy">Absen pulang</span><span x-show="busy" x-cloak>Sedang membaca lokasi...</span></x-filament::button>
            </div>
            <p class="iz-task-sub" style="margin-top:14px">Lokasi hanya dipakai saat tombol ditekan, untuk memastikan Anda berada di toko. Tunggu sebentar setelah menekan tombol, jangan ditekan dua kali.</p>
        </section>

        <section class="iz-card iz-grow-15">
            <h2 class="iz-mb-8">Riwayat absen 10 hari terakhir</h2>
            <div style="overflow-x:auto;-webkit-overflow-scrolling:touch">
            <table class="lp-t lp-grid" style="min-width:320px;font-size:14px">
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
            </div>
        </section>
    </div>
    @endif
</x-filament-panels::page>
