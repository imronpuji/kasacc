<x-filament-panels::page>
    @php $rp = fn ($n) => ($n < 0 ? '-' : '').'Rp'.number_format(abs((int) $n), 0, ',', '.'); @endphp

    <x-filament::input.wrapper style="max-width:320px">
        <x-filament::input.select wire:model.live="branchId">
            @foreach ($branches as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
        </x-filament::input.select>
    </x-filament::input.wrapper>

    @if ($summary)
        <x-filament::section heading="Ringkasan">
            <table style="width:100%;border-collapse:collapse">
                <tr><td>Saldo Bank menurut pembukuan</td><td style="text-align:right;font-weight:700">{{ $rp($summary['book_balance']) }}</td></tr>
                <tr><td>Mutasi bank yang belum ada di pembukuan ({{ count($summary['bank_only']) }} baris)</td><td style="text-align:right">{{ $rp($summary['bank_only_total']) }}</td></tr>
                <tr><td>Catatan pembukuan yang belum muncul di bank ({{ count($summary['book_only']) }} baris)</td><td style="text-align:right">{{ $rp($summary['book_only_total']) }}</td></tr>
            </table>
            <p style="margin-top:8px;font-weight:600">
                @if ($summary['reconciled'])
                    Sudah cocok semua. Tidak ada selisih.
                @else
                    Belum cocok. Selesaikan baris yang tersisa di bawah.
                @endif
            </p>
            <p style="opacity:.7;margin-top:4px">Cara membaca: mutasi bank yang belum ada di pembukuan biasanya biaya admin atau bunga, klik "Catat ke pembukuan". Catatan pembukuan yang belum muncul di bank biasanya transfer atau cek yang belum cair, tunggu sampai muncul di rekening koran.</p>
        </x-filament::section>

        @if ($summary['book_only'])
        <x-filament::section heading="Ada di pembukuan, belum ada di rekening koran">
            <table style="width:100%;border-collapse:collapse">
                <tr style="font-weight:600"><td>Tanggal</td><td>Keterangan</td><td style="text-align:right">Jumlah</td></tr>
                @foreach ($summary['book_only'] as $b)
                    <tr><td>{{ $b['date'] }}</td><td>#{{ $b['journal_id'] }} {{ $b['description'] }}</td><td style="text-align:right">{{ $rp($b['amount']) }}</td></tr>
                @endforeach
            </table>
        </x-filament::section>
        @endif
    @endif

    <x-filament::section heading="Mutasi rekening koran">
        {{ $this->table }}
    </x-filament::section>
</x-filament-panels::page>
