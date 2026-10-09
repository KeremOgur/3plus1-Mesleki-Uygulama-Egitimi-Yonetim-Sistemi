"""Real local HTTP checks for MFA, bearer scope, file quarantine and limits."""
from full_system_http import Client,ROOT,OUT
import base64,hashlib,hmac,io,json,struct,time,uuid,zipfile,argparse,urllib.parse

parser=argparse.ArgumentParser();parser.add_argument('--base',default='http://127.0.0.1:8088');args=parser.parse_args()
base=args.base
assert urllib.parse.urlparse(base).hostname in ('127.0.0.1','localhost')
c=Client(base);results=[]
def check(label,res,expected):
    status,body,data,elapsed,url=res
    ok=status in expected
    results.append({'check':label,'status':status,'ok':ok,'code':data.get('code') if isinstance(data,dict) else None})
    (OUT/'http-security.json').write_text(json.dumps({'checks':len(results),'passed':sum(x['ok'] for x in results),'results':results},ensure_ascii=False,indent=2),encoding='utf-8')
    assert ok,(label,status,results[-1]['code'])
    return data
def code(secret,counter):
    digest=hmac.new(base64.b32decode(secret),struct.pack('>Q',counter),hashlib.sha1).digest();offset=digest[-1]&15
    return str((struct.unpack('>I',digest[offset:offset+4])[0]&0x7fffffff)%1000000).zfill(6)
def main():
    # Run before login throttles consume the shared anonymous IP bucket.
    check('SMTP unavailable',c.request('/api/v1/auth/forgot-password',{'email':'qa.smtp@demo.mue.invalid'},'POST'),(503,))
    mfa=json.loads((ROOT/'backend/storage/app/private/qa-mfa-credentials.json').read_text())
    credentials={k:mfa[k] for k in ('email','password')};counter=int(time.time())//30
    check('MFA missing OTP',c.request('/api/v1/auth/login',credentials,'POST'),(401,))
    invalid=code(mfa['secret'],counter);invalid=str((int(invalid)+1)%1000000).zfill(6)
    check('MFA wrong OTP',c.request('/api/v1/auth/login',credentials|{'otp':invalid},'POST'),(401,))
    check('MFA expired OTP',c.request('/api/v1/auth/login',credentials|{'otp':code(mfa['secret'],counter-3)},'POST'),(401,))
    otp=code(mfa['secret'],int(time.time())//30)
    auth=check('MFA valid OTP',c.request('/api/v1/auth/login',credentials|{'otp':otp},'POST'),(200,))
    headers={'Authorization':'Bearer '+auth['token']}
    check('MFA code replay',c.request('/api/v1/auth/login',credentials|{'otp':otp},'POST'),(401,))
    check('MFA token auth',c.request('/api/v1/auth/me',headers=headers),(200,))
    check('MFA token logout',c.request('/api/v1/auth/logout',{},'POST',headers),(200,))
    check('Revoked bearer',c.request('/api/v1/auth/me',headers=headers),(401,))
    student=json.loads((ROOT/'backend/storage/app/private/demo-credentials.json').read_text())['accounts'][0]
    # Seeder ordering is not relied upon.
    accounts=json.loads((ROOT/'backend/storage/app/private/demo-credentials.json').read_text())['accounts']
    student=next(x for x in accounts if x['role']=='ogrenci')
    auth=check('API demo without MFA',c.request('/api/v1/auth/login',{'email':student['email'],'password':student['password']},'POST'),(200,))
    headers={'Authorization':'Bearer '+auth['token']}
    assignment=auth['assignments'][0]
    check('Missing API assignment',c.request('/api/v1/resources/students',headers=headers),(403,))
    check('Malformed API assignment',c.request('/api/v1/resources/students',headers=headers|{'X-Assignment-Id':'not-a-uuid'}),(403,))
    director_id=json.loads((OUT/'http-baseline-results.json').read_text())['inventory']['mudur']['role_assignments']['items'][0]['id']
    # Any other user's assignment is rejected even if the advertised role is valid.
    if director_id==assignment['id']:
        director_id=str(uuid.uuid4())
    check('Foreign API assignment',c.request('/api/v1/resources/students',headers=headers|{'X-Assignment-Id':director_id}),(403,))
    headers['X-Assignment-Id']=assignment['id']
    own=check('Own student bearer scope',c.request('/api/v1/resources/students',headers=headers),(200,))['items'][0]
    foreign=json.loads((OUT/'foreign-fixture.json').read_text())
    check('Bearer IDOR',c.request('/api/v1/resources/students/'+foreign['students'],headers=headers),(403,))
    empty=check('Foreign scoped list',c.request('/api/v1/resources/students?student_id='+foreign['students'],headers=headers),(200,));assert empty['total']==0
    for typ in ('students','companies','documents'):
        check('Student forbidden export '+typ,c.request('/api/v1/exports/'+typ+'?format=csv',headers=headers),(403,))
    for iban in ('bad','TR000000000000000000000000'):
        check('HTTP invalid IBAN '+iban[:3],c.request('/api/v1/students/'+own['id']+'/confirm-profile',{'version':own['version'],'iban':iban},'POST',headers|{'Idempotency-Key':str(uuid.uuid4())}),(422,))
    def multipart(filename,content):
        boundary='mueqa'+uuid.uuid4().hex;parts=[]
        for k,v in {'target_type':'students','target_id':own['id'],'classification':'kurum_ici','retention_start_event':'synthetic-qa'}.items():parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n'.encode())
        parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="file"; filename="{filename}"\r\nContent-Type: application/octet-stream\r\n\r\n'.encode()+content+b'\r\n');parts.append(f'--{boundary}--\r\n'.encode())
        import urllib.request,urllib.error
        req=urllib.request.Request(base+'/api/v1/documents',data=b''.join(parts),headers=headers|{'Accept':'application/json','Content-Type':'multipart/form-data; boundary='+boundary,'Idempotency-Key':str(uuid.uuid4())},method='POST')
        start=time.monotonic()
        try:r=c.http.open(req,timeout=40)
        except urllib.error.HTTPError as e:r=e
        body=r.read().decode(errors='replace')
        return r.status,body,json.loads(body),time.monotonic()-start,r.geturl()
    png=base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jG0kAAAAASUVORK5CYII=')
    doc=check('Real multipart valid PNG',multipart('qa-http.png',png),(201,));assert doc['scan_status']=='bekliyor' and 'object_key' not in doc
    check('Unscanned download denied',c.request('/api/v1/documents/'+doc['id']+'/download',headers=headers),(409,))
    for filename,content in [('wrong.exe',png),('wrong.png',b'<?php echo 1;'),('empty.pdf',b'')]:check('Real multipart '+filename,multipart(filename,content),(422,))
    # Wrong content beyond the limit checks request validation with an actual body.
    check('Real multipart >20MB',multipart('oversized.pdf',b'%PDF-1.4\n'+b'0'*(21*1024*1024)),(413,422))
    observed=[]
    for i in range(7):
        res=c.request('/api/v1/auth/login',{'email':'qa.limit@demo.mue.invalid','password':'wrong-synthetic-password'},'POST');observed.append(res[0]);check('Login rate attempt '+str(i+1),res,(401,429))
    assert 429 in observed
    check('Student bearer logout',c.request('/api/v1/auth/logout',{},'POST',headers),(200,))
    (OUT/'http-security.json').write_text(json.dumps({'checks':len(results),'passed':sum(x['ok'] for x in results),'results':results},ensure_ascii=False,indent=2),encoding='utf-8')
    print('HTTP security checks:',len(results),'all passed')
if __name__=='__main__':main()
