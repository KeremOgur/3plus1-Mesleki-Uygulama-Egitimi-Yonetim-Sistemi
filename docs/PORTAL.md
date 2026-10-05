# Türkçe MUE portalı

Uygulama: `D:\MUE\backend`. Giriş: <http://127.0.0.1:8088/giris>. `D:\MUE\start-local.ps1` PostgreSQL, kuyruk, zamanlayıcı ve yerel web sunucusunu başlatır. Mevcut backend/portal korundu; dört özgün kaynak yeniden incelenerek gerekli düzeltmeler tamamlandı. Güncel [izlenebilirlik](requirements-traceability.md) ve [test sonuçları](TEST_SONUCLARI.md) nihai durumu belirtir.

## Ekranlar ve erişim

| Portal | Ana ekranlar |
|---|---|
| Öğrenci | Akademik bilgiler, uygunluk, başvuru, işletme bilgileri, sıralı tercih ve sürümler, görüşme, ilan edilmiş sonuç ve itiraz, öğrenme planı, devam, haftalık rapor/kazanım ilişkisi, geri bildirim, portfolyo, öz değerlendirme, taahhütname, belge, bildirim ve takvim |
| Akademik danışman | Atanmış öğrenciler/eğitimler, öğrenme planı, EK-4 ve EK-13, devam ve rapor incelemesi, düzeltme isteği, kazanım/portfolyo, değerlendirme ve dönem izleme raporu |
| İşletme yetkilisi | Kendi işletmesi ve şubeleri, eğitici nitelikleri, protokol/uygunluk, teklif/kontenjan, asgari aday bilgileriyle görüşme ve gerekçeli ret, atanmış eğitimler, ilgili belgeler |
| Eğitici personel | Aynı işletme portalında ayrı kişi/görev hesabı; atanmış eğitimler, öğrenme planı, devam gönderimi, haftalık rapor incelemesi, EK-3, izinli olay ve belge işlemleri |
| MYO | Müdür/MUE Başkanı, MUE Komisyonu, Bölüm Komisyonu/Program Başkanı, Koordinatör ve belge görevlerinin kapsamına göre kurum/takvim, aktarım, görevlendirme, işletme, eşleştirme, komisyon kararları, ilan, itiraz/değişiklik, eğitim, mali işlemler ve kalite |
| Teknik hesap yönetimi | Hesap oluşturma/düzenleme/pasife alma ve teknik görevler. Akademik karar yetkisi yoktur. |

`/panel/{kaynak}` listeleri Türkçe arama, durum ve ilişki filtreleri, yetki uygulanmış sayım ve imleçli sayfalama sunar. `/panel/{kaynak}/{id}` ayrıntıları, görev/durum bazlı işlemleri, karar/sürüm bilgilerini ve ilgili kayıt bağlantılarını gösterir. Yazdırma stili gezinmeyi kaldırır. Yetkili kurumsal roller aynı filtrelerle CSV/XLSX/PDF dışa aktarır. Kişisel hesap parolaları ve özel depolama yolları ekrana taşınmaz.

Yazma yetkisi olup toplu okuma yetkisi bulunmayan geri bildirim görevleri ayrı gönderim ekranı kullanır. Öğrencilere tercih politikası için yalnız kendi başvurularının onaylı ağırlık, sürüm, ulaşım tablosu ve tercih sınırı gösterilir; komisyon belgesi veya diğer öğrencilerin verileri açılmaz.

## EK-1–20 işlem haritası

