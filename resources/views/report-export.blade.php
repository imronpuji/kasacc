<!doctype html>
<html lang="id"><head><meta charset="utf-8"><title>{{ $title }}</title>
<style>
 body{font-family:DejaVu Sans,sans-serif;font-size:11px;color:#111}
 h1{font-size:16px;margin:0 0 2px} .sub{color:#555;margin-bottom:12px}
 table{width:100%;border-collapse:collapse} td,th{border-bottom:1px solid #ddd;padding:4px 6px;text-align:left}
 th{background:#f1f3f5} .n{text-align:right}
</style></head><body>
<h1>{{ $title }}</h1>
<div class="sub">{{ $period }} &middot; {{ $branch }}</div>
<table>
 @foreach ($rows as $i => $row)
  <tr>
   @foreach ($row as $c => $cell)
    @if ($i === 0)<th class="{{ in_array($c, $money_columns) ? 'n' : '' }}">{{ $cell }}</th>
    @else<td class="{{ in_array($c, $money_columns) ? 'n' : '' }}">{{ is_int($cell) && in_array($c, $money_columns) ? number_format($cell, 0, ',', '.') : $cell }}</td>@endif
   @endforeach
  </tr>
 @endforeach
</table>
</body></html>
