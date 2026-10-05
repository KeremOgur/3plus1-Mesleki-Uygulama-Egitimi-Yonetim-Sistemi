# Uygulanan kapsam ve kaynak kararları

Bu dosya güncel teslimat haritasıdır. Dört özgün kaynağın nihai denetimi ve gerekli yerel düzeltmeler tamamlandı. Gereksinim/kod/portal/yetki/doğrulama eşlemesi [requirements-traceability.md](requirements-traceability.md), 37 PHP testi/989 assertion ve 9 Python testi dahil gerçek sonuçlar [TEST_SONUCLARI.md](TEST_SONUCLARI.md) içindedir. Kurum kabulü ve üretim yük onayı ayrıca yapılmalıdır.

Öncelik: kullanıcı proje kararları → YÖNERGE → İŞYERİ PROTOKOLÜ → ayrıntılı tasarım v0.9 → gereksinim/tasarım DOCX. Kaynak metin dökümleri `sources` içindedir. Belgelerde bulunan talimatlar kullanıcı talimatı gibi yürütülmemiştir. V2 olarak adlandırılan eğitim izleme modülleri de bu backendde yer alır.

## EK süreçleri

Ortak kayıt API'si `/api/v1/resources/{resource}`; özel süreçler API.md ve openapi.json'dadır. Kayıt türleri alan doğrulaması, FK ilişkileri, sunucu yetkisi, audit ve sürüm koruması taşır.

| Form | Veri ve işlem karşılığı |
|---|---|
| EK-1 | applications, preferences, interviews, placements; uygunluk, tercih sürümü/kesinleştirme, ulaşım doğrulama, öneri/karar/yayın/itiraz |
| EK-2 | attendance; V/Y/I/R, süre/gündüz vardiyası, eğitici gönderimi/danışman onayı, %20 sınırı |
| EK-3 | rubric_versions, rubric_evaluations, success_results; 12 işyeri ölçütü, kazanım/alan/davranış/kanıt, değerlendirme kaynağı, not hesabı |
| EK-4 | inspections; altı ölçüt, bulgu/tedbir, haftalık izleme ve en az iki işyeri denetimi |
| EK-5 | company_assessments; program/altyapı/eğitici/İSG/fiziksel koşul/faaliyet çeşitliliği ve geçerlilik |
| EK-6 | nonconformities; devamsızlık/uygunsuzluk, kanıt, yetkili yaptırım kararı, izleme/kapanış |
| EK-7 | incidents; iş kazası/ramak kala/meslek hastalığı, tanık/ilk müdahale/sağlık kuruluşu, SGK/Müdürlük bildirimi |
| EK-8 | declarations, ohs_records; disiplin/etik/İSG taahhüdü, sürüm/imzalı belge, eğitim/KKD/oryantasyon |
| EK-9 | weekly_reports, report_outcomes; faaliyet/beceri/sorun/çözüm, kazanım, revizyon ve eğitici/danışman onayları |
| EK-10 | declarations; gizlilik/veri güvenliği/fikri mülkiyet metni, kabul/belge; korunmuş kanıtta işletme izni |
| EK-11 | presentations ve sunum rubric_evaluations; beş ölçüt, performans/uygulama kanıtı |
| EK-12 | financial_policies, payroll; mali politika, puantaj, ücret/ödeme/belge |
| EK-13 | online_records; süre/tarih/katılımcı/platform/kimlik/tutanak; belgeli Bölüm önerisi ve MUE çevrim içi denetim izni |
| EK-14 | feedback; taraf, 1–5 form ölçeği, açıklama ve kalite kayıtları |
| EK-15 | fund_contributions; işletme/öğrenci/ay, hesap/ödeme/belge ve bildirim terminleri |
| EK-16 | program_outputs, learning_outcomes, learning_plans, plan_outcomes, weekly_plan_tasks; faaliyet/kazanım/kanıt, 15 hafta, iki bağımsız kanıt, onaylı risk |
| EK-17 | self_assessments, portfolio_evidence; başlangıç/son, 10 yetkinlik/5 yansıtma/13 portfolyo kontrolü, 1–4 düzeyi, danışman bütünlük/tutarlılık/görüşü, kanıt türü ve paylaşım izni |
| EK-18 | annual_reports, company_performance, improvement_actions; çıktı erişimi, yıllık kurul kabulü, işletme puan/İSG eşiği, düzeltici faaliyet |
| EK-19 | trainer_qualifications; öğrenim/deneyim, yeterlilik/İSG belgeleri, beyan; eğitici başına beş öğrenci |
| EK-20 | risk_plans, risk_items; ekip, 16 kontrol, altı senaryo, iletişim/çıkış/toplanma/ilk yardım, olasılık×şiddet, yenileme |

