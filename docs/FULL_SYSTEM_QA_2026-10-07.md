# MUE son sistem doğrulaması — 7 Ekim 2026

Son karar: **Local/demo kapsamındaki sistem doğrulaması başarılı.**

Çalışma yalnızca `D:\MUE` ve bu projenin localhost/test veritabanlarında yapıldı. Üretim verisi kullanılmadı. `D:\MUE-Personal` ve kişisel imzalı arşiv üzerinde işlem yapılmadı. Bu rapor gerçek kurum bağlantılarının veya bütün olası kullanıcı/veri kombinasyonlarının doğrulandığı anlamına gelmez.

## Son test sonuçları

| Kontrol | Sonuç | Yerel kanıt |
|---|---|---|
| PHP tam regresyon | 48 test, 1.265 assertion; 0 failure/error/skipped; uyarı/deprecation bildirilmedi | `backend/storage/logs/full-qa-20261007/final-php.txt`, `final-junit.xml` |
| Python optimizer | 9 test, tamamı geçti | `final-python.txt` |
| Gerçek HTTP rol/kayıt taraması | 2.150 kontrol, 2.150 başarılı | `http-results.json`, `http-final.txt` |
| Gerçek HTTP güvenlik | 35 kontrol, tamamı ana 8088 portunda geçti | `http-security.json`, `http-security-main-final.txt` |
| Son localhost smoke | Sekiz rol, 88 kontrol, tamamı geçti | `final-smoke.json` |
| Gerçek HTTP yaşam döngüsü | 1 senaryo, 656 assertion; son tekrar geçti | `lifecycle-final.txt`, `lifecycle-final-junit.xml`, `lifecycle-http.jsonl` |
| Production Vite build | Geçti | `final-vite.txt` |
| PHP/Python syntax | Geçti; 95 PHP dosyası lint, Python compile/py_compile | `final-syntax.json` |
| Blade view cache | Geçti | `final-blade.txt` |
| Config cache | Oluşturuldu, local ortam doğrulandı, testlerin ayrı DB ayarlarını korumak için temizlendi | `final-config-cache.txt` |
| Migration | Yeni boş DB'ye 10 migration başarıyla uygulandı; son turda bekleyen migration yok | `clean-migration.txt`, `final-migrations.txt`, `final-migrate.txt` |
| OpenAPI | 277 path, 386 operation, 67 kaynak, 2.847 `$ref`; yapısal karşılaştırma geçti | `openapi-check.json`, `docs/openapi.json` |
| Yetkili dosya export | Müdür ile gerçek CSV/XLSX/PDF indirme, MIME ve dosya yapısı geçti | `export-formats.json` |
| Scheduler | Gerçek `schedule:run`, outbox komutu başarılı | `final-schedule.txt` |
| Queue kesintisi | İşçi kapalıyken 202 / kuyrukta / 1 kalıcı iş; işçi çalıştırılınca tamamlandı / 0 bekleyen iş | `queue-outage.json`, `queue-recovery.txt` |
| Son sağlık kontrolü | failed_jobs=0, queue_backlog=0, alarms=[] | `final-health.txt` |

Kanıt dosyalarının tabloda kısaltılan adları `backend/storage/logs/full-qa-20261007/` altındadır. Başlangıç ve hata öncesi çıktılar da bu klasörde tutuldu; nihai sonuçlar `final-*` dosyalarıdır. Assertion sayısı, performans ve restore testlerindeki dinamik kontrol sayısına bağlı değişebilir; burada son JUnit toplamı kullanılmıştır.

## Ortam

PHP 8.2.12, Composer 2.10.3, Laravel 12.69.3, PostgreSQL 18, Python 3.12.14, OR-Tools 9.15.6755, Node 24.18.0, npm 12.0.1, Vite 7.3.6 doğrulandı. Gerekli PHP eklentileri ve Composer platform gereksinimleri geçti. Özel PHP yapılandırması `tools/php.ini` ile kullanıldı.

Ana uygulama `http://127.0.0.1:8088`, local PostgreSQL `127.0.0.1:55432`, sentetik demo DB `mue`, PHPUnit DB `mue_test` idi. Yaşam döngüsü için ayrı `mue_qa_lifecycle_20261007` DB ve 8092 portu kullanıldı. Sorun ayırma için açılan 8094 sunucusu da yalnızca localhost idi. Geçici QA sunucuları/işçisi ve yaşam döngüsü DB'si çalışma sonunda kaldırıldı; ana localhost uygulaması çalışıyor.

