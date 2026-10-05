from pathlib import Path
import re
root=Path('D:/MUE/backend'); p=root/'.env'
s=p.read_text()
settings={'APP_NAME':'"MUE 3+1"','APP_ENV':'local','APP_DEBUG':'false','APP_URL':'http://127.0.0.1:8088','APP_LOCALE':'tr','APP_FALLBACK_LOCALE':'tr','DB_CONNECTION':'pgsql','DB_HOST':'127.0.0.1','DB_PORT':'55432','DB_DATABASE':'mue','DB_USERNAME':'mue_dev','DB_PASSWORD':'','MATCHING_PYTHON':'D:/MUE/optimizer/.venv/Scripts/python.exe','MATCHING_RUNNER':'D:/MUE/optimizer/runner.py','SESSION_ENCRYPT':'true','SESSION_SECURE_COOKIE':'false','QUEUE_CONNECTION':'database'}
for k,v in settings.items():
 if re.search(r'^#?\s*'+k+r'=',s,re.M): s=re.sub(r'^#?\s*'+k+r'=.*$',k+'='+v,s,flags=re.M)
 else: s+='\n'+k+'='+v
p.write_text(s)
(root/'config/filesystems.php').write_text((root/'config/filesystems.php').read_text().replace("'serve' => true","'serve' => false"))
print('Local environment configured, secrets not printed.')
