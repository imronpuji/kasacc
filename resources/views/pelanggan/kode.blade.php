@extends('pelanggan._layout')
@section('title', 'Isi kode')
@section('content')
<div class="box">
  <h1>Isi kode</h1>
  <p>Jika nomor {{ $phone }} terdaftar di toko, kode 6 angka sudah dikirim. Kode berlaku 5 menit.</p>
  @if ($errors->any())<div class="err" role="alert">{{ $errors->first() }}</div>@endif
  <form method="post" action="{{ route('pelanggan.verifikasi.kirim') }}">@csrf
    <input type="hidden" name="phone" value="{{ $phone }}">
    <label for="code">Kode 6 angka</label>
    <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="123456" required autofocus>
    <button type="submit">Masuk</button>
  </form>
  <p style="margin-top:12px"><a href="{{ route('pelanggan.masuk') }}">Ganti nomor atau minta kode baru</a></p>
</div>
@endsection
