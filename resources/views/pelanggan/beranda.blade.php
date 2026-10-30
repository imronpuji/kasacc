@extends('pelanggan._layout')
@section('title', 'Akun pelanggan')
@section('content')
@php
    $rp = fn ($n) => 'Rp '.number_format((int) $n, 0, ',', '.');
    $kind = ['garansi' => 'Garansi', 'produk' => 'Barang', 'layanan' => 'Layanan', 'lainnya' => 'Lainnya'];
    $cstatus = ['baru' => 'Diterima', 'diproses' => 'Diproses', 'selesai' => 'Selesai'];
@endphp
<div class="box">
  <h1>Halo, {{ $customer->name }}</h1>
  <p>{{ $customer->phone }}</p>
  <form method="post" action="{{ route('pelanggan.keluar') }}">@csrf<button class="link" type="submit">Keluar</button></form>
</div>
<div class="box">
  <h2>Servis</h2>
  @forelse ($tickets as $t)
    <div class="row"><span><a href="{{ route('lacak', $t->public_token) }}">{{ $t->code }}</a> · {{ $t->device }}<br><span class="muted">{{ $t->received_on->format('d/m/Y') }} · {{ $t->branch->name }}</span></span>
      <span style="text-align:right"><span class="badge">{{ \App\Domain\Service\ServiceTicketService::publicStatus($t) }}</span>@if ($t->status === 'selesai')<br>{{ $rp($t->total) }}@endif</span></div>
  @empty
    <p>Belum ada servis.</p>
  @endforelse
</div>
<div class="box">
  <h2>Belanja</h2>
  @forelse ($sales as $s)
    <div class="row"><span>{{ $s->receipt_no }}<br><span class="muted">{{ $s->sold_on->format('d/m/Y') }} · {{ $s->branch->name }}</span></span><span style="text-align:right">{{ $rp($s->total) }}@if ($s->voided_at)<br><span class="muted">Dibatalkan</span>@endif</span></div>
  @empty
    <p>Belum ada belanja yang tercatat dengan nomor ini.</p>
  @endforelse
</div>
@if ($complaints->isNotEmpty())
<div class="box">
  <h2>Komplain</h2>
  @foreach ($complaints as $c)
    <div class="row"><span>{{ $kind[$c->type] ?? $c->type }} · {{ $c->received_on->format('d/m/Y') }}@if ($c->resolution)<br><span class="muted">{{ $c->resolution }}</span>@endif</span><span class="badge">{{ $cstatus[$c->status] ?? $c->status }}</span></div>
  @endforeach
</div>
@endif
@endsection
