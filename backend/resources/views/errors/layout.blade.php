@extends('portal.layout')
@section('title','İşlem tamamlanamadı')
@section('content')
<main id="main" class="container py-5"><section class="panel-card mx-auto" style="max-width:640px"><img src="{{ asset('images/firat-logo.png') }}" width="64" height="64" alt="Fırat Üniversitesi"><p class="eyebrow mt-4">MESLEKİ UYGULAMA EĞİTİMİ · @yield('code')</p><h1 class="h3">@yield('message')</h1><p class="muted">@yield('help')</p><a class="btn btn-primary" href="/panel">Portala dön</a><a class="btn btn-light" href="/giris">Giriş sayfası</a></section></main>
@endsection
