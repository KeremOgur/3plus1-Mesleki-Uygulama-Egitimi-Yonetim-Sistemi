from pathlib import Path
import json,hashlib,re,difflib
root=Path('D:/MUE')
portal=root/'backend/config/portal.php'
s=portal.read_text(encoding='utf8')
if not s.startswith('<?php\n$portal='):
    s=s.replace('return [','$portal=[',1)
    s+='\n$portal["fields"]=array_merge($portal["fields"],'+'''['tax_office'=>'Vergi dairesi','profile_confirmed_at'=>'Profil doğrulama tarihi','prepared_on'=>'Plan hazırlama tarihi','adviser_opinion_document_id'=>'Danışman uygun görüş belgesi','workplace_criteria_scores'=>'EK-3 on iki ölçüt puanı','portfolio_platform'=>'Portfolyo ortamı','portfolio_integrity'=>'Portfolyo bütünlüğü','evidence_consistency'=>'Kanıtlarla tutarlılık','adviser_feedback'=>'Danışman geri bildirimi','consent_record_document_id'=>'Kişisel veri işleme beyanı / dayanak belgesi','education_records'=>'Öğrenim geçmişi','experience_records'=>'Mesleki deneyim geçmişi','student_units'=>'Öğrencinin çalışacağı birimler','orientation_hours'=>'İSG oryantasyon süresi (saat)','orientation_document_id'=>'İSG oryantasyon belgesi','credit_reviewed_by'=>'Mahsup değerlendirmesini yapan danışman','collection_hash'=>'Aday kümesi bütünlük özeti']''' +');\n'
    s+='''$portal['values']=array_merge($portal['values'],['kismen_yeterli'=>'Kısmen yeterli','tutarli'=>'Tutarlı','kismen_tutarli'=>'Kısmen tutarlı','tutarsiz'=>'Tutarsız']);
return $portal;
'''
    portal.write_text(s,encoding='utf8')
sources=Path('C:/Users/Kerem/Downloads')
names=['YÖNERGE.pdf','İŞYERİ PROTOKOLÜ.pdf','MUE_3plus1_Ayrintili_Sistem_Tasarimi_v0_9.pdf','MUE_3plus1_Yazilim_Projesi_Gereksinim_ve_Tasarim_Dokumani.docx']
(root/'docs/sources/final-audit/source-hashes.json').write_text(json.dumps({n:hashlib.sha256((sources/n).read_bytes()).hexdigest() for n in names},ensure_ascii=False,indent=2),encoding='utf8')
def form_lines(n):
    lines=(root/'docs/sources/final-audit'/n).read_text(encoding='utf8').splitlines()
    start=next(i for i,l in enumerate(lines) if l=='EK-1')
    return [l for l in lines[start:] if not (re.match(r'=== PAGE|\[5\]|\d+ / \d+|Bu belge|EK-\d+ .*Sayfa',l) or 'Elazığ Organize Sanayi Bölgesi Meslek Yüksekokulu Mesleki Uygulama Eğitimi' in l)]
diff=list(difflib.unified_diff(form_lines('YÖNERGE.pdf.txt'),form_lines('İŞYERİ PROTOKOLÜ.pdf.txt'),n=1))
(root/'docs/sources/final-audit/form-differences.txt').write_text('\n'.join(diff),encoding='utf8')
print('Form comparison lines:',len(diff))
