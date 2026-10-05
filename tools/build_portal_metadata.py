from pathlib import Path
import json
root=Path('D:/MUE')
d=json.loads((root/'docs/resource-manifest.json').read_text(encoding='utf8'))
titles='''institutions|Kurum ve MYO
departments|Bölümler
programs|Programlar
academic_terms|Dönem ve takvim
companies|İşletme havuzu
company_sites|İşletme şubeleri
students|Öğrenci ve akademik bilgiler
role_assignments|Görev ve yetkiler
program_outputs|Program çıktıları
learning_outcomes|Öğrenme kazanımları
term_programs|Kurul ve yarıyıl planı
trainer_qualifications|Eğitici yeterlilikleri
company_assessments|İşletme uygunluğu
protocols|İşyeri protokolleri
protocol_scopes|Protokol kapsamları
offers|Uygun işletme ve kontenjanlar
capacity_requests|Kontenjan talepleri
applications|MUE başvuruları
preferences|Sıralı tercihler
interviews|Öğrenci görüşmeleri
matching_policies|Eşleştirme politikaları
matching_runs|Eşleştirme çalışmaları
candidate_scores|Aday uygunluğu ve puanları
match_results|Yerleştirme önerileri
placements|Yerleştirme ve eğitim
decisions|Komisyon kararları
publications|Resmî ilanlar
publication_items|İlan edilen sonuçlar
appeals|İtirazlar
change_requests|İşletme değişikliği talepleri
adviser_assignments|Akademik danışman atamaları
trainer_assignments|İşyeri eğitici atamaları
learning_plans|Bireysel öğrenme planları
plan_outcomes|Kazanım ve faaliyet eşlemesi
weekly_plan_tasks|Haftalık görev planı
attendance|Devam takibi
weekly_reports|Haftalık faaliyet raporları
report_outcomes|Rapor ve kazanım bağlantıları
portfolio_evidence|Dijital portfolyo ve kanıtlar
self_assessments|Öz değerlendirme
inspections|Danışman denetimleri
online_records|Çevrim içi görüşme tutanakları
rubric_versions|Rubrik sürümleri
rubric_evaluations|Kazanım ve performans değerlendirmesi
presentations|Dönem sonu sunumları
training_file_deliveries|Eğitim dosyası teslimi
success_results|Başarı ve değerlendirme sonuçları
declarations|Taahhütnameler
ohs_records|İSG ve koruyucu donanım
insurance_records|Sigorta ve işe giriş
financial_policies|Mali politikalar
payroll|Ücret ve puantaj
fund_contributions|Devlet katkısı işlemleri
nonconformities|Uygunsuzluk bildirimleri
incidents|İş kazası ve olay bildirimleri
feedback|Kalite ve geri bildirim
company_performance|İşletme performansı
annual_reports|Yıllık değerlendirme raporları
improvement_actions|İyileştirme eylemleri
risk_plans|Risk ve acil durum planları
risk_items|Risk değerlendirme maddeleri
recognition_requests|Önceki eğitimin tanınması
change_control|Sistem değişiklik yönetimi
documents|Belgeler
import_batches|Öğrenci aktarım kayıtları
notifications|Bildirimler
outbox_events|Bildirim işlem kuyruğu'''
labels=dict(line.split('|',1) for line in titles.splitlines())
words='''20plus|20 ve üzeri
absence|Devamsızlık
academic|Akademik
accepted|Kabul
account|Hesap
action|Eylem
actions|Eylemler
active|Aktif
activity|Faaliyet
adaptation|İntibak
address|Adres
adviser|Danışman
affected|Etkilenen
against|Ret
aggregate|İlgili kayıt
agreement|Sözleşme
aid|Yardım
algorithm|Algoritma
annual|Yıllık
answers|Yanıtlar
aphb|APHB
appeal|İtiraz
application|Başvuru
approval|Onay
approved|Onaylı
area|Alan
assembly|Toplanma
assessed|Değerlendirme
assessment|Değerlendirme
at|Zamanı
attachments|Ekler
attainment|Erişim
attended|Katılım
attendees|Katılımcı
authorization|Yetkilendirme
authorized|Yetkili
available|Uygulanabilir
backup|Yedek
bank|Banka
behavior|Davranış
board|Kurul
branch|Şube
bytes|Bayt
calculation|Hesaplama
capacity|Kontenjan
cause|Neden
causes|Nedenler
certificate|Belge
certificates|Sertifikalar
chair|Başkan
channel|Kanal
checklist|Kontrol listesi
claim|Talep
claimed|Talep edilen
class|Sınıf
classification|Gizlilik sınıfı
code|Kod
cohort|Grup
comments|Görüşler
commission|Komisyon
company|İşletme
comparison|Karşılaştırma
competencies|Yetkinlikler
complete|Tam
completed|Tamamlandı
completion|Tamamlanma
component|Bileşen
conclusion|Sonuç
conditions|Koşullar
confirmed|Doğrulandı
consent|Onay
contact|İletişim
contacts|İletişim bilgileri
content|İçerik
contribution|Katkı
controls|Kontroller
corrective|Düzeltici
count|Sayısı
course|Ders
credited|Mahsup edilen
criteria|Ölçütler
criterion|Ölçüt
current|Güncel
data|Veriler
date|Tarih
dates|Tarihler
days|Gün
deadline|Son tarih
decision|Karar
declaration|Beyan
degree|Öğrenim düzeyi
delivered|Teslim
delivery|Teslim
department|Bölüm
description|Açıklama
direct|Doğrudan
directorate|Müdürlük
district|İlçe
diversity|Çeşitlilik
document|Belge
documents|Belgeler
drill|Tatbikat
due|Ödenecek
duties|Görevler
duty|Görev
ects|AKTS
effect|Etki
effects|Etkiler
effort|İş gücü
electronic|Elektronik
eligibility|Uygunluk
eligible|Uygun
email|E-posta
emergency|Acil durum
employer|İşveren
employment|İstihdam
enabled|Etkin
end|Bitiş
ends|Bitiş
entry|İşe giriş
errors|Hatalar
estimated|Tahmini
evaluation|Değerlendirme
event|Olay
evidence|Kanıt
exception|İstisna
exit|Çıkış
expected|Beklenen
experience|Deneyim
explained|Açıklanan
explanation|Açıklama
explanations|Açıklamalar
extension|Uzatma
extinguisher|Yangın söndürücü
facility|Kuruluş
failure|Başarısızlık
fault|Kusur
feedback|Geri bildirim
fetched|Alınma
field|Alan
financial|Mali
finding|Bulgu
findings|Bulgular
first|İlk
for|Kabul
form|Form
from|Başlangıç
general|Genel
gpa|GNO
grade|Not
graduated|Mezuniyet
graduation|Mezuniyet
gross|Brüt
grouping|Gruplandırma
hash|Özet
hazard|Tehlike
health|Sağlık
hold|Saklama engeli
holiday|Tatil
hours|Saat
iban|IBAN
id|Kaydı
ids|Kayıtları
impact|Etki
independent|Bağımsız çalışma
indicator|Gösterge
information|Bilgi
infrastructure|Altyapı
inspected|Denetim
inspection|Denetim
institution|Kurum
interruption|Kesinti
interview|Görüşme
investigation|İnceleme
ip|Fikri mülkiyet
item|Madde
items|Maddeler
kep|KEP
key|Anahtar
knowledge|Bilgi
legal|Hukuki
letter|Harf
level|Düzey
levels|Düzeyler
limit|Sınır
location|Konum
mark|Devam işareti
material|Materyal
max|Azami
meal|Yemek
meeting|Toplantı
member|Üye
members|Üye
mersis|MERSİS
message|İleti
migration|Şema değişikliği
mime|Dosya türü
minimum|Asgari
minutes|Dakika
mitigation|Tedbir
mode|Yöntem
month|Ay
months|Ay
nace|NACE
name|Ad
national|T.C. kimlik
nearest|En yakın
need|İhtiyaç
net|Net
new|Yeni
next|Sonraki
no|Numarası
notice|Bildirim
notification|Bildirim
notified|Bildirim
number|Numara
object|Depolama
objective|Amaç
occurred|Olay
offer|İşletme teklifi
official|Resmî
ohs|İSG
on|Tarihi
one|Tek
online|Çevrim içi
onsite|Yerinde
opens|Açılış
opinion|Görüş
organization|Kuruluş
orientation|Oryantasyon
original|Orijinal
outcome|Kazanım
outcomes|Kazanımlar
output|Çıktı
owned|Sahipliği
owner|Sorumlu
paid|Ödenen
paper|Basılı
participants|Katılımcılar
pass|Geçme
payload|İçerik
payment|Ödeme
payroll|Puantaj
pedagogy|Pedagoji
percent|Yüzdesi
permission|İzin
personnel|Personel
phase|Aşama
phone|Telefon
physical|Fiziksel
placement|Yerleştirme
plan|Plan
planned|Planlanan
platform|Platform
policy|Politika
portfolio|Portfolyo
possible|Olası
ppe|KKD
preference|Tercih
preferences|Tercihler
premium|Prim
presented|Sunum
previous|Önceki
probability|Olasılık
problems|Sorunlar
processed|İşlenme
program|Program
proposed|Önerilen
protected|Korumalı
protocol|Protokol
province|İl
publication|İlan
published|İlan
qualification|Yeterlilik
qualified|Nitelikli
rank|Sıra
rate|Oranı
reachable|Ulaşılabilir
read|Okunma
reason|Gerekçe
reasons|Gerekçeler
receipt|Makbuz
recipient|Alıcı
record|Kayıt
recorded|Kayıt
reference|Referans
reflection|Yansıtma
remeasured|Yeniden ölçülen
renewal|Yenileme
report|Rapor
reported|Bildirim
request|Talep
requested|Talep edilen
required|Gerekli
requirements|Gereksinimler
residual|Kalan risk
resource|Kaynak
response|Müdahale
responsible|Sorumlu
result|Sonuç
retention|Saklama
retry|Tekrar
review|Görüş
reviewed|İncelendi
revision|Sürüm
rights|Hakları
risk|Risk
risks|Riskler
role|Görev
root|Kök
rows|Satırlar
rubric|Rubrik
rule|Kural
rules|Kurallar
run|Çalışma
scale|Ölçeği
scan|Tarama
scenarios|Senaryolar
scheduled|Planlanan
score|Puan
scores|Puanlar
seconds|Saniye
sector|Sektör
security|Güvenlik
seed|Deterministik anahtar
self|Öz
semester|Yarıyıl
sent|Gönderim
severity|Şiddet
sgk|SGK
sha256|SHA256
share|Payı
shift|Vardiya
shuttle|Servis
signed|İmzalı
site|Şube
size|Boyut
skills|Beceriler
snapshot|Anlık görüntü
solutions|Çözümler
solver|Çözücü
source|Kaynak
sources|Kaynaklar
stage|Aşama
stakeholder|Paydaş
start|Başlangıç
started|Başlama
starts|Başlangıç
state|Durum
statistics|İstatistikler
status|Durum
steps|Adımlar
student|Öğrenci
submitted|Gönderim
suitable|Uygun
summary|Özet
supersedes|Önceki sürüm
supervision|Gözetim
target|Hedef
tax|Vergi
team|Ekip
technical|Teknik
termination|Fesih
test|Test
text|Metin
threshold|Eşiği
time|Süre
title|Unvan
to|Teslim
total|Toplam
trained|Eğitim
trainer|Eğitici
training|Eğitim
transferable|Aktarılabilir
transport|Ulaşım
type|Türü
tyyc|TYYÇ
unannounced|Habersiz
under20|20 altı
unit|Birim
until|Bitiş
user|Kullanıcı
valid|Geçerlilik
value|Değer
values|Değerler
verified|Doğrulandı
version|Sürüm
vote|Oyu
votes|Oy
wage|Ücret
warning|Uyarı
way|Yön
week|Hafta
weeks|Hafta
weight|Ağırlığı
witnesses|Tanıklar
worked|Çalışılan
workplace|İşyeri
year|Yıl
years|Yıl'''
w=dict(line.split('|',1) for line in words.splitlines())
allfields={f for r in d.values() for f in r['fields']}|{'institution_id','program_id','term_id','student_id','company_id','placement_id','version','state','created_at','updated_at','retained_until','created_by','reason','password','password_confirmation','active'}
w.update(term='Dönem',created='Oluşturma',updated='Güncelleme',retained='Saklama',by='Yapan',password='Parola',confirmation='Tekrarı')
fields={f:' '.join(w.get(t,t) for t in f.split('_')) for f in allfields}
overrides='''name|Adı / adı soyadı
national_id|T.C. kimlik numarası
student_no|Öğrenci numarası
user_id|Kullanıcı
institution_id|Kurum / MYO
program_id|Program
term_id|Akademik dönem
student_id|Öğrenci
company_id|İşletme
placement_id|Yerleştirme
valid_from|Geçerlilik başlangıcı
valid_until|Geçerlilik bitişi
starts_on|Başlangıç tarihi
ends_on|Bitiş tarihi
legal_name|Ticaret unvanı
contact_name|Yetkilinin adı soyadı
contact_title|Yetkilinin görevi
program_suitable|Program hedeflerine uygunluk
technical_infrastructure|Teknik altyapı ve ekipman yeterli
qualified_trainer|Yeterli eğitici personel mevcut
ohs_suitable|İSG koşulları uygun
physical_conditions|Fiziksel çalışma koşulları uygun
activity_diversity|Mesleki faaliyet çeşitliliği yeterli
onsite_inspected|Yerinde inceleme yapıldı
gpa|Genel not ortalaması (GNO)
gpa_scale|GNO ölçeği
gpa_snapshot|Başvuru anındaki GNO
preference_weight|Tercih ağırlığı
transport_weight|Ulaşım ağırlığı
transport_rules|Ulaşım puan tablosu
one_way_minutes|Tek yön ulaşım süresi (dakika)
max_preferences|Azami tercih sayısı
activity_text|Yapılan faaliyetlerin açıklaması
knowledge_skills|Kazanılan bilgi ve beceriler
problems_solutions|Karşılaşılan sorunlar ve çözümleri
trainer_review|İşyeri eğiticisinin görüşü
adviser_review|Akademik danışmanın görüşü
trainer_approved|Eğitici onayı
adviser_reviewed|Danışman incelemesi
planned_minutes|Planlanan süre (dakika)
attended_minutes|Katılım süresi (dakika)
workplace_inspection|İşyerinde yapılan denetim
criteria_scores|Değerlendirme ölçütleri
criterion_values|Ölçüt puanları (0–100)
criterion_scores|İşletme performans ölçütleri
behavior_evidence|Davranış ve kanıt açıklaması
reason|Gerekçe
reason_code|Gerekçe kodu
revision_reason|Revizyon gerekçesi
commission_member_id|Komisyon üyesi
decision_no|Karar numarası
decision_on|Karar tarihi
meeting_date|Toplantı tarihi
members_count|Komisyon üye sayısı
attendees_count|Katılan üye sayısı
votes_for|Kabul oyu
votes_against|Ret oyu
chair_vote_for|Başkanın oyu kabul yönünde
trainer_id|İşyeri eğitim sorumlusu
site_id|İşletme şubesi
outcome_id|Öğrenme kazanımı
program_output_id|Program çıktısı
bank_account_student_owned|Hesap öğrencinin kendisine ait
net_minimum_wage|Geçerli net asgari ücret (₺)
minimum_due|Hesaplanan asgari ödeme (₺)
gross_paid|Ödenen brüt tutar (₺)
net_paid|Ödenen net tutar (₺)
state_contribution|Hesaplanan devlet katkısı (₺)
employer_share|İşveren payı (₺)
total_claim|Toplam katkı talebi (₺)
protected_material|Gizli / korumalı materyal içeriyor
retention_start_event|Saklama başlangıç olayı
legal_hold|İtiraz / hukuki saklama engeli
scan_status|Zararlı içerik taraması
original_name|Dosya adı
size_bytes|Dosya boyutu (bayt)
student_signed_document_id|Öğrenci imzalı plan
trainer_signed_document_id|Eğitici imzalı plan
adviser_signed_document_id|Danışman imzalı plan
qualification_document_id|Yeterlilik belgesi
company_permission_id|İşletmenin yazılı paylaşım izni
entry_document_id|İşe giriş bildirgesi
ppe_delivery_document_id|KKD teslim belgesi
text_version|Taahhütname metin sürümü
outcome|Sonuç / karar
source|Değerlendirme / veri kaynağı
source_role|Geri bildirim veren taraf
created_at|Oluşturulma zamanı
updated_at|Güncellenme zamanı
retained_until|En erken saklama bitişi
created_by|Kaydı oluşturan
pass_threshold|Kurumun geçme notu
letter_grade_rules|Kurumun harf notu tablosu
time_limit_seconds|Çözücü süre sınırı (saniye)
seed|Tekrar üretim anahtarı
total_score|Toplam puan
authorized_failure|Yetkili kararla başarısızlık
classification|Belge gizlilik sınıfı'''
fields.update(dict(line.split('|',1) for line in overrides.splitlines()))
enums={}
enumlabels='''sistem_yoneticisi|Teknik sistem yöneticisi
mudur|MYO Müdürü / Komisyon Başkanı
mue_komisyon|MUE Komisyonu
bolum_komisyon|Bölüm / Program Komisyonu
program_baskani|Bölüm / Program Başkanı
koordinator|Okul–Sanayi Koordinatörü
akademik_danisman|Akademik Danışman
egitici|Eğitici Personel
isletme_yetkilisi|İşletme Yetkilisi
ogrenci|Öğrenci
belge_gorevlisi|İdari Belge Görevlisi
taslak|Taslak
gonderildi|Gönderildi
inceleniyor|İnceleniyor
iade|Düzeltme istendi
onaylandi|Onaylandı
reddedildi|Reddedildi
ilan_edildi|İlan edildi
aktif|Aktif
pasif|Pasif
askida|Askıda
onerildi|Önerildi
bolum_incelemesi|Bölüm incelemesinde
baslamaya_hazir|Başlamaya hazır
basladi|Eğitim başladı
tamamlandi|Tamamlandı
degistirildi|Değiştirildi
uygun|Uygun
eksik|Eksik bilgi
uygun_degil|Uygun değil
egitici_onayi|Eğitici onayladı
danisman_onayi|Danışman onayladı
hesaplandi|Hesaplandı
karantina|Karantinada
bekliyor|Bekliyor
temiz|Tarama tamamlandı — temiz
zararli|Zararlı içerik tespit edildi
hazir|Hazır
kuyrukta|Kuyrukta
calisiyor|Çalışıyor
basarisiz|Başarısız
basarili|Başarılı
alindi|Alındı
kabul|Kabul
ret|Ret
talep|Talep oluşturuldu
karar_verildi|Karar verildi
uygulandi|Uygulandı
kapandi|Kapandı
acik|Açık
eslestirme|Eşleştirme
uygulama|Uygulama
degerlendirme|Değerlendirme
kapali|Kapalı
imza_bekliyor|İmza bekleniyor
feshedildi|Feshedildi
suresi_doldu|Süresi doldu
dogrulandi|Doğrulandı
kayitli|Kayıtlı
onizleme|Önizleme
islendi|İşlendi
uygulaniyor|Uygulanıyor
kontrol_edildi|Kontrol edildi
ozel|Özel işletme
kamu|Kamu
universite|Üniversite
ortak|Ortak kontenjan
ayri|Ayrı kontenjan
az_tehlikeli|Az tehlikeli
tehlikeli|Tehlikeli
cok_tehlikeli|Çok tehlikeli
on_lisans|Ön lisans
lisans|Lisans
lisansustu|Lisansüstü
gunduz|Gündüz
V|Var
Y|Yok
I|İzinli
R|Raporlu
yuz_yuze|Yüz yüze
cevrim_ici|Çevrim içi
donem_basi|Dönem başı
donem_sonu|Dönem sonu
bilgi|Bilgi
beceri|Beceri
yetkinlik|Yetkinlik
is_yeri|İşyeri değerlendirmesi
danisman|Danışman değerlendirmesi
dosya_portfolyo|Dosya ve portfolyo
sunum|Sunum / uygulama
mesleki|Mesleki bilgi ve beceri
problem|Problem çözme
takim|İletişim ve ekip çalışması
etik|Etik ve sorumluluk
isg|İş sağlığı ve güvenliği
belgeleme|Raporlama ve belgeleme
planlandi|Planlandı
tam|Tamamlandı
kismi|Kısmen tamamlandı
kurum_ici|Kurum içi
gizli|Gizli
saglik|Sağlık verisi
disiplin|Disiplin verisi
is_kazasi|İş kazası
ramak_kala|Ramak kala
meslek_hastaligi|Meslek hastalığı
yapildi|Yapıldı
yapilacak|Yapılacak
OPTIMAL|Optimum çözüm
FEASIBLE|Uygulanabilir çözüm — optimum ispatlanmadı
UNKNOWN|Çözüm durumu belirsiz
INFEASIBLE|Uygulanabilir çözüm yok
ERROR|Çözücü hatası
onay|Onay
askiya_alma|Askıya alma
fesih|Fesih
bolum|Bölüm Komisyonu
mue|MUE Komisyonu
mudurluk|Müdürlük
kurul|MYO Kurulu
portal|Portal bildirimi
email|E-posta
sms|SMS'''
enums=dict(line.split('|',1) for line in enumlabels.splitlines())
groups={
 'Başvuru ve yerleştirme':'applications preferences offers interviews placements appeals change_requests recognition_requests',
 'Eğitim ve izleme':'students learning_plans plan_outcomes weekly_plan_tasks attendance weekly_reports report_outcomes portfolio_evidence self_assessments inspections online_records training_file_deliveries',
 'Değerlendirme':'rubric_versions rubric_evaluations presentations success_results program_outputs learning_outcomes',
 'İşletme ve güvenlik':'companies company_sites trainer_qualifications company_assessments protocols protocol_scopes capacity_requests declarations ohs_records insurance_records incidents nonconformities risk_plans risk_items',
 'Komisyon ve yönetim':'institutions departments programs academic_terms term_programs role_assignments adviser_assignments trainer_assignments matching_policies matching_runs candidate_scores match_results decisions publications publication_items',
 'Mali işlemler ve kalite':'financial_policies payroll fund_contributions feedback company_performance annual_reports improvement_actions',
 'Belgeler ve sistem':'documents notifications import_batches change_control outbox_events'}
