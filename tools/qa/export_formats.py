"""Check real authorized CSV/XLSX/PDF downloads using synthetic demo data."""
import io,json,urllib.request,zipfile
from full_system_http import Client,ROOT,OUT

account=next(a for a in json.loads((ROOT/'backend/storage/app/private/demo-credentials.json').read_text())['accounts'] if a['role']=='mudur')
client=Client('http://127.0.0.1:8088');assert client.request('/giris')[0]==200
login=client.request('/giris',account|{'_token':client.csrf},'POST',{'Content-Type':'application/x-www-form-urlencoded'})
assert login[0]==200 and '/panel' in login[4]
results=[]
for fmt in ('csv','xlsx','pdf'):
    request=urllib.request.Request(client.base+'/portal-api/v1/exports/companies?format='+fmt,headers={'Accept':'application/octet-stream'})
    with client.http.open(request,timeout=40) as response:
        content=response.read();mime=response.headers['Content-Type'];assert response.status==200
    if fmt=='csv':
        assert content.startswith(b'\xef\xbb\xbf') and ',' in content.decode('utf-8-sig')
        assert mime.startswith('text/csv')
    elif fmt=='xlsx':
        with zipfile.ZipFile(io.BytesIO(content)) as workbook:assert 'xl/workbook.xml' in workbook.namelist() and 'xl/worksheets/sheet1.xml' in workbook.namelist()
        assert mime.startswith('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
    else:
        assert content.startswith(b'%PDF-') and b'%%EOF' in content[-1024:]
        assert mime.startswith('application/pdf')
    (OUT/('authorized-company-export.'+fmt)).write_bytes(content)
    results.append({'format':fmt,'status':200,'mime':mime,'bytes':len(content),'valid_container':True})
assert client.request('/cikis',{'_token':client.csrf},'POST',{'Content-Type':'application/x-www-form-urlencoded'})[0]==200
(OUT/'export-formats.json').write_text(json.dumps(results,indent=2),encoding='utf-8')
print('Authorized CSV/XLSX/PDF exports: passed')
