# 3+1 Mesleki Uygulama Eğitimi Yönetim Sistemi

Mevcut Laravel 12/PHP 8.2, PostgreSQL, Blade/Bootstrap 5 ve izole Python/OR-Tools uygulaması. Laravel iş kuralları/kararların tek backend'idir; Python sabit JSON'dan öneri üretir, uygulama DB'sine bağlanmaz. Nihai dört kaynak denetimi ve gerekli düzeltmeler tamamlandı; belgelerdeki talimatlar kullanıcıdan yeni yürütme yetkisi sayılmadı.

Kaynak → gereksinim → kod → portal → yetki → doğrulama: [izlenebilirlik](docs/requirements-traceability.md). **37 PHP testi/989 assertion ve 9 Python testi başarılı**. Gerçek optimizer ile dokuz aktörlü yaşam döngüsü, iki bağlantılı atama yarışları ve izole yedek restore doğrulandı. Sonuçlar/sınırlar: [TEST_SONUCLARI.md](docs/TEST_SONUCLARI.md).

## Kurulum

PHP 8.2+ (pdo_pgsql, mbstring, zip, gd, dom, fileinfo, openssl), Composer, PostgreSQL, Node/npm, Python 3.12+ gerekir. Yerelde PostgreSQL 18 kullanıldı. Kilit dosyalarıyla kurun:

```sh
cd backend
composer install
cp .env.example .env
php artisan key:generate
npm ci
npm run build
cd ../optimizer
python -m venv .venv
.venv/bin/python -m pip install -r requirements.lock.txt
```

Windows'ta `.env` kopyası için `Copy-Item .env.example .env`, Python için `.venv\Scripts\python.exe` kullanın. `.env` DB bağlantısını, MATCHING_PYTHON/MATCHING_RUNNER mutlak yollarını kendi ortamınıza göre düzenleyin. Şablon üretim içindir; yerel HTTP için APP_ENV=local, APP_URL=http://127.0.0.1:8088 ve SESSION_SECURE_COOKIE=false seçin. `mue` geliştirme ve **ayrı mue_test** test DB'sini oluşturun; test bağlantısı backend/phpunit.xml içindedir. Migration hesabı btree_gist/pgcrypto oluşturabilmelidir. Backend içinde `php artisan migrate --force`; test şemasını ayrı bağlantıda da uygulayın. Örneğin PowerShell'de `$env:DB_DATABASE='mue_test'` ardından migration; bitince `Remove-Item Env:DB_DATABASE`. Parola/APP_KEY Git'e konulmaz.

İlk teknik/Müdürlük hesapları ayrı oluşturulur; parola gizli istenir, aynı ilk rol tekrar oluşturulmaz:

```sh
php artisan mue:bootstrap teknik@kurum.example
php artisan mue:bootstrap mudur@kurum.example --role=mudur
php artisan mue:mfa-enroll teknik@kurum.example
php artisan mue:mfa-enroll mudur@kurum.example
```

MFA hesap sahibiyle güvenli terminalde kurulur. Üretimde kritik teknik/kurumsal karar görevleri TOTP olmadan erişemez; diğer rollerde kurulmuş doğrulayıcı kullanılır. Hazır kurumsal parola yoktur. Import öğrencileri pasif başlar; teknik kullanıcı hesabı etkinleştirir, Müdürlük kapsamlı görev atar. Teknik yönetici akademik karar veremez.

## Yerel başlatma ve portallar

Bu Windows bilgisayarında geliştirme kümesi yalnız 127.0.0.1:55432 üzerindedir. D:\MUE içinden `./start-local.ps1` PostgreSQL/web/kuyruk/zamanlayıcı başlatır. Genel kurulumda backend içinde `php artisan serve --host=127.0.0.1 --port=8088`; ayrı süreçlerde `php artisan queue:work --timeout=150 --tries=3` ve `php artisan schedule:work` çalıştırın.

