"""Generate the portal contract from the typed resource manifest and live route inventory."""
import json,re
from pathlib import Path
root=Path(__file__).resolve().parents[1]
resources=json.loads((root/'docs/resource-manifest.json').read_text(encoding='utf-8'))
routes=json.loads((root/'docs/routes.json').read_text(encoding='utf-8-sig'))
uuid={'type':'string','format':'uuid'}
contexts=['institution_id','program_id','term_id','company_id','student_id','placement_id']
internal={'preferences','matching_runs','candidate_scores','match_results','decisions','publications','publication_items','success_results','documents','import_batches','notifications','outbox_events','appeals'}
schemas={}
def field(d):
    t=d['type']; r=d['rules']; s={'type':{'text':'string','string':'string','integer':'integer','bigint':'integer','decimal':'number','boolean':'boolean','uuid':'string','date':'string','timestamp':'string','jsonb':'array'}[t], 'description':'Sunucu doğrulaması: '+r,'x-laravel-rules':r}
    if t in ['uuid','date','timestamp']: s['format']={'uuid':'uuid','date':'date','timestamp':'date-time'}[t]
    if t=='jsonb': s.pop('type'); s['oneOf']=[{'type':'object','additionalProperties':True},{'type':'array','items':{}}]
    if d['ref']: s['x-reference-resource']=d['ref']
    if 'nullable' in r: s['nullable']=True
    if t=='string' and '|in:' in r: s['enum']=r.split('|in:')[1].split('|')[0].split(',')
    return s
for name,spec in resources.items():
    props={k:field(d) for k,d in spec['fields'].items()}
    if name!='institutions': props.update({k:dict(uuid,nullable=True,description='İlişkiden türetilen yetki kapsamı') for k in contexts})
    required=[k for k,d in spec['fields'].items() if d['rules'].startswith(('required','present'))]
    required+=spec['required_context']
    required=[k for k in dict.fromkeys(required) if k in props]
    schemas[name+'Input']={'type':'object','properties':props,'required':required,'x-writer-roles':spec['write'],'x-required-context':spec['required_context']}
    schemas[name]={'type':'object','properties':dict(props,id=uuid,state={'type':'string'},version={'type':'integer'},created_at={'type':'string','format':'date-time'},updated_at={'type':'string','format':'date-time'},retained_until={'type':'string','format':'date-time','nullable':True},legal_hold={'type':'boolean'}),'x-reader-roles':spec['read'],'x-state-transitions':spec['states'],'x-ek':spec['ek'],'description':'Yanıtta göreve göre gizli alanlar çıkarılır.'}
schemas['Error']={'type':'object','properties':{'code':{'type':'string'},'message':{'type':'string','description':'Türkçe hata metni'},'field_errors':{'type':'object','additionalProperties':True},'request_id':{'type':'string'}}}
schemas['Decision']={'type':'object','required':['decision_no','decision_on','document_id','reason','meeting_date','members_count','attendees_count','votes_for','votes_against','chair_vote_for','outcome'],'properties':{'decision_no':{'type':'string'},'decision_on':{'type':'string','format':'date'},'document_id':uuid,'reason':{'type':'string'},'meeting_date':{'type':'string','format':'date'},'members_count':{'type':'integer','minimum':1},'attendees_count':{'type':'integer','minimum':1},'votes_for':{'type':'integer','minimum':0},'votes_against':{'type':'integer','minimum':0},'chair_vote_for':{'type':'boolean'},'outcome':{'type':'string','enum':['onay','ret','iade','askiya_alma','fesih']}}}
def ref(name): return {'$ref':'#/components/schemas/'+name}
def response(schema=None,desc='Başarılı işlem'):
    return {'description':desc,'content':{'application/json':{'schema':schema or {'type':'object','additionalProperties':True}}}}