`APP_DEBUG=false`, özel depolama, yazma/okuma, hash, session ve database queue doğrulandı. Demo kimlik bilgileri seeder tarafından özel dosyaya yazılmıştır; parola/token bu rapora alınmadı. `start-local.ps1` gerçek başlatma ve tekrar çağırma ile denendi.

## Rol ve portal kapsamı

Sekiz rolün tamamında gerçek tarayıcı girişi ve çıkışı denendi: öğrenci, akademik danışman, işletme yetkilisi, eğitici, müdür, MUE komisyonu, bölüm komisyonu ve sistem yöneticisi. MFA tanımlanmamış local demo hesapları OTP olmadan giriş yaptı.

HTTP taraması her rolün izin verilen kaynaklarında sayfa, liste, mevcut kayıtların detay/geçmiş sayfaları, filtre ve mevcutsa cursor pagination işlemlerini kontrol etti. Export yetkisi olan roller için CSV, öğrenci/danışman gibi yetkisiz roller için reddetme ayrıca denendi. Müdür rolünde gerçek CSV, Excel ve PDF indirmeleri de MIME ve dosya yapısıyla doğrulandı; PDF'nin bütün sayfaları için ayrıca görsel baskı tasarımı denetimi yapılmadı.

| Rol | HTTP taramasındaki kaynak sayısı |
|---|---:|
| Öğrenci | 55 |
| Akademik danışman | 53 |
| İşletme yetkilisi | 55 |
| Eğitici | 52 |
| Müdür | 66 |
| MUE komisyonu | 65 |
| Bölüm komisyonu | 62 |
| Sistem yöneticisi | 5 |

Tarayıcıda menülerden elde edilen 72 farklı URL ziyaret edildi: öğrenciye açık bütün menüler ve yönetim/teknik rollere özel kalan ekranlar. Bunlar başvuru/tercih/yerleştirme, eğitim/izleme, değerlendirme, işletme/İSG, komisyon, mali işlemler/kalite, belgeler, bildirimler, akıllı eşleştirme, rapor, aktarım, EK merkezi ve hesap yönetimini kapsadı. Tarayıcı çıktıları `browser-ui.json` içindedir. Bütün ekranlarda her rol ile bütün CRUD kombinasyonları elle yapılmış değildir; geniş rol matrisi gerçek HTTP ile, işlem değişiklikleri yaşam döngüsü ve regression testleriyle tamamlandı.

EK-1–EK-20 kataloğu ve rol bazlı dijital karşılıkları incelendi. Gerekli alanlar typed kaynak şemasından ve ilgili iş akışlarından doğrulandı. Yaşam döngüsü ve compliance testleri form oluşturma/güncelleme, belge şartı, sürüm ve karar geçişlerini kapsar; her EK'nin bütün opsiyonel varyantları ayrı manuel senaryo olarak tüketilmedi.

Tarayıcıda yanlış parola, logout, filtre/boş liste, mobil sidebar, modal, yerleştirme dropdown etiketi ve sentetik haftalık rapor oluşturma/detay akışı denendi. Kaydedilen üçüncü hafta raporu demo DB'de tutuldu. 1920×1080, 1366×768 ve 390×844 görünümleri kontrol edildi. Son mobil tabloda document genişliği 375, viewport 390 idi; geniş tablo kendi kaydırma alanında kalıyor. Modal genişliği yaklaşık 367 piksel ve yerleştirme etiketi öğrenci adı/numarası/tarih içeriyor. İncelenen tarayıcı konsolunda JS error/warn kaydı yoktu. Bazı ilk yüklemeler paralel QA sırasında bekleme sınırını aştı; yeniden kontrol edildi ve ekranlar açıldı.

Görsel kanıtlar `docs/ui/full-qa-20261007/` altındadır: `student-mobile-report.png`, `student-mobile-table.png`, `student-mobile-form.png`, `director-role-names.png`, `technical-desktop.png` ve `student-preferences-mobile.png`.

