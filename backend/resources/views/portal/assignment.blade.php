@extends('portal.layout')
@section('title','Görev seçimi')
@section('body-class','auth-page')
@section('content')
<main id="main" class="assignment-shell card"><img src="{{ asset('images/firat-logo.png') }}" alt="Fırat Üniversitesi" width="64" class="mb-4"><span class="eyebrow">HOŞ GELDİNİZ, {{ auth()->user()->name }}</span><h1>Hangi görevinizle devam edeceksiniz?</h1><p class="muted">Her görev yalnız tanımlı kurum, program, dönem ve öğrenci kapsamına erişir.</p>@if(session('error'))<div class="alert alert-warning">{{ session('error') }}</div>@endif
@forelse($assignments as $a)<form method="post" action="/gorev" class="mb-2">@csrf<input type="hidden" name="assignment_id" value="{{ $a->id }}"><button class="assignment-choice" type="submit"><span><strong>{{ config('portal.values.'.$a->role) }}</strong><small>{{ \App\Domain\Records::get('institutions',$a->institution_id)->name }}@if($a->program_id) · {{ \App\Domain\Records::get('programs',$a->program_id)->name }}@endif @if($a->company_id) · {{ \App\Domain\Records::get('companies',$a->company_id)->legal_name }}@endif</small></span><i class="bi bi-arrow-right" aria-hidden="true"></i></button></form>@empty<div class="empty-state"><i class="bi bi-person-lock"></i><h2>Aktif görevlendirme bulunamadı</h2><p>Hesabınız için yetkili görev yöneticinizin görevlendirme yapması gerekiyor.</p></div>@endforelse
<form method="post" action="/cikis" class="mt-4">@csrf<button class="btn btn-light">Oturumu kapat</button></form></main>
@endsection
