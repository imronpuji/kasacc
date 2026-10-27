<!doctype html>
<html lang="id"><head><meta charset="utf-8"><title>Slip gaji {{ sprintf('%02d/%04d', $run->month, $run->year) }}</title>
<style>
body{font-family:system-ui,sans-serif;color:#111;margin:0;padding:16px;background:#f3f4f6}
.slip{background:#fff;max-width:620px;margin:0 auto 24px;padding:24px;border:1px solid #d1d5db;page-break-after:always}
h1{font-size:18px;margin:0}h2{font-size:13px;margin:16px 0 4px;text-transform:uppercase;color:#4b5563}
table{width:100%;border-collapse:collapse;font-size:13px}td{padding:3px 0}td.r{text-align:right}
.tot td{border-top:1px solid #111;font-weight:700;padding-top:6px}
@media print{body{background:#fff;padding:0}.slip{border:0;margin:0}}
</style></head><body>
@php $rp = fn ($n) => 'Rp'.number_format((int) $n, 0, ',', '.'); @endphp
@foreach ($slips as $s)
<div class="slip">
  <h1>Slip Gaji {{ sprintf('%02d/%04d', $run->month, $run->year) }}</h1>
  <div style="font-size:13px;color:#4b5563">{{ $run->branch->name }} · {{ $s->employee->name }} ({{ $s->employee->position }}) · PTKP {{ $s->employee->ptkp }}</div>
  <h2>Pendapatan</h2>
  <table>
    <tr><td>Gaji pokok</td><td class="r">{{ $rp($s->base) }}</td></tr>
    <tr><td>Tunjangan tetap</td><td class="r">{{ $rp($s->allowance) }}</td></tr>
    <tr><td>Lembur</td><td class="r">{{ $rp($s->overtime) }}</td></tr>
    <tr><td>Komisi servis</td><td class="r">{{ $rp($s->commission) }}</td></tr>
    <tr><td>Potongan alpa</td><td class="r">-{{ $rp($s->absence_deduction) }}</td></tr>
    <tr><td>Potongan terlambat</td><td class="r">-{{ $rp($s->late_deduction) }}</td></tr>
    <tr class="tot"><td>Total pendapatan</td><td class="r">{{ $rp($s->income) }}</td></tr>
  </table>
  <h2>Potongan</h2>
  <table>
    <tr><td>BPJS Kesehatan</td><td class="r">{{ $rp($s->emp_kes) }}</td></tr>
    <tr><td>JHT</td><td class="r">{{ $rp($s->emp_jht) }}</td></tr>
    <tr><td>JP</td><td class="r">{{ $rp($s->emp_jp) }}</td></tr>
    <tr><td>PPh 21</td><td class="r">{{ $rp($s->pph21) }}</td></tr>
    <tr><td>Cicilan kasbon</td><td class="r">{{ $rp($s->advance_deduction) }}</td></tr>
    <tr class="tot"><td>Total potongan</td><td class="r">{{ $rp($s->total_deductions) }}</td></tr>
  </table>
  <table style="margin-top:12px"><tr class="tot"><td style="font-size:15px">Gaji bersih ({{ $s->pay_method === 'bank' ? 'transfer' : 'tunai' }})</td><td class="r" style="font-size:15px">{{ $rp($s->net_pay) }}</td></tr></table>
  <h2>Ditanggung perusahaan (tidak memotong gaji)</h2>
  <table>
    <tr><td>BPJS Kesehatan</td><td class="r">{{ $rp($s->er_kes) }}</td></tr>
    <tr><td>JHT + JP</td><td class="r">{{ $rp($s->er_jht + $s->er_jp) }}</td></tr>
    <tr><td>JKK + JKM</td><td class="r">{{ $rp($s->er_jkk + $s->er_jkm) }}</td></tr>
  </table>
</div>
@endforeach
</body></html>