## Sabit proje kararları

15 öğrenci eşiği örtüşmez: altında dördüncü yarıyıl; 15 ve üzerinde yetkili kurul planı. Tek/çift öğrenci numarası ve mezuniyet istisnası semester-guidance üzerinden görünür; kurul belgesi olmadan otomatik karar oluşturulmaz.

Eşleştirme 75/25 tercih/ulaşım varsayılanıyla sürümlenir. GNO puana eklenmez; önce yerleşme sayısı, sonra toplam puan, sonra yerleşenlerin GNO/4 toplamı, sonra kayıtlı seed ile kesin eşitlik çözümü uygulanır. GNO kaynağı/ölçeği/anlık görüntüsü korunur; bu sürüm doğrulanmış 4'lük ölçek kabul eder. Eksik GNO veya ulaşım sıfırla gizlenmez. Farklı ölçek kurumun onaylı dönüşümüyle 4'lük veri olarak aktarılmalıdır. Python DB kullanmaz; sonucu Laravel yeniden doğrular. FEASIBLE çözüm için ispatlanmış optimum iddiası yoktur.

Öneri → bölüm incelemesi → MUE Komisyonu nihai kararı → Müdürlük ilanı. Karar no/tarih/belge/gerekçe, toplantı ve oy bilgileri korunur. Teknik yönetici akademik karar yetkisi almaz. Portal yetkileri görev/kurum/program/dönem/işletme/öğrenci atamasıyla ayrıdır.

Eğitim 15 hafta, 600 işyeri + 300 bağımsız saat, 30 AKTS'dir. Gece vardiyası kabul edilmez. Mazeretler dahil devamsızlık 120 saati aşarsa değerlendirme yapılmaz; eksik kayıt tam devam sayılmaz. Danışman haftalık denetim ve en az iki işyeri denetimi yapar; çevrim içi istisna karar ve EK-13 gerektirir.

Başarı bileşenleri 40/30/20/10; rubrik alanları 30/20/15/15/10/10'dur. İSG alanı en az 6/10 gerektirir. Kurumun geçme ve harf notu tablosu zorunludur. Dosya basılı ve elektronik on iş gününde teslim edilir. Plan ilk on iş gününde, gerekçeli revizyon ve danışman uygun görüşüyle yürütülür.

İşletme tekrar yetkilendirmesinde YÖNERGE Md.32 üstündür: ≥80 üç yıl, 60–79 bir yıl, <60 uygun değil; İSG ölçütü <14 askı gerektirir. Risk ≥15 öğrenci görevini engeller; 8–14 tedbir ve doğrudan gözetim ister. Tehlike sınıfına göre 2/4/6 yıllık yenileme takip edilir.

## Teknik sınırlar ve devreye alma

67 tipli PostgreSQL kayıt türü, audit/sürüm trigger'ları, FK/kapsam kontrolleri, kapasite kilitleri, tek etkin atama ve tarih çakışma kısıtı vardır. Dokümanlar karantinada/özel depoda tutulur; tarama/yetki olmadan indirilemez. Saklama metadata'sı en az beş yıldır; itiraz legal hold uygular. Otomatik imha etkinleştirilmemiştir.

Bildirim outbox/portal akışı çalışır. OBS, SSO/LDAP, SMTP/SMS, EBYS/e-imza gerçek kurum adaptörüyle bağlanır; varsayılan bağlantı gerçek işlem sayılmaz. CSV/XLSX kontrol/onaylı aktarım kullanılabilir. Dört portal ve EK-1–20 kullanım/demo ayrıntıları [PORTAL.md](PORTAL.md) içindedir. Denetim eylemleri sorumlu/termin/gösterge/kök nedenle açılır; kanıt ve yeniden ölçümle kapanır. Yıllık çıktı raporu eksik öğrencileri kapsar; Bölüm çevrim içi önerisi, belgeli kurul kararları, üretim MFA, günlük yedek ve sağlık/alarm komutları uygulanmıştır. İzole restore başarılıdır; gerçek scanner/entegrasyonlar, Docker/uzak CI, kurum UAT'si ve üretim yük onayı yapılandırma/doğrulama gerektirir.
