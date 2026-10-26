<!doctype html><html lang="id"><head><meta charset="utf-8"><title>Struk {{ $sale->receipt_no }}</title>
<style>body{font-family:monospace;width:300px;margin:auto}table{width:100%}td.r{text-align:right}hr{border:0;border-top:1px dashed #000}</style></head>
<body onload="window.print()">
<h3 style="text-align:center">{{ $sale->branch->name }}</h3>
<div>{{ $sale->receipt_no }}<br>{{ $sale->sold_on->format('d/m/Y') }} · {{ $sale->cashier->name ?? '-' }}</div><hr>
<table>@foreach ($sale->items as $i)<tr><td colspan="2">{{ $i->product->name }}</td></tr>
<tr><td>{{ $i->qty }} × {{ number_format($i->unit_price,0,',','.') }}</td><td class="r">{{ number_format($i->line_total,0,',','.') }}</td></tr>@endforeach</table><hr>
<table><tr><td>Total</td><td class="r">{{ number_format($sale->total,0,',','.') }}</td></tr>
<tr><td>Bayar</td><td class="r">{{ ['kas'=>'Tunai','bank'=>'Transfer','qris'=>'QRIS'][$sale->payment_method] ?? $sale->payment_method }}</td></tr></table>
<p style="text-align:center">Terima kasih</p></body></html>
