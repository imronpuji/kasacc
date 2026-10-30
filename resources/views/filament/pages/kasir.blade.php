<x-filament-panels::page>
    <style>
        .ks-grid{display:flex;flex-wrap:wrap;gap:20px;align-items:flex-start}
        .ks-left{flex:1.6 1 480px;min-width:0;display:flex;flex-direction:column;gap:16px}
        .ks-cart{flex:1 1 340px;max-width:460px;background:#fff;border:1px solid #E5E7EB;border-radius:20px;padding:22px;display:flex;flex-direction:column;gap:14px}
        .ks-tiles{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:14px}
        .ks-tile{text-align:left;background:#fff;border:1px solid #E5E7EB;border-radius:16px;padding:16px;display:flex;flex-direction:column;gap:6px;min-height:112px;cursor:pointer;color:#111827}
        .ks-tile:hover{border-color:#0B63F6}
        .ks-tile b{font-size:15px;font-weight:600}.ks-tile small{font-size:13px;color:#5B6472}
        .ks-tile em{margin-top:auto;font-style:normal;font-size:18px;font-weight:700;color:#0B4FC4;font-variant-numeric:tabular-nums}
        .ks-line{display:flex;align-items:center;gap:10px;padding:10px 0;border-top:1px solid #EEF0F3}
        .ks-qb{width:44px;height:44px;border:1px solid #D1D5DB;border-radius:12px;background:#fff;font-size:20px;cursor:pointer}
        .ks-empty{padding:32px 8px;text-align:center;color:#5B6472;font-size:15px;line-height:1.5;background:#F7F8FA;border-radius:14px}
        .ks-muted{font-size:13px;color:#5B6472}
        .ks-total{display:flex;justify-content:space-between;align-items:baseline;border-top:2px solid #111827;padding-top:14px}
        .ks-total span:last-child{font-size:30px;font-weight:700;letter-spacing:-.02em;font-variant-numeric:tabular-nums}
        .ks-pay{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px}
        .ks-pay label{height:52px;display:flex;align-items:center;justify-content:center;border:2px solid #D1D5DB;border-radius:12px;background:#fff;font-weight:600;font-size:15px;cursor:pointer}
        .ks-pay label.on{border-color:#0B63F6;background:#E8F0FE;color:#0B4FC4}
        .ks-pay input{position:absolute;opacity:0;pointer-events:none}
        .ks-tile:disabled{opacity:.55;cursor:not-allowed;background:#F7F8FA}.ks-tile:disabled:hover{border-color:#E5E7EB}
        .ks-out{color:#B42318!important;font-weight:600}
        .ks-rm{border:0;background:none;color:#B42318;font-size:13px;cursor:pointer;padding:12px 6px;min-height:44px}
        @media (max-width:640px){.ks-cart{max-width:none;padding:16px}.ks-tiles{grid-template-columns:repeat(auto-fill,minmax(150px,1fr))}}
    </style>

    @php($branches = $this->branches())
    @if ($branches->isEmpty())
        <div class="ks-empty">Akun Anda belum terhubung ke cabang. Hubungi pemilik toko.</div>
    @else
    <div class="ks-grid">
        <div class="ks-left">
            @if ($branches->count() > 1)
                <label style="display:flex;flex-direction:column;gap:4px">
                    <span style="font-size:14px;font-weight:600">Cabang</span>
                    <x-filament::input.wrapper>
                        <x-filament::input.select wire:model.live="branchId">
                            @foreach ($branches as $b)<option value="{{ $b->id }}">{{ $b->name }}</option>@endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </label>
            @endif
            <label style="display:flex;flex-direction:column;gap:4px">
                <span style="font-size:14px;font-weight:600">Cari barang</span>
                <x-filament::input.wrapper>
                    <x-filament::input type="text" wire:model.live.debounce.250ms="search" autofocus placeholder="Contoh: layar iPhone 11 atau scan barcode" />
                </x-filament::input.wrapper>
            </label>
            <div class="ks-tiles">
                @foreach ($this->results as $p)
                    @php($habis = (int) $p->stock_qty <= 0)
                    <button wire:click="add({{ $p->id }})" type="button" class="ks-tile" wire:key="p{{ $p->id }}" @disabled($habis)>
                        <b>{{ $p->name }}</b>
                        @if ($habis)<small class="ks-out">Habis</small>@else<small>Sisa {{ $p->stock_qty }}</small>@endif
                        <em>Rp {{ number_format($p->sale_price, 0, ',', '.') }}</em>
                    </button>
                @endforeach
            </div>
            @if ($this->results->isEmpty())
                @if (trim($search) !== '')
                    <p class="ks-muted">Tidak ada barang yang cocok dengan "{{ $search }}". Coba kata lain atau scan ulang barcode.</p>
                @else
                    <p class="ks-muted">Belum ada barang yang sudah diberi harga jual. Isi harga jual lewat menu Produk.</p>
                @endif
            @endif
        </div>

        <aside class="ks-cart" aria-label="Keranjang">
            <h2 style="margin:0;font-size:20px;font-weight:600">Keranjang</h2>
            @if (empty($cart))
                <div class="ks-empty">Keranjang masih kosong. Ketuk barang di daftar untuk menambahkannya.</div>
            @endif
            @foreach ($cart as $id => $item)
                <div class="ks-line" wire:key="c{{ $id }}">
                    <div style="flex:1;min-width:0"><div style="font-size:15px;font-weight:600">{{ $item['name'] }}</div><div class="ks-muted">Rp {{ number_format($item['price'], 0, ',', '.') }} per buah</div></div>
                    <button type="button" class="ks-qb" wire:click="changeQty({{ $id }}, -1)" aria-label="Kurangi jumlah {{ $item['name'] }}">-</button>
                    <span style="min-width:24px;text-align:center;font-weight:600">{{ $item['qty'] }}</span>
                    <button type="button" class="ks-qb" wire:click="changeQty({{ $id }}, 1)" aria-label="Tambah jumlah {{ $item['name'] }}">+</button>
                    <button type="button" class="ks-rm" wire:click="remove({{ $id }})" aria-label="Hapus {{ $item['name'] }} dari keranjang">Hapus</button>
                </div>
            @endforeach

            <div class="ks-total"><span style="font-size:16px;font-weight:600">Total yang harus dibayar</span><span>Rp {{ number_format($this->total, 0, ',', '.') }}</span></div>

            <div style="font-size:14px;font-weight:600">Pelanggan bayar dengan</div>
            <div class="ks-pay">
                @foreach (\App\Domain\Accounting\MoneyAccounts::options(['qris' => 'QRIS']) as $k => $label)
                    <label class="{{ $method === $k ? 'on' : '' }}">
                        <input type="radio" wire:model.live="method" value="{{ $k }}" />{{ $label }}
                    </label>
                @endforeach
            </div>
            <div class="ks-muted">{{ ['kas' => 'Uang tunai masuk laci kas.', 'bank' => 'Transfer masuk rekening toko.', 'qris' => 'QRIS masuk rekening toko, dipotong biaya QRIS '.$this->qrisRate.'%.'][$method] ?? 'Uang masuk ke '.\App\Domain\Accounting\MoneyAccounts::label($method).'.' }}</div>

            <label style="display:flex;flex-direction:column;gap:4px">
                <span style="font-size:14px;font-weight:600">Email atau nomor HP pelanggan (boleh dikosongkan)</span>
                <x-filament::input.wrapper>
                    <x-filament::input type="text" wire:model.blur="customerContact" placeholder="Contoh: budi@email.com atau 081234567890" />
                </x-filament::input.wrapper>
                <span class="ks-muted">Isi supaya pelanggan bisa melihat belanjanya di portal pelanggan (masuk dengan email).</span>
            </label>

            @if ($method === 'kas' && ! empty($cart))
                <label style="display:flex;flex-direction:column;gap:4px">
                    <span style="font-size:14px;font-weight:600">Uang diterima (boleh dikosongkan)</span>
                    <x-filament::input.wrapper prefix="Rp">
                        <x-filament::input type="text" inputmode="numeric" wire:model.live.debounce.300ms="cashReceived" placeholder="Contoh: 200000" />
                    </x-filament::input.wrapper>
                    <span class="ks-muted">Isi untuk melihat kembalian. Tidak mengubah catatan keuangan.</span>
                </label>
                @if ($this->change !== null)
                    @if ($this->change >= 0)
                        <div style="display:flex;justify-content:space-between;font-size:18px;font-weight:700;color:#0E7A4F"><span>Kembalian</span><span>Rp {{ number_format($this->change, 0, ',', '.') }}</span></div>
                    @else
                        <div style="font-size:14px;font-weight:600;color:#B42318">Uang diterima kurang Rp {{ number_format(-$this->change, 0, ',', '.') }}.</div>
                    @endif
                @endif
            @endif

            <x-filament::button wire:click="checkout" wire:loading.attr="disabled" wire:target="checkout" :disabled="empty($cart)" size="xl" style="width:100%">Selesaikan pembayaran</x-filament::button>

            @if ($lastReceipt)
                <p style="text-align:center;margin:0"><a href="{{ route('struk', $lastReceipt) }}" target="_blank" class="iz-link">Cetak struk {{ $lastReceipt }}</a></p>
            @endif
        </aside>
    </div>
    @endif
</x-filament-panels::page>