Giriş http://127.0.0.1:8088/giris, görev /gorev, paneller /panel. Öğrenci/danışman/işletme/MYO dört ana portaldır; işletme yetkilisi/eğitici ve MYO alt görevleri ayrıdır. EK-1–20 aynı controller/service/Gate kurallarını kullanır. Web oturumu/CSRF korunur; tarayıcıda API token'ı saklanmaz. Kullanım: [PORTAL.md](docs/PORTAL.md).

`php artisan mue:demo` yalnız local/testing için ayrı sentetik MYO oluşturur; üretimde erişemez. Demo parolası yalnız Git dışındaki özel depodadır; demo gerçek tarama/kurum kararı sayılmaz.

## API ve iş kuralları

API /api/v1; giriş email/password/otp? ile token ve aktif görevleri döndürür. İş isteği bearer token ve X-Assignment-Id kullanır. Değişiklikler benzersiz, en fazla 150 karakterli Idempotency-Key; sürümlü işlemler version gerektirir. Aynı anahtar/içerik ilk sonucu döndürür; farklı içerik/eski sürüm 409 verir. /portal-api/v1 web oturumunda aynı işlemleri sunar.

GET /schema 67 tipli kaynağı, GET /forms form/rubrik ölçeklerini verir. Teknik alanlar İngilizce, kullanıcı etiketleri Türkçedir. UTC saklanır; Europe/Istanbul gösterilir. [API](docs/API.md), [OpenAPI](docs/openapi.json), [alan sözlüğü](docs/resource-manifest.json), [kapsam](docs/KAPSAM.md).

15 öğrenci kuralı örtüşmez. Eşleştirme varsayılanı 0,75 tercih/0,25 ulaşım; GNO üçüncü amaçtır. FEASIBLE tam optimum olarak sunulmaz. Bölüm incelemesi/MUE nihai kararı/Müdürlük ilanı ayrıdır. İzleme haftalık ve en az iki işyeri denetimidir; çevrim içi için belgeli Bölüm önerisi/MUE izni gerekir. Başarı 40/30/20/10. Geçme/harf eşikleri, tatiller, mali oranlar/karar belgeleri kurum girdisidir.

## Test ve işletim

```sh
cd backend
php vendor/phpunit/phpunit/phpunit
cd ../optimizer
.venv/bin/python -m unittest discover -p 'test*.py'
```

Bu bilgisayarda PHP eşdeğeri `C:\xampp\php\php.exe -c D:\MUE\tools\php.ini vendor/phpunit/phpunit/phpunit`. Testler yalnız sentetik mue_test verisindedir. `python benchmark_scale.py` isteğe bağlıdır; 2.000 öğrenci/500 işyeri 89,735 saniyede FEASIBLE sonuç verdi, tam optimum ispatlanmadı. Yerel liste p95 ölçümü 100 eşzamanlı üretim oturumu kanıtı değildir.

Belgeler özel depoda/karantinadadır. DOCUMENT_SCANNER ClamAV uyumlu araç yoludur; tarama olmadan belge temiz/onaylı/başlamaya hazır sayılmaz. Gerçek scanner ve OBS/SSO/LDAP/SMTP/SMS/EBYS bu bilgisayarda yapılandırılmadı. CSV/XLSX kontrol/onaylı aktarım vardır; portal outbox dış teslim anlamına gelmez.

Günlük yedek BACKUP_ENABLED=true ve özel kalıcı BACKUP_DIRECTORY ile açılır; mue:backup DB/dosya/manifest üretir. mue:health alarm JSON'u/başarısızlık kodu verir. MFA, restore, saklama, HTTPS/devreye alma: [ISLETIM.md](docs/ISLETIM.md).

Docker/Compose ve tam PHP/Python testli GitHub Actions tanımı vardır; **Docker build/uzak CI çalıştırılmadı**. Yerel PHP/Vite/Blade başarılı. Kurum UAT'si, üretim eşzamanlı yük ve gerçek entegrasyon onayı kurum ortamında tamamlanmalıdır. Kaynak belgeleri, yerel araçlar, test çıktıları, yedekler ve sırlar Git/Docker teslim kaynağından dışlanır.