| Form | Dijital arayüz ve kayıt türü |
|---|---|
| EK-1 | Başvuru `applications`, sıralama/kesinleştirme `preferences`, ilan/itiraz `placements` |
| EK-2 | Devam işaretleri, gündüz eğitim süresi, eğitici gönderimi ve danışman incelemesi `attendance` |
| EK-3 | Kazanım, onaylı rubrik, değerlendirme kaynağı ve alan bazlı ölçüt puanları `rubric_evaluations`; hesaplanan başarı `success_results` |
| EK-4 | Altı ölçüt, yüz yüze/izinli çevrim içi yöntem, bulgular, eylemler ve belge `inspections` |
| EK-5 | İşletme, şube, eğitici, altı zorunlu uygunluk ölçütü ve inceleme açıklamaları `company_assessments` |
| EK-6 | Uygunsuzluk/devamsızlık bildirimi, inceleme, komisyon kararı ve yetkili yaptırım metadata'sı `nonconformities` |
| EK-7 | Olay, kazazede kapsamı, tanıklar, bildirim/inceleme tarihleri ve belgeler `incidents` |
| EK-8 | Sürümü gösterilen disiplin/etik/İSG metni, kabul ve imzalı belge `declarations`; İSG kayıtları `ohs_records` |
| EK-9 | Haftalık faaliyet/bilgi-beceri/sorun-çözüm, kazanım bağlantıları, eğitici/danışman görüşü, iade ve yeni sürüm `weekly_reports`, `report_outcomes` |
| EK-10 | Sürümü gösterilen gizlilik/veri/fikrî mülkiyet metni ve imzalı belge `declarations` |
| EK-11 | Beş sunum ölçütü, sunum tarihi, belgeler ve değerlendirme `presentations` |
| EK-12 | Aylık puantaj/ödeme/banka kanıtı; hesaplanan asgari ödeme, devlet katkısı ve işveren payı `payroll` |
| EK-13 | Çevrim içi görüşme, katılımcılar, bulgular ve kayıt `online_records`; belgeli Bölüm önerisi ardından ayrı MUE Komisyonu izni |
| EK-14 | Öğrenci/işletme için beş açık ifadeli 1–5 ölçeği ve görüşler `feedback` |
| EK-15 | Puantaj ilişkisi, dönem/ay ve katkı talebi `fund_contributions` |
| EK-16 | Plan/sürüm/imzalar `learning_plans`, kazanım/faaliyet/en az iki kanıt/hafta `plan_outcomes`, 15 haftalık görev ve risk bağlantısı `weekly_plan_tasks` |
| EK-17 | Kazanım düzeyi, on yetkinlik, beş yansıtma sorusu, on üç portfolyo kontrolü `self_assessments`; belge ve izinli kanıt `portfolio_evidence` |
| EK-18 | Yıllık istatistik/paydaş/kazanım/denetim/İSG/kök neden ve önceki eylem tabloları `annual_reports`; sekiz ağırlıklı ölçüt `company_performance`; eylemler `improvement_actions` |
| EK-19 | Öğrenim, deneyim, işyeri/görev, sertifikalar, yeterlilik/İSG kanıtları `trainer_qualifications` |
| EK-20 | Risk ekibi, on altı tehlike kontrolü, altı olağanüstü durum senaryosu, acil iletişim ve yerler `risk_plans`; olasılık/şiddet/tedbir/gözetim `risk_items` |

EK merkezi yirmi formu birlikte gösterir; kullanıcının yetkisi dışındaki süreçler açıklayıcı kapalı durumdadır. Liste, ayrıntı ve tipli formlar JSON metni girilmesini istemez; koleksiyonlar etiketli satır veya sabit ölçüt olarak doldurulur. Ek bilgi backend tarafından desteklenmiyorsa yeni bir iş kuralı veya alan uydurulmadı.

## Eşleştirme ve karar

Yeni politikanın varsayılanı backend ve formda **0,75 tercih / 0,25 ulaşım**. Ekran onaylı politika/sürümün gerçek ağırlıklarını da gösterir. Eşleştirme çalışmasının kuyruğu, solver durumu, aday uygunluğu, tercih ve ulaşım bileşenleri, puanı, yerleşen/yerleşemeyen önerisi ve gerekçeleri görüntülenir. Sonuçlar güncelleme düğmesiyle alınır; çalışma bitmeden sonuç üretilmiş gibi gösterilmez.

Öneriyi Bölüm Komisyonu incelemeye açar. Manuel teklif değişikliği gerekçeyle kaydedilir. Komisyon işlemlerinde karar numarası/tarihi, toplantı, üye/katılım/oy sayıları, eşitlikte başkan oyu, sonuç, gerekçe ve temiz taranmış belge birlikte girilir. Nihai MUE kararı ve Müdür ilanı ayrı işlemlerdir. Kapasite ve tüm zorunlu uygunluk kontrolleri sunucudadır.

## Oturum ve belge güvenliği

Blade/Bootstrap 5.3.8, yerel Bootstrap Icons ve hafif modüler JavaScript kullanılır. SPA, CDN bağımlılığı veya tarayıcıda bearer token yoktur. Web oturumu ve CSRF altında `/portal-api/v1` aynı backend controller/service yöntemlerini çalıştırır. Aktif görev sunucu oturumundan alınır; istemcinin `X-Assignment-Id` başlığı bunu değiştiremez. Durum değişiklikleri sürüm ve idempotency anahtarlarını korur. Oturum açma/görev seçimi kimliği yeniler, çıkış oturumu geçersiz kılar. Pasif, kaldırılmış veya üretimdeki demo görevi erişemez.

Belge yükleme hedef kaydın yetkisini kontrol eder; dosya özel depoda karantinaya girer. Tarama tamamlanmamış belge temiz olarak etiketlenmez, indirilemez, seçilemez ve onay için kullanılamaz. İndirme yetki/tarama/bütünlük kontrolüyle yapılır; depolama URL'si yayınlanmaz. Öğrencinin başka öğrenci verisine erişimi ve işletmenin GNO/özel akademik veri erişimi mevcut Gate kapsamlarıyla reddedilir.

## Geliştirme demosu

```powershell
cd D:\MUE\backend
C:\xampp\php\php.exe -c D:\MUE\tools\php.ini artisan mue:demo
```

