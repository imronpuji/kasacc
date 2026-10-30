<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Nota servis {{ $t->code }}</title>
<style>body{font-family:monospace;width:300px;max-width:100%;margin:auto}table{width:100%}td.r{text-align:right}hr{border:0;border-top:1px dashed #000}</style></head>
<body onload="window.print()">
@php
    $attrs = $t->branch->getAttributes();
    $rp = fn ($n) => 'Rp '.number_format((int) $n, 0, ',', '.');
    $status = ['diterima' => 'Sedang dikerjakan', 'selesai' => 'Selesai', 'batal' => 'Dibatalkan'][$t->status] ?? $t->status;
@endphp
<h3 style="text-align:center;margin-bottom:4px">{{ $t->branch->name }}</h3>
@if (! empty($attrs['address']) || ! empty($attrs['phone']))
<div style="text-align:center;font-size:12px">{{ $attrs['address'] ?? '' }}@if (! empty($attrs['address']) && ! empty($attrs['phone']))<br>@endif @if (! empty($attrs['phone']))Telp {{ $attrs['phone'] }}@endif</div>
@endif
<hr>
<div style="text-align:center;font-weight:bold">NOTA SERVIS</div>
<div>No. servis: {{ $t->code }}<br>Diterima: {{ $t->received_on->format('d/m/Y') }}<br>Status: {{ $status }}@if ($t->closed_on)<br>Ditutup: {{ $t->closed_on->format('d/m/Y') }}@endif</div><hr>
<div>Pelanggan: {{ $t->customer_name }}@if ($t->customer_phone)<br>HP/WA: {{ $t->customer_phone }}@endif<br>Perangkat: {{ $t->device }}@if ($t->complaint)<br>Keluhan: {{ $t->complaint }}@endif @if ($t->technician)<br>Teknisi: {{ $t->technician->name }}@endif</div><hr>
<table>
@if ($t->status === 'selesai')
<tr><td>Ongkos jasa</td><td class="r">{{ $rp($t->labor_fee) }}</td></tr>
<tr><td>Sparepart</td><td class="r">{{ $rp($t->parts_total) }}</td></tr>
<tr><td><b>Total</b></td><td class="r"><b>{{ $rp($t->total) }}</b></td></tr>
<tr><td>DP dibayar</td><td class="r">{{ $rp($t->deposit) }}</td></tr>
<tr><td><b>Sisa dibayar</b></td><td class="r"><b>{{ $rp(max($t->total - $t->deposit, 0)) }}</b></td></tr>
@elseif ($t->status === 'batal')
<tr><td>Biaya pengecekan</td><td class="r">{{ $rp($t->labor_fee) }}</td></tr>
<tr><td>DP dibayar</td><td class="r">{{ $rp($t->deposit) }}</td></tr>
<tr><td><b>DP dikembalikan</b></td><td class="r"><b>{{ $rp(max($t->deposit - $t->labor_fee, 0)) }}</b></td></tr>
@else
<tr><td>DP dibayar</td><td class="r">{{ $rp($t->deposit) }}</td></tr>
@endif
</table><hr>
@if ($t->public_token)<div style="text-align:center;font-size:12px">Cek status servis:<br>{{ route('lacak', $t->public_token) }}<br>Masuk pelanggan: {{ route('pelanggan.masuk') }}</div><hr>@endif
<p style="text-align:center;font-size:12px">@if ($t->status === 'diterima')Bawa nota ini saat mengambil HP.@else Simpan nota ini sebagai bukti.@endif</p></body></html>
