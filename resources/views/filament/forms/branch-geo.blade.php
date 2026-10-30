<div x-data="{ msg: '',
        lat() { return parseFloat($wire.data.lat); }, lng() { return parseFloat($wire.data.lng); },
        ready() { return Number.isFinite(this.lat()) && Number.isFinite(this.lng()); },
        mapSrc() { return 'https://www.google.com/maps?q=' + this.lat() + ',' + this.lng() + '&z=18&output=embed'; },
        mapLink() { return this.ready() ? 'https://www.google.com/maps/search/?api=1&query=' + this.lat() + ',' + this.lng() : 'https://www.google.com/maps'; },
        go() {
            this.msg = 'Membaca lokasi...';
            if (!navigator.geolocation) { this.msg = 'Lokasi tidak didukung di browser ini. Pakai Google Maps di bawah.'; return; }
            navigator.geolocation.getCurrentPosition(
                p => { $wire.$set('data.lat', p.coords.latitude.toFixed(6)); $wire.$set('data.lng', p.coords.longitude.toFixed(6)); this.msg = 'Terisi dari lokasi Anda sekarang (akurasi sekitar ' + Math.round(p.coords.accuracy) + ' m). Pastikan Anda sedang berada di toko, periksa peta, lalu tekan Simpan.'; },
                () => { this.msg = 'Lokasi tidak bisa dibaca. Izinkan lokasi di browser (Chrome atau Safari), atau pakai Google Maps di bawah.'; },
                { enableHighAccuracy: true, timeout: 15000 });
        } }">
    <div class="iz-info">
        <b>Cara dengan Google Maps (paling tepat):</b> buka Google Maps, cari toko Anda, tekan lama (HP) atau klik kanan (laptop) tepat di titik toko, lalu salin angka koordinat yang muncul di atas. Tempel di kolom isian lokasi di bawah.<br>
        <b>Cara cepat:</b> berdiri di dalam toko lalu tekan "Pakai lokasi saya sekarang".
    </div>
    <div style="display:flex;flex-wrap:wrap;gap:12px;margin-top:12px">
        <button type="button" class="iz-btn" x-on:click="go()">Pakai lokasi saya sekarang</button>
        <a class="iz-btn" x-bind:href="mapLink()" target="_blank" rel="noopener" x-text="ready() ? 'Lihat di Google Maps' : 'Buka Google Maps'">Buka Google Maps</a>
    </div>
    <p x-show="msg" x-text="msg" class="iz-task-sub" style="margin-top:8px"></p>
    <div x-show="ready()" style="margin-top:12px">
        <div class="iz-task-sub" style="margin-bottom:6px">Pratinjau titik toko. Pastikan penanda tepat di lokasi toko.</div>
        <iframe x-bind:src="ready() ? mapSrc() : 'about:blank'" title="Peta lokasi toko" loading="lazy" referrerpolicy="no-referrer-when-downgrade" style="width:100%;height:280px;border:1px solid #E5E7EB;border-radius:14px"></iframe>
    </div>
</div>