Komut yalnız `local/testing` ortamında çalışır ve mevcut demoyu tekrar oluşturmaz. Ayrı `DEMO-MUE` kurumu, sentetik öğrenci/işletme ve sekiz ayrı görev hesabı oluşur. Parola rastgele üretilir ve yalnız `D:\MUE\backend\storage\app\private\demo-credentials.json` dosyasına yazılır. Bu dosya webden sunulmaz ve git dışında tutulur. Mevcut kurumsal veri veya hesaplar değiştirilmez.

Hesaplar: `ogrenci`, `akademik_danisman`, `isletme_yetkilisi`, `egitici`, `mudur`, `mue_komisyon`, `bolum_komisyon`, `sistem_yoneticisi`; e-postalar `rol@demo.mue.invalid` biçimindedir. Hesapların `mue-demo:` işareti üretimde oturum/token erişimini engeller. Üretim kurulumu için gerçek hesapları `mue:bootstrap` ve yetkili hesap/görev işlemleriyle oluşturun.

Demo önceden hazırlanmış sentetik bir ilan edilmiş eğitim kaydı içerir; bu bir resmî karar/ilan veya tarama kanıtı değildir ve ekranda açıkça belirtilir. Protokol, uygunluk, eğitici yeterliliği ve politika taslaktır; örnek belge **bekliyor/karantina** durumundadır. Zararlı içerik taraması ve gerçek imzalı kararlar olmadan bu süreçleri onaylamak/eşleştirme çalıştırmak mümkün değildir.

Temsilî gösterim: öğrenci haftalık rapor oluşturur, kazanım bağlantısını ekler ve gönderir; atanmış eğitici görüşünü kaydedip inceler; atanmış danışman görüşüyle incelemeyi tamamlar. Bu akış tarayıcıda gerçekleştirildi. Kanıtlar: [MYO paneli](ui/portal.jpg), [rapor incelemesi](ui/rapor-incelemesi.jpg), [mobil panel](ui/mobil-panel.jpg), [karantina](ui/karantina.jpg), [eşleştirme](ui/eslestirme.jpg).

## Derleme ve doğrulama

```powershell
cd D:\MUE\backend
& 'C:\Program Files\nodejs\npm.cmd' ci
& 'C:\Program Files\nodejs\npm.cmd' run build
C:\xampp\php\php.exe -c D:\MUE\tools\php.ini vendor\phpunit\phpunit\phpunit --filter=PortalSmokeTest
```

Portal testleri `mue_test` üzerinde transaction'lı sentetik fixture kullanır; dört portal/eğitici girişini, raporu, CSRF/oturum görevini, ağırlık/politika özetini, mahremiyeti, Türkçe CSV'yi, karantinayı, üretimde demo engelini ve yapısal EK alanlarını kontrol eder. Final paket 37 PHP testi/989 assertion ve 9 Python testiyle başarılıdır. Test fixture'ındaki temiz belge metadata'sı gerçek antivirüs taraması değildir.

Vite üretim derlemesi ve Blade kontrolü geçti. Önceki tarayıcı testlerinde dört portal/eğitici ve rapor akışı; finalde öğrenci/EK merkezi/EK-17 ve 390×844 taşma kontrolü incelendi. Kaynak ve rol/regresyon denetimi güncel test raporunda yer alır; 100 eşzamanlı üretim yükü ölçülmedi. Docker/uzak CI çalıştırılmadı.

## Görsel referanslar ve işletim sınırları

Önceki Prompt 2 hedefli alan düzeltmeleri [tarihsel belgede](PROMPT2_KAYNAK_DUZELTMELERI.md) korunur. Finalde EK-3/16/17/19/20 alanları, kurul/çevrim içi izin zinciri, eylem takibi, raporlar ve Türkçe etiketler kaynaklarla karşılaştırılarak düzeltildi. Üretim girişinde kritik görev MFA'sı uygulanır; tipli formlar karar/inceleme için korunan alanları ilgili görev üzerinden sunar.

[Fırat Üniversitesi](https://www.firat.edu.tr/en), [kurumsal kimlik sayfası](https://www.firat.edu.tr/en/page/menu/corporate-identity-6750) ve [OBS'nin kamuya açık giriş sayfası](https://obs.firat.edu.tr/) yalnız görsel referans olarak incelendi. Bordo/altın/pastel gri renk, beyaz kartlar, kurumsal logo ve sade gezinme kullanıldı. İş kuralları bu sitelerden alınmadı; OBS'nin birebir kopyası olduğu iddia edilmez.

Gerçek antivirüs, OBS/SSO/LDAP, SMTP/SMS ve EBYS yapılandırılmamıştır; başarı taklit edilmez. SMTP yoksa parola yenileme Türkçe hata verir. Karar/takvim/mali politika/görevlendirme kurum girdisidir. HTTPS, üretim özel depo/günlük yedek scheduler/merkezi alarm, kurum UAT'si ve eşzamanlı yük onayı ayrıca kurulup doğrulanmalıdır. Yerel nihai denetim tamamlanmıştır.
