@extends('pelanggan._layout')
@section('title', 'Masuk pelanggan')
@section('content')
<div class="box">
  <h1>Masuk pelanggan</h1>
  <p>Lihat servis dan belanja Anda. Masukkan nomor HP yang Anda berikan ke toko, kami kirim kode masuk.</p>
  @if ($errors->any())<div class="err" role="alert">{{ $errors->first() }}</div>@endif
  <form method="post" action="{{ route('pelanggan.kode') }}">@csrf
    <label for="phone">Nomor HP</label>
    <input id="phone" name="phone" type="tel" inputmode="numeric" autocomplete="tel" placeholder="081234567890" value="{{ old('phone') }}" required autofocus>
    <button type="submit">Kirim kode</button>
  </form>
</div>
@endsection
