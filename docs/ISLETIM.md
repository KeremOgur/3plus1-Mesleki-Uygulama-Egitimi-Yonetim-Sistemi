# İşletim ve geri dönüş

## Kurulum ve hesaplar

[README](../README.md) taşınabilir kurulum adımlarını içerir. PHP/Composer/npm/Vite, Python 3.12+ ve PostgreSQL gerekir. Migration hesabı btree_gist/pgcrypto oluşturabilmelidir; çalışma DB hesabı kısıtlanmalıdır. .env kurum değerleriyle düzenlenir, APP_KEY üretilir, migration/build uygulanır.

Üretim: APP_ENV=production, APP_DEBUG=false, SESSION_SECURE_COOKIE=true, HTTPS reverse proxy, kalıcı özel depo. İlk teknik/Müdürlük hesapları mue:bootstrap ile ayrı kurulur; görevlendirmeyi yetkili kurum personeli yapar.

`php artisan mue:mfa-enroll hesap@kurum.example` güvenli terminalde hesap sahibinin doğrulayıcısını kurar. Anahtar log/Git'e konulmaz; TOTP doğrulanmadan saklanmaz. Kurulum eski API token'larını iptal eder. Üretimde sistem yöneticisi, Müdürlük, MUE/Bölüm komisyonu, program başkanı ve belge görevlisi MFA kullanır. Doğrulamasız eski token/oturum kritik göreve erişemez. Kurulmuş hesabı sessiz sıfırlama yoktur; kayıp doğrulayıcı için kurum kimlik/kurtarma süreci gerekir.

OBS/SSO/LDAP/SMTP/SMS/EBYS-e-imza/scanner kurum sözleşmesi ve yapılandırması olmadan etkin sayılmaz. app/Integrations adaptörleri servis sağlayıcısında bağlanır. OBS not gönderme varsayılanı 503 verir. Hatalı CSV/XLSX onaylanmaz, import hesapları pasif başlar. DOCUMENT_SCANNER ClamAV uyumlu araç yoludur; yoksa karantina sürer.

## Çalışanlar ve izleme

Kuyruk `php artisan queue:work --timeout=150 --tries=3`; zamanlayıcı `php artisan schedule:work` veya dakikada bir schedule:run. Kuyruk retry süresi 180 saniye; Python CLI sınırı 135 saniye. Windows başlatıcısı özel ini için PHPRC/--no-reload aktarır.

mue:outbox dakikalık portal olaylarını; mue:maintenance saatlik eğitim/takvim/saklama uyarılarını işler. Eksik haftalık rapor/izleme, iki işyeri denetimi, plan/dosya, başvuru/tercih, protokol/nakil/olay/mali bildirim/risk/eylem terminleri izlenir. Outbox işleme dış e-posta/SMS/EBYS teslimi kanıtı değildir.

`php artisan mue:health`: DB erişimi, failed_jobs, 100'ü aşan kuyruk, karantina sayısı, 1 GiB altı disk ve etkin yedeğin 24 saat güncelliği. Alarm varsa yapılandırılmış log warning ve **exit 1**; saatlik schedule. JSON/çıkış kodunu kurum merkezi alarmına bağlayın; yerel uygulama merkezi alarm teslimi yapmış sayılmaz. Request ID/arındırılmış hata korunur; log single/stderr/syslog yapılandırılabilir.

Python sabit JSON önerisi üretir; Laravel sert kısıtları tekrar denetler. Amaç aşamaları önceki optimumu korur; FEASIBLE/OPTIMAL ayrıdır. Hata/süre aşımı önceki kurum kararını değiştirmez.

## Yedek ve restore

Varsayılan otomatik yedek **kapalıdır**. .env:

```dotenv
BACKUP_ENABLED=true
BACKUP_PG_DUMP=pg_dump
BACKUP_DIRECTORY=/secure/mue-backups
BACKUP_TIMEOUT_SECONDS=600
```

