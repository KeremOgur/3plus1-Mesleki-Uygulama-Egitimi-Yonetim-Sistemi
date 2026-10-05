# API sözleşmesi

Kök: `/api/v1`. Kimlik: Laravel Sanctum, iki saat geçerli token. Görev: `X-Assignment-Id`. Güncel yetki her istekte ve tekrar yanıtında denetlenir. Öğrenci, danışman ve eğitici görünürlüğü kayıt sahipliği/atamasıyla; işletme görünürlüğü kendi işletmesiyle sınırlıdır. Teknik yönetici akademik verilere veya kararlara kendiliğinden erişemez.

## Ortak kayıt uç noktaları

| Yöntem | Yol | İşlev |
|---|---|---|
| GET | `/resources/{resource}` | Yetkili liste; `program_id, term_id, company_id, student_id, placement_id, cursor, limit` filtreleri |
| GET | `/resources/{resource}/{id}` | Alan yetkileri uygulanmış tek kayıt |
| POST | `/resources/{resource}` | Türün yapılandırılmış ilk durumunda kayıt oluşturma |
| PUT | `/resources/{resource}/{id}` | `version` ile izinli durumda kayıt düzenleme; süreç gerektiriyorsa gerekçe |
| POST | `/resources/{resource}/{id}/transition` | `version,state` ve gerekiyorsa komisyon karar kaydı |
| GET | `/resources/{resource}/{id}/history` | Yetkili tarihsel sürümler; alan gizliliği korunur |

Liste: `{items, next_cursor, total}`. Varsayılan 25, üst sınır 100. Yetkisiz kayıtlar toplam sayısına dahil edilmez. Filtresiz işletme/öğrenci listesiyle kişisel veri yayınlanmaz.

`preferences, matching_runs, candidate_scores, match_results, decisions, publications, publication_items, success_results, documents, import_batches, notifications, outbox_events, appeals` doğrudan oluşturulamaz/düzenlenemez. İlgili süreç uç noktaları kullanılır. Fiziksel DELETE uç noktası yoktur; bağlı tarihsel kayıtlar korunur. Değişiklik istekleri benzersiz, en fazla 150 karakterli `Idempotency-Key` kullanır.

Alan sözleşmesi `GET /schema` ve `resource-manifest.json` içinde. Bağlam alanları ilişkilerden türetilir; istemcinin farklı kurum/program/öğrenci bağlamı vermesi reddedilir. Temel ilişkiler FK alanlarıdır. JSON alanları rubrik/form dizileri, taşıma verisi, kanıt veya sabit eşleştirme girdisi içindir.

## Süreçler

