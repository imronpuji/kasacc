<!doctype html>
<html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Slip gaji {{ sprintf('%02d/%04d', $run->month, $run->year) }}</title>
<style>
body{font-family:system-ui,sans-serif;color:#111;margin:0;padding:16px;background:#f3f4f6}
.slip{background:#fff;max-width:620px;margin:0 auto 24px;padding:24px;border:1px solid #d1d5db;page-break-after:always}
h1{font-size:18px;margin:0}h2{font-size:13px;margin:16px 0 4px;text-transform:uppercase;color:#4b5563}
table{width:100%;border-collapse:collapse;font-size:13px}td{padding:3px 0}td.r{text-align:right}
.tot td{border-top:1px solid #111;font-weight:700;padding-top:6px}
.bar{max-width:620px;margin:0 auto 12px;text-align:right}.bar button{padding:10px 18px;font-size:15px;border:0;border-radius:8px;background:#0B63F6;color:#fff;cursor:pointer}
.draft{background:#FEF3E2;color:#B45309;padding:6px 10px;font-size:13px;font-weight:700;margin:10px 0;border-radius:6px}
@media(max-width:480px){.slip{padding:14px}}
@media print{body{background:#fff;padding:0}.slip{border:0;margin:0}.bar{display:none}}
</style></head><body>
@php $rp = fn ($n) => 'Rp'.number_format((int) $n, 0, ',', '.'); @endphp
<div class="bar"><button type="button" onclick="window.print()">Cetak slip</button></div>
@foreach ($slips as $s)
<div class="slip">
  <h1>Slip Gaji {{ sprintf('%02d/%04d', $run->month, $run->year) }}</h1>
  <div style="font-size:13px;color:#4b5563">{{ $run->branch->name }} · {{ $s->employee->name }} ({{ $s->employee->position }}) · Status pajak: {{ $s->employee->ptkp }}</div>
  @if ($run->status === 'draft')<div class="draft">Konsep, belum disetujui</div>@endif
  <h2>Penghasilan dan penyesuaian</h2>
  <table>
    <tr><td>Gaji pokok</td><td class="r">{{ $rp($s->base) }}</td></tr>
    <tr><td>Tunjangan tetap</td><td class="r">{{ $rp($s->allowance) }}</td></tr>
    <tr><td>Lembur</td><td class="r">{{ $rp($s->overtime) }}</td></tr>
    <tr><td>Komisi servis</td><td class="r">{{ $rp($s->commission) }}</td></tr>
    @if ($s->thr > 0)<tr><td>Tunjangan hari raya (THR)</td><td class="r">{{ $rp($s->thr) }}</td></tr>@endif
    <tr><td>Potongan alpa</td><td class="r">-{{ $rp($s->absence_deduction) }}</td></tr>
    <tr><td>Potongan terlambat</td><td class="r">-{{ $rp($s->late_deduction) }}</td></tr>
    <tr class="tot"><td>Gaji kotor (setelah potongan absen)</td><td class="r">{{ $rp($s->income) }}</td></tr>
    @if ($s->income === 0 && ($s->absence_deduction + $s->late_deduction) > 0)<tr><td colspan="2" style="font-size:11px;color:#6b7280">Potongan alpa dan terlambat dibatasi sebesar penghasilan bulan ini.</td></tr>@endif
  </table>
  @php $tickets = app(\App\Domain\Payroll\PayrollService::class)->slipTickets($s); @endphp
  @if ($tickets->isNotEmpty())
  <h2>Rincian komisi servis ({{ rtrim(rtrim(number_format($s->employee->commission_bps / 100, 2, ',', '.'), '0'), ',') }}% dari ongkos servis)</h2>
  <table>
    @foreach ($tickets as $t)
    <tr><td>{{ $t->closed_on?->format('d/m') }} · {{ $t->code }} · {{ $t->device }}</td><td class="r">{{ $rp($t->labor_fee) }}</td></tr>
    @endforeach
    <tr class="tot"><td>{{ $tickets->count() }} unit · ongkos {{ $rp($tickets->sum('labor_fee')) }}</td><td class="r">{{ $rp($s->commission) }}</td></tr>
  </table>
  @endif
  <h2>Potongan dari gaji</h2>
  <table>
    <tr><td>BPJS Kesehatan</td><td class="r">{{ $rp($s->emp_kes) }}</td></tr>
    <tr><td>Jaminan Hari Tua (JHT)</td><td class="r">{{ $rp($s->emp_jht) }}</td></tr>
    <tr><td>Jaminan Pensiun (JP)</td><td class="r">{{ $rp($s->emp_jp) }}</td></tr>
    <tr><td>Pajak penghasilan (PPh 21)</td><td class="r">{{ $rp($s->pph21) }}</td></tr>
    <tr><td>Cicilan pinjaman gaji (kasbon)</td><td class="r">{{ $rp($s->advance_deduction) }}</td></tr>
    <tr class="tot"><td>Total potongan</td><td class="r">{{ $rp($s->total_deductions) }}</td></tr>
  </table>
  <table style="margin-top:12px"><tr class="tot"><td style="font-size:15px">Gaji bersih ({{ $s->pay_method === 'bank' ? 'transfer' : 'tunai' }})</td><td class="r" style="font-size:15px">{{ $rp($s->net_pay) }}</td></tr></table>
  <h2>Ditanggung perusahaan (tidak memotong gaji)</h2>
  <table>
    <tr><td>BPJS Kesehatan</td><td class="r">{{ $rp($s->er_kes) }}</td></tr>
    <tr><td>Jaminan Hari Tua (JHT) + Jaminan Pensiun (JP)</td><td class="r">{{ $rp($s->er_jht + $s->er_jp) }}</td></tr>
    <tr><td>Jaminan Kecelakaan Kerja (JKK) + Jaminan Kematian (JKM)</td><td class="r">{{ $rp($s->er_jkk + $s->er_jkm) }}</td></tr>
  </table>
</div>
@endforeach
</body></html>