## Gerçek öğrenci yaşam döngüsü

Mevcut kapsamlı senaryo, ayrı DB üzerinde commit edilen sentetik veriler ve gerçek HTTP istekleriyle iki kez çalıştırıldı. Son tekrar 656 assertion ile geçti. Ayrı paydaş hesapları kullanıldı; Python CLI, OR-Tools ve database queue gerçekten çalıştı.

Kapsam: öğrenci ve akademik kayıt; işletme/şube/eğitici/protokol/uygunluk/offer; başvuru ve uygunluk; sıralı tercih ve sürümü; ulaşım doğrulaması; görüşme; onaylı politika ve immutable snapshot; eşleştirme; bağımsız sonuç kontrolü; komisyon/müdürlük/ilan; yerleştirme ve başlama koşulları; öğrenme planı ve haftalık görev; 75 günlük devam; 15 haftalık rapor, eğitici ve danışman onayı; haftalık izleme ve iki işyeri denetimi; portfolyo, öz değerlendirme, eğitim dosyası, sunum ve rubrik; başarı, ilan, itiraz ve son durum geçişleri. İşyeri değişikliğinin izleme kayıtlarını koruması ayrıca `SourceComplianceTest` ile doğrulandı.

Resmî onay ve temiz tarama gerektiren bu sentetik senaryoda belge metadata fixture'ları kullanıldı. Bunlar gerçek antivirüs, EBYS veya e-imza sonucu değildir. Demo belgeleri bu sebeple sahte biçimde temiz yapılmadı.

## Eşleştirme, başarı, bütünlük ve performans

Laravel → database queue → immutable snapshot → Python CLI/OR-Tools → JSON → Laravel hard constraint validation → transaction akışı gerçek HTTP senaryosunda geçti. Python'un DB yazması gerekmedi. Testler %75 tercih/%25 ulaşım, yerleşemeyen sayısı önceliği, toplam skor, normalize GNO tie-break, seed/determinism, kapasite/şube/eğitici/sabit atama, uygunluk, eksik ulaşım ve yetersiz kontenjanı kapsadı. Bozuk optimizer çıktısı reddedildi; Python çalıştırılamadığında run başarısız oldu ve kısmi candidate/match satırı kalmadı. İki öğrencinin son kontenjan için yarışması ve çift aktif yerleştirme gerçek paralel transaction testleriyle engellendi.

2.000 öğrenci, 500 işyeri, 8.000 aday kenarı denemesinde 2.000 öğrenci yerleşti: `FEASIBLE`, 88,5 saniye, `proven_optimal=false`. Tam optimum ispatı yoktur.

Başarı hesabında EK-3 %40, EK-4 %30, dosya/portfolyo %20 ve sunum %10 doğrulandı. Ayrı alan ağırlıkları, 80 örneği, 60 geçer sınırı, 59,99 başarısızlık, eksik alanın reddi ve onaysız/gecikmiş dosya bileşeninin sıfır puan ve gerekçe üretmesi test edildi.

Yeni şemada 572 foreign key, 133 check, 41 unique, 80 primary key, 1 exclusion constraint, 795 NOT NULL sütunu ve 525 index görüldü. İki HTTP yaşam döngüsünden sonra 2 yerleştirme, 150 devam, 30 haftalık rapor, 30 denetim, 2.289 audit ve revision kaydı ölçüldü. Bunlar bütün kayıtların semantik doğruluğunu tek başına kanıtlayan sayılar değildir; geçiş/assertion ve constraint testleriyle birlikte değerlendirilmiştir.

Son liste testi 2.001 öğrenci ve 20 ardışık in-process API isteğinde p95 ≈ 0,054 saniye ve testin query sınırının altında kaldı. Bu ölçüm 100 eşzamanlı üretim oturumu veya tarayıcı açılış süresi değildir. Genel yetki filtresinin bütün kaynak/rollerde büyük hacim ölçeklenmesi ayrıca üretim yük testi gerektirir.

## Dosya ve güvenlik

