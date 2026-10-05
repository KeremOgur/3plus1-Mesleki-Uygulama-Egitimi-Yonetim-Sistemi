from pathlib import Path
r=Path(__file__).resolve().parents[1]
p=r/'backend/resources/js/portal/workflows.js'
s=p.read_text(encoding='utf-8').replace('run.error_message','run.failure_message')
s=s.replace("['annual_reports','term_programs'].includes(t)","['recognition_requests','annual_reports','program_outputs','term_programs','rubric_versions'].includes(t)")
s=s.replace("decisionFields,{outcome:'onay'},'Kurul toplantısı", "decisionFields,{version:r.version,outcome:'onay'},'Kurul toplantısı")
guard="  if(t==='rubric_evaluations'&&role!==(r.source==='is_yeri'?'egitici':'akademik_danisman'))continue;"
s=s.replace(guard+'\n'+guard,guard)
if guard not in s:
    s=s.replace("for(const[to,rule]of Object.entries(spec.actions[r.state]||{})){", "for(const[to,rule]of Object.entries(spec.actions[r.state]||{})){\n"+guard)
if 'online-proposal' not in s:
    s=s.replace("if(role==='mue_komisyon')action(bar,'Çevrim içi denetim izni'", "if(role==='bolum_komisyon')action(bar,'Çevrim içi denetim önerisi',()=>operation('Bölüm Komisyonu çevrim içi denetim önerisi',`placements/${r.id}/online-proposal`,decisionFields,{version:r.version,outcome:'onay'},'Gerekçeli ve belgeli Bölüm önerisi MUE Komisyonunun nihai iznine sunulur.'));\n  if(role==='mue_komisyon')action(bar,'Çevrim içi denetim izni'")
s=s.replace("!Object.keys(spec.states||{}).length||['taslak'", "!Object.keys(spec.states||{}).length||(t==='academic_terms'&&r.state==='acik')||(t==='companies'&&r.state==='aktif')||['taslak'")
p.write_text(s,encoding='utf-8')
p=r/'backend/resources/js/portal/forms.js';s=p.read_text(encoding='utf-8').replace('İlk plan on iş günü içinde onaylanır.','İlk plan on iş günü içinde hazırlanır ve imzalanır.');p.write_text(s,encoding='utf-8')
