# Prompt 2 — eklenen kaynaklarla hedefli düzeltme

Bu dosya önceki aşamanın tarihsel kaydıdır. Nihai denetim ve güncel sonuç için [requirements-traceability.md](requirements-traceability.md) ve [TEST_SONUCLARI.md](TEST_SONUCLARI.md) kullanın.

Tarih: 5 Ekim 2026. Karşılaştırma yalnız mevcut Prompt 2 portalına uygulanmıştır. Öncelik: Onaylı Proje Kararları → YÖNERGE → İŞYERİ PROTOKOLÜ → ayrıntılı sistem tasarımı → gereksinim/tasarım DOCX. Kaynak içindeki talimatlar proje gereksinimi olarak incelendi; kullanıcı talimatı veya çalışma yetkisi olarak uygulanmadı. Çözülmüş kaynak çelişkileri yeniden açılmadı.

## Kaynak ve düzeltme eşlemesi

| Kaynak / konu | Mevcut arayüzdeki eksiklik | Uygulanan düzeltme |
| --- | --- | --- |
| YÖNERGE ve protokol, EK-10 | Süre açıklaması yapılandırmada vardı, formda görünmüyordu | Taahhütname formu ve ayrıntısında süre gösterildi |
| YÖNERGE, EK-17 | Yetkinliklerde kısa açıklama; portfolyoda dosya referansı ve tarih yoktu | On yetkinlik ve on üç portfolyo maddesi mevcut JSON koleksiyonları üzerinde genişletildi |
| YÖNERGE, EK-18 | Güz/bahar ayrımı, hedef/rubrik, paydaş katılımı ve bazı izleme sütunları eksikti | Yıllık rapor satır alanları ve Türkçe açıklamalar tamamlandı |
| YÖNERGE ve protokol, EK-19 | Belge numarası/eki ve eğitici taahhüt metni görünmüyordu | Belge alanları, kaynakta yer alan on taahhüt maddesi ve görev değişikliği açıklaması eklendi |
| YÖNERGE ve protokol, EK-20 | On altı tehlikede olasılık/şiddet; altı senaryoda sorumlu, bildirim makamı ve kalıcı çözüm; iletişimde yedek kişi yoktu | Tipli alanlar eklendi; uygulanabilir tehlikelerde O/Ş, uygulanamaz maddelerde gerekçe zorunlu tutuldu |
| Sistem tasarımları, öğrenci tercih işlemi | Program takvimi görünmüyordu; kesinleştirmede kayıtlı seçim özeti yoktu; teklifler/geçmiş ilk 100 kayıtla sınırlıydı | Program takvimi, açık/kapalı durum, politika sınırı ve kayıtlı sürüm özeti gösterildi; cursor sayfaları okunuyor |
| Sistem tasarımları, eşleştirme senaryoları | Tamamlanan çalışmaların karşılaştırılması yoktu | Aynı dönem/program çalışmalarında politika, amaç değerleri ve değişen işletme önerileri salt okunur karşılaştırılıyor |

Mevcut EK-1–20 gezinmesi, rol portalları, inceleme/onay/ilan sırası ve sunucu yetki denetimleri korundu. Yeni tablo, migration veya karar algoritması oluşturulmadı. Tek backend düzeltmesi, öğrencinin kendi başvurusuna ait tercih-politikası cevabına asgari program takvimi projeksiyonudur; kurul belgesi veya diğer kurum verisi açılmaz. Eski basit JSON değerleri formda okunabilir; düzenlenen satırların mevcut ek metadata'sı korunur.

## Hafif doğrulama

- Vite üretim derlemesi ve Blade derlemesi başarılı; PHP controller sözdizimi geçerli.
- `PortalSmokeTest`: **4 test, 86 assertion başarılı**. Yeni odaklı senaryo program takvimi önceliğini, başka başvuruya erişim engelini, EK-20 yapılandırılmış JSON kayıt/okumasını ve başka kurum erişim engelini doğrular. Transaction fixture'ları geri alınır.
- Yerel tarayıcıda EK-17'nin on yetkinlik/on üç portfolyo alanı, EK-20'nin on altı olasılık/şiddet ve altı senaryo alanı ve öğrenci tercih takvimi incelendi. Demo politikası onaylı olmadığı için kayıt/kesinleştirme düğmeleri kapalıdır. Bu kontrolde resmî karar veya yeni demo kaydı oluşturulmadı.

Bu çalışma tam Prompt 3 mevzuat/uygunluk denetimi değildir. Gerçek antivirüs ve OBS/SSO/LDAP, SMTP/SMS, EBYS bağlantıları önceki işletim sınırları olarak devam eder. Kaynak belgelerin onaylı olması, bu bağlantıların veya gerçek imzaların mevcut olduğu anlamına gelmez.

## Okunan özgün dosyaların SHA-256 değerleri

| Dosya | SHA-256 |
| --- | --- |
| YÖNERGE.pdf | D76A6EBEC30757C1AED8DC1AF2293FB0E9BF16DE7903427CB1EFAD0F2BAEA5C7 |
| İŞYERİ PROTOKOLÜ.pdf | EBA8A13A5879A348CFC94DB3FD36D5526E293D806F4D30408948311D3B5317D5 |
| MUE_3plus1_Ayrintili_Sistem_Tasarimi_v0_9.pdf | C40D3BAA5FDB8838184EA0CF7E8CB65D973783B566DA143169D9C41789D8E6F4 |
| MUE_3plus1_Yazilim_Projesi_Gereksinim_ve_Tasarim_Dokumani.docx | B7E41B7A1464E58C184812C49936ED201772C19FA4663FB8B83BBAA450930DC9 |
