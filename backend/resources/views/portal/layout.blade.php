<!doctype html>
<html lang="tr">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="{{ csrf_token() }}"><title>@yield('title','Mesleki Uygulama Eğitimi') · Fırat Üniversitesi</title>@vite(['resources/css/app.css','resources/js/app.js'])</head>
<body class="@yield('body-class')">
<a class="skip-link" href="#main">İçeriğe geç</a>
@yield('content')
<div id="notice" class="toast-region" aria-live="polite" aria-atomic="true"></div>
<dialog id="confirm-dialog" aria-labelledby="confirm-title"><form method="dialog"><h2 id="confirm-title">İşlemi onaylayın</h2><p id="confirm-message"></p><div class="d-flex gap-2 justify-content-end"><button class="btn btn-light" value="cancel">Vazgeç</button><button class="btn btn-primary" value="confirm">Onayla ve devam et</button></div></form></dialog>
<dialog id="form-dialog" aria-labelledby="form-title"><header class="dialog-header"><div><span class="eyebrow">MESLEKİ UYGULAMA EĞİTİMİ</span><h2 id="form-title">Kayıt formu</h2></div><button type="button" class="btn btn-light" data-close-dialog aria-label="Formu kapat"><i class="bi bi-x-lg" aria-hidden="true"></i></button></header><div id="form-content"></div></dialog>
</body></html>
