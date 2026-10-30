<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Struk {{ $sale->receipt_no }}</title>
<style>body{font-family:monospace;width:300px;max-width:100%;margin:auto}table{width:100%}td.r{text-align:right}hr{border:0;border-top:1px dashed #000}</style></head>
<body onload="window.print()">
@php($attrs = $sale->branch->getAttributes())
<h3 style="text-align:center;margin-bottom:4px">{{ $sale->branch->name }}</h3>
@if (! empty($attrs['address']) || ! empty($attrs['phone']))
<div style="text-align:center;font-size:12px">{{ $attrs['address'] ?? '' }}@if (! empty($attrs['address']) && ! empty($attrs['phone']))<br>@endif @if (! empty($attrs['phone']))Telp {{ $attrs['phone'] }}@endif</div>
@endif
<hr>
@if ($sale->voided_at)<div style="text-align:center;font-weight:bold;border:2px solid #000;padding:4px;margin-bottom:6px">STRUK DIBATALKAN</div>@endif
<div>{{ $sale->receipt_no }}<br>{{ $sale->sold_on->format('d/m/Y') }} {{ $sale->created_at?->timezone(config('kasir.timezone', config('app.timezone')))->format('H:i') }}<br>Kasir: {{ $sale->cashier->name ?? '-' }}</div><hr>
<table>@foreach ($sale->items as $i)<tr><td colspan="2">{{ $i->product->name }}</td></tr>
<tr><td>{{ $i->qty }} x {{ number_format($i->unit_price,0,',','.') }}</td><td class="r">{{ number_format($i->line_total,0,',','.') }}</td></tr>@endforeach</table><hr>
@php($earned = (int) \App\Models\LoyaltyPoint::where('source_type', 'pos_sale')->where('source_id', $sale->id)->where('kind', 'earn')->value('points'))
<table>@if ($sale->discount > 0)<tr><td>Belanja</td><td class="r">Rp {{ number_format($sale->total,0,',','.') }}</td></tr>
<tr><td>Potongan {{ number_format($sale->points_redeemed,0,',','.') }} poin</td><td class="r">-Rp {{ number_format($sale->discount,0,',','.') }}</td></tr>@endif
<tr><td>Total</td><td class="r">Rp {{ number_format($sale->total - $sale->discount,0,',','.') }}</td></tr>
<tr><td>Dibayar dengan</td><td class="r">{{ ['kas'=>'Tunai','bank'=>'Transfer'][$sale->payment_method] ?? \App\Domain\Accounting\MoneyAccounts::label($sale->payment_method) }}</td></tr></table>
@if ($earned > 0)<p style="text-align:center;margin:6px 0">Poin didapat: {{ number_format($earned,0,',','.') }}</p>@endif
<p style="text-align:center">Terima kasih, sampai jumpa lagi</p></body></html>