def body(schema,content='application/json'): return {'required':True,'content':{content:{'schema':schema}}}
def obj(fields,required=None): return {'type':'object','properties':fields,'required':required or list(fields)}
assignment={'name':'X-Assignment-Id','in':'header','required':True,'schema':uuid,'description':'Aktif kurumsal görev kimliği'}
idempotency={'name':'Idempotency-Key','in':'header','required':True,'schema':{'type':'string','maxLength':150},'description':'Kullanıcı, görev, yol ve içerik kapsamında tekrar koruması'}
version={'type':'integer','minimum':1}
text={'type':'string'}
reason_version=obj({'version':version,'reason':text})
requests={
 '/accounts':obj({'name':text,'email':{'type':'string','format':'email'},'password':dict(text,minLength=12),'password_confirmation':text}),
 '/accounts/{id}':obj({'name':text,'email':{'type':'string','format':'email'},'active':{'type':'boolean'},'password':dict(text,nullable=True,minLength=12),'password_confirmation':dict(text,nullable=True),'reason':text},['name','email','active','reason']),
 '/auth/login':obj({'email':{'type':'string','format':'email'},'password':text,'otp':dict(text,nullable=True,pattern='^[0-9]{6}$')},['email','password']),
 '/auth/forgot-password':obj({'email':{'type':'string','format':'email'}}),
 '/auth/reset-password':obj({'email':{'type':'string','format':'email'},'token':text,'password':text,'password_confirmation':text}),
 '/applications/{id}/preferences':obj({'version':version,'preferences':{'type':'array','minItems':1,'items':obj({'offer_id':uuid,'reachable':{'type':'boolean'},'one_way_minutes':{'type':'number','minimum':0}})}}),
 '/applications/{id}/submit-preferences':obj({'version':version,'revision':version}),
 '/applications/{id}/verify-transport':obj({'version':version,'revision':version,'reason':text}),
 '/matching-runs':obj({'policy_id':uuid}),
 '/placements/{id}/review':obj({'version':version,'offer_id':uuid,'reason':text}),
 '/publications':obj({'term_id':uuid,'decision_document_id':uuid,'targets':{'type':'array','items':obj({'type':{'type':'string','enum':['placements','success_results']},'id':uuid})}}),
 '/appeals':obj({'target_type':{'type':'string','enum':['placements','success_results']},'target_id':uuid,'reason':text,'document_id':dict(uuid,nullable=True)},['target_type','target_id','reason']),
 '/imports/{id}/approve':reason_version,
 '/assignments/{id}/revoke':reason_version,
 '/protocols/{id}/renew':reason_version,
 '/accounts/{id}/deactivate':obj({'reason':text}),
 '/placements/{id}/online-permission':{'allOf':[ref('Decision'),obj({'version':version})]},
 '/placements/{id}/online-proposal':{'allOf':[ref('Decision'),obj({'version':version})]},
 '/board-decisions/{resource}/{id}':{'allOf':[ref('Decision'),obj({'version':version})]},
 '/students/{id}/confirm-profile':obj({'version':version,'phone':dict(text,nullable=True),'address':dict(text,nullable=True),'iban':dict(text,nullable=True)},['version']),
 '/directorate-approvals/{resource}/{id}':obj({'version':version,'document_id':uuid,'reason':text}),
 '/change-requests/{id}/credits':obj({'version':version,'credited_minutes':{'type':'integer','minimum':0,'maximum':36000},'credited_outcomes':{'type':'array','items':obj({'outcome_id':uuid,'evidence':text})},'reason':text,'directorate_document_id':dict(uuid,nullable=True)},['version','credited_minutes','credited_outcomes','reason']),
}
paths={}
def operation(path,method,tag,req=None,result=None,public=False,multipart=False,summary=None):
    parameters=[{'name':k,'in':'path','required':True,'schema':({'type':'integer'} if path.startswith('/accounts/') else uuid) if k=='id' else text} for k in re.findall(r'\{([^}]+)\}',path)]
    if not public and path not in ['/auth/me','/auth/logout','/schema','/forms']: parameters.append(assignment)
    if method in ['post','put','patch'] and not path.startswith('/auth/'): parameters.append(idempotency)
    op={'tags':[tag],'summary':summary or path,'operationId':method+'_'+re.sub('[^a-zA-Z0-9]+','_',path).strip('_'),'parameters':parameters,'responses':{'200':response(result),'401':response(ref('Error'),'Kimlik doğrulama gerekli'),'403':response(ref('Error'),'Görev/kapsam yetkisi yok'),'404':response(ref('Error'),'Kayıt bulunamadı'),'409':response(ref('Error'),'Durum, sürüm, kontenjan veya tekrar uyuşmazlığı'),'422':response(ref('Error'),'Alan/iş kuralı doğrulaması'),'503':response(ref('Error'),'Kurumsal entegrasyon yapılandırılmamış')},'security':[] if public else [{'bearerAuth':[]}]}
    if method=='post' and (path.startswith('/resources/') or path in ['/documents','/accounts']): op['responses']['201']=response(result,'Kayıt oluşturuldu')
    if path=='/matching-runs':op['responses']['202']=response(obj({'run_id':uuid,'state':text}),'Eşleştirme kuyruğa alındı')
    if req: op['requestBody']=body(req,'multipart/form-data' if multipart else 'application/json')
    if method=='get' and path.startswith('/resources/') and '{id}' not in path: op['parameters']+=[{'name':k,'in':'query','schema':uuid} for k in contexts]+[{'name':'limit','in':'query','schema':{'type':'integer','minimum':1,'maximum':100,'default':25}},{'name':'cursor','in':'query','schema':uuid}]
    paths.setdefault(path,{})[method]=op