Windows'ta PostgreSQL 18 pg_dump.exe mutlak yolu kullanılabilir. Araç sunucu sürümünü desteklemelidir. Boş BACKUP_DIRECTORY için storage/app/backups kullanılır; özel belge deposunun dışında, webden erişilemeyen, kısıtlı ve kalıcı dizin gerekir. `php artisan mue:backup` manuel; scheduler etkinse günlük **02:00 UTC**.

Başarılı yedek benzersiz dizinde database.dump (custom), private.zip ve manifest.json üretir. Manifest DB/arşiv/her özel dosyanın SHA256'sını içerir. DB dökümünden sonra özel dosyalar alınır; başarısızlık başarı manifesti üretmez. APP_KEY dahil edilmez; ayrı güvenli anahtar yedeği gerekir. Otomatik yedek silme yoktur; kapasite/saklama politikasını kurum belirler.

Gerçek pg_dump/pg_restore ile **yalnız sentetik mue_test verisinden ayrı geçici DB'ye** restore geçti: migration/yerleştirme/audit/sürüm/belge/görev satırları, FK ve dosya hash'leri. Bu üretim restore tatbikatı veya ölçülmüş RPO/RTO değildir. Hedef RPO 24 saat/RTO 8 saat.

Geri dönüşte yazmalar/worker'lar durdurulur; tamamlanmış manifest/hash doğrulanır, dump ayrı boş test DB'sine pg_restore --no-owner --no-acl ile alınır, özel zip ayrı özel depoya açılır. Satır/FK/audit/sürüm/belge ilişkileri ve uygulama sürümü birlikte doğrulanır; kurum onayıyla bağlantı değiştirilir. Üretim geri dönüşü kayıt silen migrate:rollback ile yapılmaz. Yedek/sırlar Git/Docker build'den dışlanır.

## Saklama ve gizlilik

İş kayıtları/belgeler en az beş yıllık metadata taşır. Kaydedilmiş graduated_on mezuniyetinden sonra bakım işlemi öğrenciye bağlı kayıt/belgelerde beş yıllık süreyi uzatır. Açık itiraz legal_hold uygular; aynı hedefte başka açık itiraz varsa kaldırılmaz. API fiziksel silmez; otomatik imha yoktur. Kurum başlangıç olaylarını/yetkili imhayı kaydeder. Eğitimden sonra beş yıllık gizlilik taahhüdü ayrıca korunur.

Audit/sürümler DB trigger'larıyla eklemelidir; normal kullanıcı değiştiremez/silemez. Gerekçe iş kaydında, audit'te özeti tutulur. Öğrenci/işletme/atanmış danışman kapsamı indirme/export için de geçerlidir. Sağlık/disiplin genel işletme/teknik yöneticiye açılmaz. DB yöneticiliği ayrı altyapı yetkisidir.

## Docker ve devreye alma

compose.yaml PostgreSQL 18, app/queue/scheduler ve kalıcı database/private_documents/backups volume'larını tanımlar. Dockerfile Python 3.12, Node 24 frontend ve PostgreSQL 18 yedek araçları içerir. Host .env/APP_KEY hazırlandıktan sonra:

```sh
docker compose --env-file backend/.env build
docker compose --env-file backend/.env up -d db
docker compose --env-file backend/.env run --rm app php artisan migrate --force
docker compose --env-file backend/.env up -d app queue scheduler
```

Docker mevcut değildi; **gerçek imaj/Compose başlangıcı doğrulanmadı**. CLI HTTP pilot içindir; üretim HTTPS/servis yönetimini kurum kurmalıdır. Uzak CI çalıştırılmadı.

Takvim, geçme/harf/mali politika, görev/kurul/karar belgeleri, gerçek scanner/entegrasyon erişimi, üretim MFA/merkezi alarm/yedek scheduler kurumsal girdilerdir. [TEST_SONUCLARI.md](TEST_SONUCLARI.md) yerel sonucu içerir; kurum UAT'si/üretim eşzamanlı yük onayı ayrıca gerekir.
