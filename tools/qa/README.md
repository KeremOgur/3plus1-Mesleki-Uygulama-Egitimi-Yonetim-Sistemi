# Local sentetik son QA araçları

Yalnızca `D:\MUE` ve local/testing verisi içindir. Parolalar/tokenlar çıktı raporlarına yazılmaz. Sekiz demo hesabı için `backend/storage/app/private/demo-credentials.json` gerekir. Çıktılar `backend/storage/logs/full-qa-20261007/` altındadır ve Git'e alınmaz.

Backend testleri ayrı `mue_test` DB'sini kullanır. Windows'ta PHP alt süreçlerinin de doğru eklentileri yüklemesi için `PHPRC` ayarlanmalıdır; doğrudan PHPUnit çağrısı tercih edilir:

```powershell
$env:PHPRC='D:\MUE\tools\php.ini'
Set-Location D:\MUE\backend
& C:\xampp\php\php.exe -c D:\MUE\tools\php.ini vendor/phpunit/phpunit/phpunit --log-junit storage/logs/full-qa-20261007/final-junit.xml
npm run build
& C:\xampp\php\php.exe -c D:\MUE\tools\php.ini artisan view:cache
Set-Location D:\MUE\optimizer
& .\.venv\Scripts\python.exe -m unittest discover -p 'test*.py' -v
```

Başlatma: kökte `start-local.ps1`; tarayıcı adresi `http://127.0.0.1:8088`. Demo seeder için backend içinde `artisan mue:demo`. Aşağıdaki araçlar gerçek local HTTP kullanır:

```powershell
Set-Location D:\MUE
& C:\xampp\php\php.exe -c tools/php.ini tools/qa/seed_http_security.php
& optimizer/.venv/Scripts/python.exe tools/qa/full_system_http.py
& optimizer/.venv/Scripts/python.exe tools/qa/http_security.py
& optimizer/.venv/Scripts/python.exe tools/qa/final_smoke.py
& optimizer/.venv/Scripts/python.exe tools/qa/export_formats.py
& C:\xampp\php\php.exe -c tools/php.ini tools/export_contract.php
& optimizer/.venv/Scripts/python.exe tools/build_openapi.py
& optimizer/.venv/Scripts/python.exe tools/qa/check_openapi.py
& C:\xampp\php\php.exe -c tools/php.ini tools/qa/cleanup_local_qa.php
```

Güvenlik turunu tarama/bearer login turundan sonra throttling penceresi geçince başlatın. MFA test hesabının secret/counter'ını yeniden hazırlamak için güvenlik seeder'ını tekrar çalıştırın. Son temizlik yalnızca işaretli sentetik MFA hesabını/görevini kapatır ve işaretli `qa-http.png` belgelerinin beklenen scanner failed-job kayıtlarını arşivler; belgeleri temiz yapmaz. Eski kanıtlar üzerine yazılacağından ihtiyaç varsa önce bu QA çıktı klasörünü proje içinde farklı bir kanıt klasörüne kopyalayın.

`build_http_lifecycle.py` mevcut `CompleteLifecycleTest` senaryosundan gerçek HTTP sürümünü üretir. Çalıştırmadan önce **yeni, ayrı** `mue_qa_lifecycle_*` PostgreSQL DB oluşturun ve migration'ları bu DB'ye uygulayın. Aynı DB ortam değişkenleriyle 8092 üzerinde PHP web süreci ve database queue worker açın; ana `mue` servislerini kullanmayın. PHPUnit dosyasını `storage/logs/full-qa-20261007/HttpLifecycleQaTest.php` yolundan, `DB_DATABASE` ayrı DB adı ve `QUEUE_CONNECTION=database` ile çalıştırın. Fixture'lar bu DB'de commit edilir; resmî/AV metadata sentetiktir. İş bittiğinde yalnızca bu geçici süreçleri ve DB'yi kaldırın. Global Laravel config cache'i bu ayrı DB testleri sırasında açık olmamalıdır.

`queue_outage.php`, tamamlanmış yaşam döngüsünün ayrı DB'sinde ve 8092 sunucusunda çalışır. Yalnızca o QA worker'ı durmuşken boş adaylı yeni sentetik dönem/politika oluşturur, HTTP 202 ve kalıcı `kuyrukta` kaydını kontrol eder. Aynı DB ile `queue:work --once --tries=1` çalıştırdıktan sonra `queue_outage.php --verify`, `tamamlandi` ve boş kuyruk doğrular. Bu ek test kapasite/yerleştirme kalitesini ölçmez.

Tarayıcı sonuçları Computer Use ile gerçek UI üzerinden alınmıştır; bu Python araçları tarayıcı render/console kontrolünün yerine geçmez. Görseller ve kapsam sınırları `docs/FULL_SYSTEM_QA_2026-10-07.md` içindedir.