for name,spec in resources.items():
    path='/resources/'+name
    operation(path,'get',name,result=obj({'items':{'type':'array','items':ref(name)},'next_cursor':dict(uuid,nullable=True),'total':{'type':'integer'}}))
    operation(path+'/{id}','get',name,result=ref(name))
    operation(path+'/{id}/history','get',name,result={'type':'array','items':{'type':'object'}})
    if name not in internal:
        operation(path,'post',name,ref(name+'Input'),ref(name))
        operation(path+'/{id}','put',name,{'allOf':[ref(name+'Input'),obj({'version':version})]},ref(name))
    if spec['states']:
        operation(path+'/{id}/transition','post',name,obj({'version':version,'state':{'type':'string','enum':sorted({v for states in spec['states'].values() for v in states})}},['version','state']),ref(name))
        paths[path+'/{id}/transition']['post']['description']='Karar gerektiren geçişlerde Decision alanları da gönderilir. Uygun görevi sunucu belirler; create yetkisi karar yetkisi değildir. Belgeler temiz taranmış olmalıdır.'
        paths[path+'/{id}/transition']['post']['x-decision-schema']=ref('Decision')
for route in routes:
    uri=route['uri']
    if not uri.startswith('api/v1/') or 'resources/{resource}' in uri:continue
    path='/'+uri.removeprefix('api/v1/')
    for method in route['method'].lower().split('|'):
        if method=='head':continue
        request=requests.get(path)
        multipart=path in ['/documents','/imports/preview']
        if multipart:
            fields={'file':{'type':'string','format':'binary'}}
            fields.update({'institution_id':uuid} if path=='/imports/preview' else {'target_type':dict(text,enum=list(resources)),'target_id':uuid,'classification':dict(text,enum=['kurum_ici','gizli','saglik','disiplin']),'retention_start_event':text})
            request=obj(fields)
        operation(path,method,path.split('/')[1],request,public=path in ['/auth/login','/auth/forgot-password','/auth/reset-password'],multipart=multipart)
        if path=='/exports/{resource}':paths[path][method]['parameters'].append({'name':'format','in':'query','required':True,'schema':{'type':'string','enum':['csv','xlsx','pdf']}})
        if path=='/reports/outcomes':paths[path][method]['parameters'].append({'name':'term_id','in':'query','required':True,'schema':uuid})
        if path=='/documents/{id}/download':paths[path][method]['responses']['200']={'description':'Yetkili, temiz taranmış özel dosya','content':{'application/octet-stream':{'schema':{'type':'string','format':'binary'}}}}
spec={'openapi':'3.0.3','info':{'title':'3+1 MUE Yönetim Backend API','version':'1.0.0','description':'Laravel yetkili sürümlü kayıt ve süreç API sözleşmesi. Koşullu iş kuralları API.md, KAPSAM.md ve resource-manifest.json ile birlikte okunur. Kurumsal karar ve entegrasyonlar otomatik varsayılmaz.'},'servers':[{'url':'http://127.0.0.1:8088/api/v1','description':'Yerel geliştirme'}],'paths':paths,'components':{'securitySchemes':{'bearerAuth':{'type':'http','scheme':'bearer','bearerFormat':'Sanctum'}},'schemas':schemas}}
(root/'docs/openapi.json').write_text(json.dumps(spec,ensure_ascii=False,indent=2),encoding='utf-8')
print('OpenAPI paths:',len(paths),'typed resources:',len(resources))
