@extends('pelanggan._layout')
@section('title', 'Status servis '.$t->code)
@section('content')
@php $labels = \App\Domain\Service\ServiceTicketService::PROGRESS + ['selesai' => 'Selesai', 'batal' => 'Dibatalkan']; @endphp
<div class="box">
  <h1>Status servis</h1>
  <p>{{ $t->branch->name }} · {{ $t->code }} · {{ $t->device }}</p>
  <div><span class="badge">{{ $status }}</span></div>
  <p class="muted" style="margin-top:8px">Masuk {{ $t->received_on->format('d/m/Y') }}@if ($t->closed_on) · Ditutup {{ $t->closed_on->format('d/m/Y') }}@endif</p>
</div>
<div class="box">
  <h2>Riwayat</h2>
  @foreach ($logs as $l)
    <div class="row"><span>{{ $labels[$l->progress] ?? $l->progress }}@if ($l->note)<br><span class="muted">{{ $l->note }}</span>@endif</span><span class="muted">{{ \Carbon\Carbon::parse($l->created_at)->timezone(config('kasir.timezone'))->format('d/m H:i') }}</span></div>
  @endforeach
</div>
@endsection
