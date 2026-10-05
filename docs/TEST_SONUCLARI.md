# Nihai doğrulama — 5 Ekim 2026

Dört özgün kaynak yeniden incelendi; mevcut uygulama üzerinde bulunan kusurlar düzeltildi. Gereksinim/kod/portal/yetki/doğrulama eşlemesi [requirements-traceability.md](requirements-traceability.md) içindedir. Test sonucu kurum kabulü veya üretim SLA onayı değildir.

Ortam: Windows, PHP 8.2.12, Laravel 12.69.3, PostgreSQL 18, Python 3.12.14, OR-Tools 9.15.6755. Son kapsamlı PHPUnit: **37 test / 989 assertion / 0 hata / 0 başarısızlık / 0 atlama**, 31,921 saniye. Son incelemede bulunan eylem durumu/okuma yetkisi kusurları giderildikten sonra tam paket tekrar çalıştırıldı. Python keşif paketi **9 test** ile başarılıdır.

| Paket / kontrol | Sonuç ve kapsam |
|---|---|
| BackendSmokeTest (5), ApiOperationSmokeTest (2) | Giriş/kapsam/IDOR, teknik rol, uygunluk, kapasite, audit, devam 120/121 saat, anlık görüntü ve başarı hesabı |
| PortalSmokeTest (4) | Dört portal ve ayrı eğitici, rapor, CSRF/oturum görevi, politika özeti, Türkçe CSV, karantina, yapısal EK alanlarının JSON turu |
| SourceComplianceTest (10) | 14/15 sınırı, görüşme çifti, eksik ulaşım, tercih sürümü/eskime, profil akademik alanları, özel belgeler/MIME, dondurulmuş alt satırlar, itiraz ve nakil izleme |
| CompleteLifecycleTest (1) | Dokuz aktörün gerçek API işlemleri: işletme/protokol/teklif/uygunluk → tercih → gerçek Python önerisi → Bölüm/MUE kararı → Müdürlük ilanı → başlama/plan → 75 gün devam/15 rapor/15 izleme/2 işyeri denetimi → portfolyo/dosya/sunum/değerlendirme → not ilanı/itiraz |
| QualityComplianceTest (6) | Bölüm önerisi/MUE çevrim içi izni, yıllık kurul ret/onay, eksik öğrencinin çıktı raporu, özel geri bildirim, danışmanın kanıtlı iyileştirme akışı, şirket mali/uygunsuzluk okuma izolasyonu |
| ConcurrencyTest (2) | İki ayrı PHP süreci/DB bağlantısıyla son kontenjan ve çift etkin atama yarışı: bir başarı, bir DB kısıtı reddi |
| BackupRestoreTest (2) | Gerçek pg_dump ve ayrı geçici DB'ye pg_restore; migration/yerleştirme/audit/sürüm/belge/görev satırları, FK ve özel dosya SHA256; kapalı yedek başarısızlığı |
| MfaTest (2) | RFC 6238 vektörü; üretimde kurulum/OTP zorunluluğu, doğrulanmış token, kod tekrarının reddi ve şifreli/gizli secret |
| PerformanceTest (1) | 2.001 öğrenci, 20 sıralı süreç içi API isteği, 100 kayıtlık sayfa/tam yetkili toplam, istek başına 20'den az sorgu; p95 **0,04674 saniye** |
| Unit/Feature Example (2) | Temel çalıştırma ve /up |
| Python discovery (9) | Snapshot/seed tekrarları, puan/GNO önceliği, 20 küçük rastgele tam sayımlı karşılaştırma, sıfır atamalı uygulanabilir çözüm, şube/eğitici/sabit atama kısıtları |
| Büyük eşleştirme örneği | 2.000 öğrenci/500 işyeri/8.000 uygun çift: 90 saniye bütçede 2.000 yerleşme, **89,735 saniye**, FEASIBLE, proven_optimal=false; tam optimum ispatlanmadı |
| PHP/frontend | 82 PHP dosyası syntax, Vite üretim derlemesi ve Blade view cache başarılı |
| Tarayıcı | Önceki dört portal/rapor kanıtları korundu; finalde öğrenci paneli, EK merkezi, EK-17 10/5/13 alan ve 390×844 taşma kontrolü |
| Sözleşme | 67 tipli kaynak, 277 OpenAPI yolu; migration 700 geliştirme/test DB'sinde uygulandı |

## Tekrarlanabilir çalıştırma

Composer/npm/Vite/Python bağımlılıkları ve ayrı **mue_test** şeması gerekir. Üretim DB'sini test hedefi yapmayın.

```powershell
cd D:\MUE\backend
& 'C:\xampp\php\php.exe' -c 'D:\MUE\tools\php.ini' vendor/phpunit/phpunit/phpunit
cd D:\MUE\optimizer
& '.\.venv\Scripts\python.exe' -m unittest discover -p 'test*.py'
```

Taşınabilir eşdeğer: backend içinde `php vendor/phpunit/phpunit/phpunit`, optimizer sanal ortamında `python -m unittest discover -p 'test*.py'`. Windows özel ini için doğrudan PHPUnit kullanın; artisan test alt sürece ini aktarmayabilir. İsteğe bağlı 90 saniyelik ölçek örneği `python benchmark_scale.py`; varsayılan CI paketi değildir.

## Kanıt sınırları

Testler sentetiktir. Çoğu özellik testi transaction geri alma kullanır; yarış/restore yalnız mue_test üzerinde ayrı etiketli sentetik kayıtlarla çalışır. Benzersiz mue_restore_test_... DB sonuçta kaldırılır. Kurumsal üretim kayıtları test edilmedi. JUnit/benchmark çıktıları backend/storage/logs altında Git dışındadır.

Yaşam döngüsü fixture'ının imzalı/temiz belge metadata'sı **gerçek antivirüs veya e-imza entegrasyonu kanıtı değildir**. Karantina testleri taranmamış dosyanın indirilmesini/onayını engeller. Yapısal risk formu ayrıca portal/API turuyla kontrol edildi; yaşam döngüsü tüm risk/olay/mali varyantları sınamaz. Matriste otomatik test ve kod/alan denetimi ayrılmıştır.

Liste ölçümü sıralı yerel süreç içi testtir; **100 eşzamanlı üretim oturumu ölçülmedi**. Tek büyük optimizer örneği tüm yoğun veri setleri için süre garantisi değildir. İzole gerçek restore geçti; üretim RPO 24 saat/RTO 8 saat hedefleri ölçülmüş SLA değildir.

Docker mevcut olmadığından gerçek imaj/Compose çalıştırması yapılmadı. Uzak CI, kurum UAT'si, bağımsız erişilebilirlik/güvenlik incelemesi ve üretim yük onayı yapılmış sayılmaz. OBS/SSO/LDAP, SMTP/SMS, EBYS/e-imza, gerçek scanner ve merkezi alarm bağlantıları kurum yapılandırması gerektirir.

Önceki portal kanıtları: [PORTAL.md](PORTAL.md), [rapor](ui/rapor-incelemesi.jpg), [mobil](ui/mobil-panel.jpg). Önceki Prompt 1/2 belgeleri tarihsel kayıttır; güncel sonuç bu dosyadır.
