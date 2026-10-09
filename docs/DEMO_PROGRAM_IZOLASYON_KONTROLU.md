# Kalıcı DEMO program izolasyonu kontrolü

Bu veri seti yalnız `D:\MUE` localhost/demo ortamı içindir. Tüm yeni bölüm, program, öğrenci, işletme ve belgeler sentetiktir. Gerçek kurum programı, işletme, imza veya resmî akademik karar temsil etmez.

Mevcut `DEMO-MUE` kurumu ve `DEMO-2026-GUZ` dönemi kullanıldı. Eski demo kayıtları korunur. Core iş kuralları ve server-side program izolasyonu değiştirilmedi.

## Hesaplar ve beklenen görünürlük

| Öğrenci e-posta adresi | Program / bölüm | Görmesi gereken işletmeler | Görmemesi gereken diğer program işletmeleri | Kontrol URL'leri |
| --- | --- | --- | --- | --- |
| ogrenci.bilgisayar@demo.mue.invalid | DEMO Bilgisayar Programcılığı / DEMO Bilişim Bölümü | DEMO Yazılım Teknolojileri A.Ş.; DEMO Veri Sistemleri Ltd. | DEMO Elektrik Otomasyon A.Ş.; DEMO Enerji Sistemleri Ltd.; DEMO Makine Üretim A.Ş.; DEMO CNC Teknolojileri Ltd. | [Giriş](http://127.0.0.1:8088/giris), [İşletme teklifleri](http://127.0.0.1:8088/panel/offers), [Sıralı tercihler](http://127.0.0.1:8088/panel/preferences) |
| ogrenci.elektrik@demo.mue.invalid | DEMO Elektrik Programı / DEMO Elektrik Bölümü | DEMO Elektrik Otomasyon A.Ş.; DEMO Enerji Sistemleri Ltd. | DEMO Yazılım Teknolojileri A.Ş.; DEMO Veri Sistemleri Ltd.; DEMO Makine Üretim A.Ş.; DEMO CNC Teknolojileri Ltd. | [Giriş](http://127.0.0.1:8088/giris), [İşletme teklifleri](http://127.0.0.1:8088/panel/offers), [Sıralı tercihler](http://127.0.0.1:8088/panel/preferences) |
| ogrenci.makine@demo.mue.invalid | DEMO Makine Programı / DEMO Makine Bölümü | DEMO Makine Üretim A.Ş.; DEMO CNC Teknolojileri Ltd. | DEMO Yazılım Teknolojileri A.Ş.; DEMO Veri Sistemleri Ltd.; DEMO Elektrik Otomasyon A.Ş.; DEMO Enerji Sistemleri Ltd. | [Giriş](http://127.0.0.1:8088/giris), [İşletme teklifleri](http://127.0.0.1:8088/panel/offers), [Sıralı tercihler](http://127.0.0.1:8088/panel/preferences) |

Parolalar bu dokümana yazılmaz. Hesapların parolaları **`D:\MUE\backend\storage\app\private\demo-credentials.json`** dosyasındadır. `accounts` listesindeki ilgili e-posta kaydını kullanın. Dosya Git dışında ve özel depodadır; mevcut hesapların parolaları korunur.

## Manuel kontrol adımları

1. Giriş URL'sinde tablodaki öğrenciyle oturum açın. Tek aktif öğrenci görevi otomatik seçilir.
2. `/panel/offers` ekranında yalnız o programın iki teklifini kontrol edin. İki teklifin ayrıntıları açılmalı ve kontenjanları 5 olmalıdır.
3. `/panel/preferences` ekranında uygun başvurunun, açık tercih takviminin ve onaylı politikanın yüklendiğini kontrol edin.
4. **Tercih ekle** düğmesine basın. İşletme teklifi seçicisinde yalnız kendi programının iki işletmesi görünmelidir. Yeni set başlangıçta boş tercih listesiyle bırakılır; kayıt veya kesinleştirme manuel olarak size aittir.
5. İsterseniz iki işletmeyi sıralayın, ulaşım süresini belirtin ve taslak kaydedin. Mevcut iş kurallarına göre en fazla iki tercih yapılabilir; ulaşım doğrulaması ayrı komisyon işlemidir.
6. Oturumu kapatıp diğer iki öğrenciyle aynı adımları tekrarlayın. Diğer programdan bir teklifin doğrudan ayrıntı URL'si de erişimi reddetmelidir.

Program başına bir aktif öğrenci bulunduğundan mevcut `<15 öğrenci` kuralına uygun olarak dönem programları **4. yarıyıl**, gruplama kapalı olarak hazırlanmıştır. Tercih son tarihi kullanılan dönemden gelir; bu kurulumda **19 Ekim 2026 16:02:58 (Europe/Istanbul)**. Son tarihten sonra mevcut iş kuralları tercih kaydını kapatır; seeder takvimi otomatik uzatmaz.

## Seeder ve kısa doğrulama

PowerShell'de:

```powershell
Set-Location D:\MUE\backend
& 'C:\xampp\php\php.exe' -c 'D:\MUE\tools\php.ini' artisan db:seed --class=ProgramIsolationDemoSeeder --no-interaction
& 'C:\xampp\php\php.exe' -c 'D:\MUE\tools\php.ini' 'D:\MUE\tools\verify-program-isolation-demo.php' --check-idempotence
```

`ProgramIsolationDemoSeeder` yalnız mevcut demo giriş mekanizmasının desteklediği `APP_ENV=local/testing` ortamlarında çalışır; `production` ve diğer ortamlar reddedilir. Local/demo uygulama `APP_ENV=local` kullanır. Yeni ortamda açık sentetik dönem varsa kullanılır; yoksa ayrı DEMO dönemi oluşturulur. Mevcut izolasyon seti varsa aynı dönem korunur; tekrar çalıştırma öğrenci, parola, tercih veya kayıt durumlarını sıfırlamaz.

Doğrulayıcı `http://127.0.0.1:8088` üzerinde gerçek API girişi ve portal oturumu kullanır. Her öğrenci için kapsam/uygunluk kayıtlarını, kendi teklif listesini ve ayrıntılarını, yabancı program filtrelerini ve dört yabancı teklif ayrıntısının reddini, işletme listesini, manuel portal URL'lerini ve tercih ekranının gerçek API kaynaklarını kontrol eder. İkinci seeder çalışmasının kimlikleri, sayıları, sürümleri ve credential dosyasını koruduğunu da doğrular. Fixture üretmez, DB temizlemez, tercih kaydetmez ve eşleştirme çalıştırmaz; doğrulama tokenlarını kapatır.

Kalıcı set: **3 bölüm, 3 program, 3 öğrenci, 3 aktif öğrenci görevi, 3 uygun başvuru, 6 işletme, 6 şube, 6 aktif protokol/kapsam, 6 onaylı değerlendirme/eğitici, 6 ilan edilmiş teklif**. Her teklif için `OfferReasons = []`; program başına onaylı politika ve dönem programı vardır. Eğitici ilişkileri için altı ayrı sentetik kullanıcı bulunur; bu hesaplar girişe kapalıdır.

9 Ekim 2026 kurulum kontrolü: **33/33 kalıcı demo doğrulaması geçti** (mevcut kayıt/credential koruma, idempotency, production engeli ve üç öğrenci için API/portal senaryoları). İlgili `ProgramOfferIsolationTest` paketi: **13/13 test, 158 assertion**. Core kod değiştirilmedi. Demo kayıtları localhost DB'de bırakıldı; doğrulama erişim tokenları kapatıldı.