PDF, JPEG, PNG ve gerçek DOCX paket yapısı ile kabul, yanlış extension/MIME, boş dosya ve >20 MB reddi test edildi. Ana localhostta gerçek multipart PNG ve 21 MB PDF gönderildi; büyük dosya kontrollü 422 ile reddedildi. Dosya hashleri, özel object key'in response'dan çıkarılması, ilişkili kayda scope, yabancı belge/öğrenci için IDOR, gizli dosya, retention/legal hold metadata ve karantina download reddi incelendi.

Liste, detay, history, export ve download sınırları ayrı kontrollerle denendi. Akademik/IBAN alanlarının gereksiz rollere çıkarılmaması doğrulandı. Sistem yöneticisinin akademik karar yetkisi olmadığı kontrol edildi. Yanlış/missing/foreign assignment, URL/API scope aşımı, CSRF 419, stale version, idempotency, session yenileme/logout, bearer iptali ve rate limit kontrol edildi. MFA için eksik, yanlış, süresi geçmiş, doğru ve tekrar kullanılan TOTP gerçek HTTP ile denendi.

Scanner yapılandırılmadığı için yüklenen sentetik dosyalar karantinada kaldı. Bu beklenen durumun oluşturduğu beş QA `ScanDocument` failed-job kaydı kanıt olarak arşivlendi ve yalnızca bu testlere ait kuyruk hata kayıtları temizlendi. Belgeler temiz işaretlenmedi ve silinmedi. Son sağlıkta yedi karantina belgesi, sıfır failed-job/backlog ve alarm yoktu. Sentetik MFA hesabı ve teknik görevi test sonunda pasifleştirildi.

## Bulunan ve düzeltilen sorunlar

| Sorun / sebep | Düzeltme | Regression kanıtı |
|---|---|---|
| History bazı JSONB alanlarında 500: PostgreSQL snapshot içinde gömülü array/object, Eloquent raw attribute JSON string bekliyordu | JSONB ham alanları yeniden serialize ederek tarihsel model hydrate edildi; şifreli alan ciphertext'i korundu | History/encryption/redaction regression + bütün rol HTTP history turu |
| Bozuk UUID, cursor ve görev header'ı PostgreSQL'e ulaşarak 500 veriyordu | Filter/reference/header/route UUID ve limit validation; kontrollü 422/403/404 | Malformed input regression + gerçek HTTP |
| Hesap yollarında metin/taşan sayısal ID controller type hatası verebiliyordu; metin filtrelerinde array doğrulaması eksikti | Pozitif integer account ID kontrolü ve q/state/form_code için string validation | Hata öncesi account-ID regression; son tam suite ve gerçek HTTP'de 4 account / 3 filter kontrolü |
| IBAN yalnızca biçimle kontrol ediliyor, yanlış checksum kabul ediliyordu | Türkiye IBAN MOD-97 doğrulaması; üç typed alan ve profil onayı | Geçerli/geçersiz checksum regression + gerçek HTTP 422 |
| DB bağlantı hatası genel 500'e düşüyordu | Bağlantı SQLSTATE'leri için Türkçe 503, HTML/JSON, özel ayrıntı sızdırmama | Enjekte edilmiş bağlantı hatası regression |
| Görev listesinde kullanıcı adı yerine ID görünüyordu: mevcut olmayan `resources/users/{id}` kullanılıyordu | Mevcut kapsamlı `reference-users` servisi ve sayfa içi cache kullanıldı | Tarayıcıda adlar; farklı kurumun kullanıcısının sızmaması regression |
| Mobil tablo sayfayı yatay büyütüyordu: absolute erişilebilirlik etiketi tablo kaydırıcısına bağlı değildi | `.table-responsive` için positioning context | 390 piksel tarayıcı ölçümü ve ekran görüntüsü |
| `start-local.ps1` tekrarında aynı proje için ek worker/scheduler/server açılıyordu | Bu proje PHP ini yoluna göre mevcut süreç kontrolü | Gerçek başlatma/tekrar çağırma |
| OpenAPI partial PUT zorunlu alanları, error field biçimi, query/report parametreleri, appeal update ve export response MIME'leri eksikti/uyumsuzdu | Update şeması, object hata alanı, filter/report/pozitif account ID parametreleri, export dosya response'ları ve auth 429; yeniden üretim | 386 operation karşılaştırması, ref/parametre/operationId kontrolü + API testleri ve gerçek export |
| Büyük dosyada boyut hatasından sonra ek MIME doğrulaması sürüyordu | Dosya validation'ına `bail` | Boyut regression + yeniden başlatılmış ana 8088'de gerçek 21 MB yükleme |

