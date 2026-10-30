@extends('pelanggan._layout')
@section('title', 'Masuk pelanggan')
@section('content')
<div class="box">
  <h1>Masuk pelanggan</h1>
  <p>Lihat servis dan belanja Anda. Masukkan email yang Anda berikan ke toko, kami kirim kode masuk ke email itu.</p>
  @if ($errors->any())<div class="err" role="alert">{{ $errors->first() }}</div>@endif
  <form method="post" action="{{ route('pelanggan.kode') }}">@csrf
    <label for="email">Email</label>
    <input id="email" name="email" type="email" autocomplete="email" placeholder="nama@email.com" value="{{ old('email') }}" required autofocus>
    <button type="submit">Kirim kode</button>
  </form>
</div>
@endsection
