@php($rp = fn ($v) => ($v < 0 ? '(' : '').number_format(abs($v), 0, ',', '.').($v < 0 ? ')' : ''))
<x-filament-panels::page>
    <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:end">
        <div style="min-width:220px">
            <label style="font-size:12px">Cabang</label>
            <x-filament::input.wrapper>
                <x-filament::input.select wire:model.live="branchId">
                    <option value="">Semua cabang (konsolidasi)</option>
                    @foreach ($branches as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </div>
        <div>
            <label style="font-size:12px">Dari</label>
            <x-filament::input.wrapper><x-filament::input type="date" wire:model.live="from" /></x-filament::input.wrapper>
        </div>
        <div>
            <label style="font-size:12px">Sampai</label>
            <x-filament::input.wrapper><x-filament::input type="date" wire:model.live="to" /></x-filament::input.wrapper>
        </div>
    </div>

    <x-filament::section heading="Laba Rugi">
        <table style="width:100%;border-collapse:collapse">
            @foreach ($income['revenue_rows'] as $code => $v)
                <tr><td>{{ $accounts[$code] }}</td><td style="text-align:right">{{ $rp($v) }}</td></tr>
            @endforeach
            <tr style="font-weight:600"><td>Total pendapatan</td><td style="text-align:right">{{ $rp($income['revenue']) }}</td></tr>
            <tr><td>Harga pokok penjualan</td><td style="text-align:right">{{ $rp(-$income['cogs']) }}</td></tr>
            <tr style="font-weight:600"><td>Laba kotor</td><td style="text-align:right">{{ $rp($income['gross_profit']) }}</td></tr>
            @foreach ($income['expense_rows'] as $code => $v)
                <tr><td>{{ $accounts[$code] }}</td><td style="text-align:right">{{ $rp(-$v) }}</td></tr>
            @endforeach
            <tr style="font-weight:700;border-top:1px solid #888"><td>Laba (rugi) bersih</td><td style="text-align:right">{{ $rp($income['net_income']) }}</td></tr>
        </table>
    </x-filament::section>

    <x-filament::section heading="Neraca">
        <table style="width:100%;border-collapse:collapse">
            @foreach ($balance['asset_rows'] as $code => $v)
                <tr><td>{{ $accounts[$code] }}</td><td style="text-align:right">{{ $rp($v) }}</td></tr>
            @endforeach
            <tr style="font-weight:600"><td>Total aset</td><td style="text-align:right">{{ $rp($balance['assets']) }}</td></tr>
            @foreach ($balance['liability_rows'] as $code => $v)
                <tr><td>{{ $accounts[$code] }}</td><td style="text-align:right">{{ $rp($v) }}</td></tr>
            @endforeach
            @foreach ($balance['equity_rows'] as $code => $v)
                <tr><td>{{ $accounts[$code] }}</td><td style="text-align:right">{{ $rp($v) }}</td></tr>
            @endforeach
            <tr><td>Laba (rugi) berjalan</td><td style="text-align:right">{{ $rp($balance['current_earnings']) }}</td></tr>
            <tr style="font-weight:600"><td>Total liabilitas dan ekuitas</td><td style="text-align:right">{{ $rp($balance['liabilities_and_equity']) }}</td></tr>
        </table>
    </x-filament::section>

    <x-filament::section heading="Neraca Saldo">
        <table style="width:100%;border-collapse:collapse">
            <tr style="font-weight:600"><td>Akun</td><td style="text-align:right">Debit</td><td style="text-align:right">Kredit</td></tr>
            @foreach ($trial['rows'] as $code => $r)
                @if ($r['debit'] || $r['credit'])
                    <tr><td>{{ $code }} {{ $r['name'] }}</td><td style="text-align:right">{{ $r['debit'] ? $rp($r['debit']) : '' }}</td><td style="text-align:right">{{ $r['credit'] ? $rp($r['credit']) : '' }}</td></tr>
                @endif
            @endforeach
            <tr style="font-weight:700;border-top:1px solid #888"><td>Total</td><td style="text-align:right">{{ $rp($trial['total_debit']) }}</td><td style="text-align:right">{{ $rp($trial['total_credit']) }}</td></tr>
        </table>
    </x-filament::section>
</x-filament-panels::page>