Önceki yerel web sürecinde büyük yükleme isteği yanıt alamadı. Aynı uygulama ayrı PHP sunucusunda ve proje betiğiyle yeniden açılan ana 8088 portunda başarılı biçimde reddetti. Önceki süreçteki timeout'un tek kök nedeni kesinleştirilmiş sayılmıyor; son ana servis kontrolü geçti.

## Backup / restore

`BackupRestoreTest`, gerçek `pg_dump` ve `pg_restore` ile ayrı geçici DB oluşturdu. Migration, öğrenci, başvuru, offer, placement, audit, revision, belge ve görev tablolarında satır hashleri; indekslerin yapısal özellikleri; DB ilişkileri ve restore edilen özel dosyaların SHA-256 hashleri karşılaştırıldı. Geçici restore DB'leri temizlendi. Üretim backup kullanılmadı.

Günlük otomatik backup bu local ortamda yapılandırılmış/etkin değildir (`backup_enabled=false`). Restore kabiliyeti ile gerçek üretim backup operasyonu birbirinden ayrıdır.

## Açık sınırlar ve dış bağımlılıklar

Doğrulanan local/demo kapsamında açık kritik hata bulunmadı. OBS, SSO/LDAP, SMTP/SMS, EBYS, e-imza, gerçek antivirüs ve merkezi alarm/monitoring bağlantıları doğrulanmadı. Unavailable integration sınırları ve yapılandırılmamış SMTP için açık 503/failure davranışı doğrulandı; gerçek gönderim veya resmî imza yoktur.

Fiziksel PostgreSQL servisi kapatılmadı. Ayrı bağlantı-hata simülasyonu sunucusu başlatma komutu otomatik onay denetimince `blocked by policy` gerekçesiyle reddedildi. Bağlantı SQLSTATE hatası, mevcut test ortamında enjekte edilerek HTML/JSON 503 ve ayrıntı sızdırmama doğrulandı. İşçi kapatma testi ise ayrı QA işçisi üzerinde fiziksel olarak yapıldı.

Üretim HTTPS/secure-cookie, gerçek kurumsal MFA enrollment operasyonu, eşzamanlı yüksek kullanıcı yükü, uzun süreli scheduler/backup/monitoring işletimi, kötü amaçlı dosyanın gerçek AV sonucu ve her formun bütün isteğe bağlı varyantları bu raporun doğrulama kapsamı dışındadır. OpenAPI kontrolü yapısal ve uygulama karşılaştırmasıdır; bütün koşullu domain response'ları için bağımsız kapsamlı OpenAPI runtime validator çalıştırıldığı iddia edilmez.

## UX notları

İlçe alanları halen serbest metindir; il/ilçe ilişkili seçim yararlı olacaktır. Ulaşım beyanı Evet/Hayır ve açık dakika etiketi içerir; varsayılan sıfır yerine kullanıcıdan açık süre girişi istemek ve ulaşım türü seçenekleri sunmak değerlendirilebilir. Reference dropdown/search alanları mevcut; büyük kullanıcı listelerinde pagination ve exact lookup iyileştirilebilir.

Offer/placement etiketi işletme/şube veya öğrenci adı içerir; kapalı/quarantine demo koşullarında uygun offer bulunmaması yanlış başarı olarak gösterilmez. Offer seçiminde kapasite/eğitici ve temel uygunluk bilgisinin etikete eklenmesi yararlı olur. Evet/Hayır ve sayısal değerlerin alan etiketleri mevcut; başlıksız `0 / Evet` metni incelenen örneklerde görülmedi. Bazı teknik kayıt başlıkları kısa kayıt referansı kullanır; daha açıklayıcı başlıklar ve referans çözümünde yükleme süresi iyileştirmeleri önerilir.

## Teslim durumu

Değişiklikler çalışma ağacında bırakıldı; commit veya dış yayın yapılmadı. Ana localhost açık, geçici QA altyapısı temizlendi, sentetik test kanıtları ve regresyon araçları korundu. Tekrar çalıştırma yönergeleri `tools/qa/README.md` içindedir.
