"""Final real HTTP smoke against the explicitly synthetic local demo accounts."""
import json,re,uuid
from full_system_http import Client,ROOT,OUT

rows=[]
def check(role,label,res,status=200):
    assert res[0]==status,(role,label,res[0])
    rows.append({'role':role,'check':label,'status':res[0],'seconds':round(res[3],4)})
    return res[2]
accounts=json.loads((ROOT/'backend/storage/app/private/demo-credentials.json').read_text())['accounts']
for account in accounts:
    role=account['role'];client=Client('http://127.0.0.1:8088')
    check(role,'login page',client.request('/giris'))
    login=client.request('/giris',account|{'_token':client.csrf},'POST',{'Content-Type':'application/x-www-form-urlencoded'})
    check(role,'web login without OTP',login);assert '/panel' in login[4]
    boot=json.loads(re.search(r'<script[^>]+id="portal-boot"[^>]*>(.*?)</script>',login[1],re.S).group(1));assert boot['demo']
    check(role,'session refresh',client.request('/panel'))
    check(role,'schema',client.request('/portal-api/v1/schema'))
    check(role,'EK catalogue',client.request('/portal-api/v1/forms'))
    check(role,'user names',client.request('/portal-api/v1/reference-users'))
    check(role,'EK page',client.request('/panel/ek'))
    typ='institutions' if role=='sistem_yoneticisi' else 'placements'
    check(role,'scoped list',client.request('/portal-api/v1/resources/'+typ+'?limit=1'))
    if role=='sistem_yoneticisi':
        for bad_id in ('not-an-integer','-1','9223372036854775808'):
            check(role,'invalid account ID '+bad_id,client.request('/portal-api/v1/accounts/'+bad_id+'/deactivate',{'reason':'Sentetik QA'},'POST',{'Idempotency-Key':str(uuid.uuid4())}),404)
        check(role,'valid numeric account ID missing reason',client.request('/portal-api/v1/accounts/'+str(boot['user']['id'])+'/deactivate',{},'POST',{'Idempotency-Key':str(uuid.uuid4())}),422)
    if role=='mudur':
        for field in ('q','state','form_code'):
            check(role,'invalid text filter '+field,client.request('/portal-api/v1/resources/students?'+field+'[]=invalid'),422)
    check(role,'logout',client.request('/cikis',{'_token':client.csrf},'POST',{'Content-Type':'application/x-www-form-urlencoded'}))
    check(role,'closed session',client.request('/portal-api/v1/schema'),401)
check('anonymous','health',Client('http://127.0.0.1:8088').request('/up'))
(OUT/'final-smoke.json').write_text(json.dumps({'checks':len(rows),'passed':len(rows),'results':rows},ensure_ascii=False,indent=2),encoding='utf-8')
print('Final localhost smoke:',len(rows),'passed')