fields.update({'schema_version': 'Veri sözleşmesi sürümü', 'policy_version': 'Politika sürümü', 'weights': 'Puan ağırlıkları', 'preference': 'Tercih', 'transport': 'Ulaşım', 'sites': 'İşletme şubeleri', 'trainers': 'Eğitici personel', 'candidates': 'Aday çiftleri', 'fixed_offer_id': 'Mevcut sabit atama', 'placed_count': 'Yerleşen öğrenci sayısı', 'unplaced_count': 'Yerleşemeyen öğrenci sayısı', 'total_score': 'Toplam puan', 'normalized_gpa_sum': 'Normalize GNO toplamı', 'stages': 'Optimizasyon aşamaları', 'wall_time_seconds': 'Geçen süre (saniye)', 'reason': 'Gerekçe', 'attended_minutes': 'Tamamlanan eğitim (dakika)', 'absent_minutes': 'Devamsızlık (dakika)', 'planned_minutes': 'Planlanan eğitim (dakika)', 'expected_minutes': 'Gerekli eğitim (dakika)', 'missing_records': 'Devam kayıtları eksik', 'absence_percent': 'Devamsızlık yüzdesi', 'missing_weeks': 'İzleme bulunmayan haftalar', 'required_workplace_inspections': 'Gerekli işyeri denetimi', 'workplace_inspections': 'Gerçekleşen işyeri denetimi', 'weekly_requirement_met': 'Haftalık izleme koşulu sağlandı', 'workplace_requirement_met': 'İşyeri denetimi koşulu sağlandı', 'complete': 'Kayıtlar tamam', 'failed': 'Devamsızlık sınırı aşıldı', 'student_name': 'Öğrenci adı soyadı', 'readiness': 'Başlama koşullarındaki eksikler', 'attendance': 'Devam özeti', 'monitoring': 'Denetim özeti', 'plan_deadline': 'İlk plan için son tarih', 'file_deadline': 'Eğitim dosyası teslim son tarihi', 'row': 'Satır numarası', 'fields': 'Alanlar', 'name': 'Adı soyadı', 'email': 'E-posta', 'password_confirmation': 'Parola tekrarı', 'active': 'Hesap aktif', 'id': 'Kayıt referansı', 'week_start': 'Haftanın başlangıç tarihi', 'week_end': 'Haftanın bitiş tarihi', 'revision': 'Form sürümü', 'document_id': 'İlgili belge', 'retention_start_event': 'Saklama başlangıç olayı', 'items': 'Geri bildirim ölçütleri', 'criterion_scores': 'İşletme performans ölçütleri', 'criteria_scores': 'Değerlendirme ölçütleri', 'transferable_competencies': 'Aktarılabilir yetkinlikler', 'outcome_attainment': 'Kazanımlara ulaşma düzeyi', 'objective_values': 'Optimizasyon amaçları', 'dependencies': 'Çalışmada kullanılan veri sürümleri', 'result': 'Çözücü sonucu', 'action': 'İşlem', 'actor_id': 'İşlemi yapan kullanıcı', 'assignment_id': 'İşlemi yapanın görevi', 'request_id': 'İstek referansı', 'before_hash': 'Önceki kayıt bütünlük özeti', 'after_hash': 'Sonraki kayıt bütünlük özeti', 'proven_optimal': 'Optimum ispatlandı', 'stage': 'Aşama', 'best_bound': 'En iyi sınır', 'gap': 'Optimizasyon farkı', 'elapsed_seconds': 'Geçen süre', 'evaluated_students': 'Değerlendirilen öğrenci sayısı', 'incomplete_students': 'Eksik değerlendirmeli öğrenci sayısı', 'average': 'Ortalama', 'above_60_percent': '%60 ve üzerinde başarı oranı', 'target_met': 'Hedef sağlandı', 'max_minutes': 'Azami tek yön dakika', 'minimum': 'Alt sınır', 'letter': 'Harf notu', 'type': 'Tür', 'key': 'Alan', 'value': 'Değer', 'source': 'Kaynak', 'behavior': 'Gözlenebilir davranış'})
enums.update({'ACCOUNT_CREATE': 'Hesap oluşturma', 'ACCOUNT_UPDATE': 'Hesap düzenleme', 'ACCOUNT_DEACTIVATE': 'Hesap pasife alma', 'INSERT': 'Kayıt oluşturma', 'UPDATE': 'Kayıt güncelleme', 'DELETE': 'Kayıt silme', 'DOWNLOAD': 'Belge indirme', 'EXPORT': 'Dışa aktarma', 'NOTIFICATION_READ': 'Bildirim okuma', 'yetersiz': 'Yetersiz', 'gelismekte': 'Gelişmekte', 'yeterli': 'Yeterli', 'ustun': 'Üstün', 'suresi_doldu': 'Süresi doldu', 'sinif': 'Sınıf', 'email': 'E-posta'})
metadata={'labels':labels,'fields':fields,'values':enums,'groups':{g:v.split() for g,v in groups.items()}}
missing=sorted({word for f in allfields for word in f.split('_') if word not in w})
assert not missing,missing
assert set(labels)==set(d)
assert set(d)=={r for vals in metadata['groups'].values() for r in vals}
def php(v):
 if isinstance(v,dict):return '['+','.join(php(k)+'=>'+php(x) for k,x in v.items())+']'
 if isinstance(v,list):return '['+','.join(php(x) for x in v)+']'
 return "'"+str(v).replace('\\','\\\\').replace("'","\\'")+"'"
(root/'backend/config/portal.php').write_text('<?php\nreturn '+php(metadata)+';\n',encoding='utf8')
print('Turkish portal metadata:',len(labels),'resources;',len(fields),'fields')