| Yol | İstek / işlem |
|---|---|
| POST `/auth/login` | `email,password,otp?`; kurulmuş MFA için OTP zorunlu; üretimde kritik görev MFA'sız giriş yapamaz |
| GET `/auth/me` | Hesap ve aktif görevler |
| POST `/auth/logout` | Token iptali |
| POST `/auth/forgot-password` | Gerçek SMTP kurulumu gerektirir |
| POST `/auth/reset-password` | `token,email,password,password_confirmation` |
| POST `/accounts` / PUT `/accounts/{id}` | Teknik hesap yönetimi; akademik rol verilmez |
| POST `/assignments/{id}/revoke` | `version,reason`; rolün yetkili görev yöneticisi |
| POST `/imports/preview` | Multipart CSV/XLSX, `file,institution_id`; kalıcı öğrenci değişmez |
| POST `/imports/{id}/approve` | `version,reason`; tüm satırlar geçerli ise tek işlem |
| PUT `/applications/{id}/preferences` | `version,preferences:[{offer_id,reachable,one_way_minutes}]`; yeni sıralı sürüm |
| POST `/applications/{id}/submit-preferences` | `version,revision`; tercih takvimini sunucu denetler |
| POST `/applications/{id}/verify-transport` | `version,revision,reason`; bölüm incelemesi |
| POST `/matching-runs` | `policy_id`; 202 ve kuyruk kimliği |
| POST `/matching-runs/{id}/recommendations` | Bölüm incelemesi için öneri kayıtları; nihai karar üretmez |
| POST `/placements/{id}/review` | `version,offer_id,reason`; sert koşullar korunur |
| POST `/publications` | `term_id,decision_document_id,targets:[{type,id}]`; değiştirilemez ilan sürümü |
| POST `/appeals` | `target_type,target_id,reason,document_id?`; sonuç ve yerleştirme takvimleri ayrı |
| POST `/change-requests/{id}/credits` | `version` ile danışman süre/kazanım/kanıt değerlendirmesi |
| POST `/directorate-approvals/{resource}/{id}` | `version,document_id,reason`; change_requests/nonconformities için Müdürlük temiz karar belgesi |
| POST `/placements/{id}/online-proposal` | `version` ve belgeli Bölüm kararı; önceki izni iptal eden yeni öneri |
| POST `/placements/{id}/online-permission` | `version` ve MUE onay kararı; aynı kaydın geçerli Bölüm önerisi gerekir |
| POST `/board-decisions/{resource}/{id}` | `version` ile Müdürlük tarafından dış MYO Kurulu kararı; aynı hedefte belgeli onay gerekir, ret onay sayılmaz |
| POST `/students/{id}/confirm-profile` | Öğrencinin kendi telefon/adres/IBAN doğrulaması; akademik alanlar yazılamaz |
| GET `/applications/{id}/semester-guidance` | Kurul planı, 15 öğrenci sınırı, tek/çift öğrenci numarası ve mezuniyet istisnası |
| GET `/placements/{id}/education/{devam,denetim,baslama}` | Süre, haftalık/işyeri denetim eksikleri ve başlama koşulları |
| POST `/placements/{id}/calculate-success` | Sonuç yeni hesap sürümü; nihai ilan ayrı |
| POST `/protocols/{id}/renew` | `version,reason`; fesih bildirimi olmayan protokol için yıllık yeni sürüm |
| GET `/interviews/{id}/student-summary` | Yetkili işletmeye gerekli öğrenci adı/no/programı; GNO/disiplin ayrıntısı yok |
| POST `/documents` | Multipart `file,target_type,target_id,classification,retention_start_event`; karantina |
| GET `/documents/{id}/download` | Kayıt ve belge yetkisi, tarama ve SHA256 kontrolü |
| GET `/reports/{dashboard,monitoring,attendance,company-history,outcomes,audit}` | Yetki kapsamlı raporlar |
| GET `/exports/{resource}?format=csv\|xlsx\|pdf` | Yetkili dışa aktarma; formül enjeksiyonu engellenir |

## Karar kaydı

Devam ve denetim raporları `{items,next_cursor,total}` ile sayfalanır. `/reports/outcomes` için `term_id` zorunludur; `learning_outcomes` ve `program_outputs` döndürür. Son onaylı değerlendirmelerle öğrenci bazında alan/bileşen hesabı yapılır; hiç değerlendirmesi olmayanlar dahil eksik öğrenciler `incomplete_students` olarak görünür, tamamlanmamış sonuç sıfır not sayılmaz. Dışa aktarmada `format` zorunludur.

Komisyon kararı gerektiren geçişler `decision_no,decision_on,document_id,reason,meeting_date,members_count,attendees_count,votes_for,votes_against,chair_vote_for,outcome` içerir. Salt çoğunluk ve oylama tutarlılığı doğrulanır; eşit oyda başkanın oyu korunur. Kayıt görevlisi aktif kullanıcı/görevden alınır. Belge, resmi imza yerine geçirilmez.

Nihai yerleştirme: `onerildi → bolum_incelemesi → onaylandi → ilan_edildi → baslamaya_hazir → basladi → tamamlandi`. İlk aşamalar bölüm, nihai onay MUE Komisyonu, yayın Müdürlüktür. Yer/kapasite/tercih/uygunluk kontrolü elle değişiklikte ve ilan sırasında sürer. Eşleştirme girdisindeki değişiklik eski çalışma onayını engeller.

## Hata gövdesi

`{code,message,field_errors,request_id}`. 401 giriş, 403 görev/kapsam, 404 kayıt, 409 sürüm/kontenjan/durum, 422 alan doğrulama, 503 eksik entegrasyon. Kullanıcıya SQL, yığın izi veya özel belge yolu dönmez.
