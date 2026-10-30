<x-filament-panels::page>
    @php $rp = fn ($n) => ($n < 0 ? '-' : '').'Rp'.number_format(abs((int) $n), 0, ',', '.'); @endphp

    <div style="max-width:320px">
        <label for="rekon-cabang" style="display:block;font-weight:600;font-size:14px;margin-bottom:4px">Cabang</label>
        <x-filament::input.wrapper>
            <x-filament::input.select id="rekon-cabang" wire:model.live="branchId">
                @foreach ($branches as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
            </x-filament::input.select>
        </x-filament::input.wrapper>
        <p style="opacity:.7;font-size:13px;margin-top:4px">Pilih cabang yang rekening banknya mau dicocokkan.</p>
    </div>

    <div style="max-width:320px">
        <label for="rekon-rekening" style="display:block;font-weight:600;font-size:14px;margin-bottom:4px">Rekening bank mana?</label>
        <x-filament::input.wrapper>
            <x-filament::input.select id="rekon-rekening" wire:model.live="accountCode">
                @foreach ($bankAccounts as $code => $name)<option value="{{ $code }}">{{ $name }}</option>@endforeach
            </x-filament::input.select>
        </x-filament::input.wrapper>
        <p style="opacity:.7;font-size:13px;margin-top:4px">Pilih rekening yang mau dicocokkan dengan rekening koran. Kas tidak termasuk.</p>
    </div>

    @if ($summary)
        <x-filament::section heading="Ringkasan">
            <table style="width:100%;border-collapse:collapse">
                <tr><td>Saldo rekening ini menurut catatan di aplikasi</td><td style="text-align:right;font-weight:700">{{ $rp($summary['book_balance']) }}</td></tr>
                <tr><td>Mutasi bank yang belum dicatat di aplikasi ({{ count($summary['bank_only']) }} baris)</td><td class="iz-right">{{ $rp($summary['bank_only_total']) }}</td></tr>
                <tr><td>Catatan di aplikasi yang belum muncul di rekening bank ({{ count($summary['book_only']) }} baris)</td><td class="iz-right">{{ $rp($summary['book_only_total']) }}</td></tr>
            </table>
            <p style="margin-top:8px;font-weight:600">
                @if ($summary['reconciled'])
                    Sudah cocok semua. Tidak ada selisih.
                @else
                    Belum cocok. Selesaikan baris yang tersisa di bawah.
                @endif
            </p>
            <p style="opacity:.7;margin-top:4px">Cara membaca: mutasi bank yang belum dicatat di aplikasi biasanya biaya admin atau bunga, tekan "Catat mutasi ini". Catatan di aplikasi yang belum muncul di rekening bank biasanya transfer atau cek yang belum cair, tunggu sampai muncul di rekening bank.</p>
        </x-filament::section>

        @if ($summary['book_only'])
        <x-filament::section heading="Sudah dicatat di aplikasi, belum muncul di rekening bank">
            <table style="width:100%;border-collapse:collapse">
                <tr style="font-weight:600"><td>Tanggal</td><td>Keterangan</td><td class="iz-right">Jumlah</td></tr>
                @foreach ($summary['book_only'] as $b)
                    <tr><td>{{ $b['date'] }}</td><td>{{ $b['description'] }}</td><td class="iz-right">{{ $rp($b['amount']) }}</td></tr>
                @endforeach
            </table>
        </x-filament::section>
        @endif
    @endif

    <x-filament::section heading="Mutasi rekening bank">
        {{ $this->table }}
    </x-filament::section>
</x-filament-panels::page>
