"""Explicitly local, synthetic demo HTTP QA. Never prints credentials or tokens."""
import argparse
import http.cookiejar
import json
import re
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
OUT = ROOT / "backend/storage/logs/full-qa-20261007"
sys.stdout.reconfigure(line_buffering=True)

class Client:
    def __init__(self, base):
        self.base = base
        self.cookies = http.cookiejar.CookieJar()
        self.http = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.cookies))
        self.csrf = None
    def request(self, path, data=None, method="GET", headers=None):
        h = dict(headers or {})
        if data is not None:
            if h.get("Content-Type") == "application/x-www-form-urlencoded":
                data = urllib.parse.urlencode(data).encode()
            else:
                h.setdefault("Content-Type", "application/json")
                data = json.dumps(data).encode()
        if path.startswith(("/api/", "/portal-api/")):
            h.setdefault("Accept", "application/json")
        if self.csrf and method != "GET":
            h.setdefault("X-CSRF-TOKEN", self.csrf)
        req = urllib.request.Request(self.base + path, data=data, method=method, headers=h)
        start = time.monotonic()
        try:
            res = self.http.open(req, timeout=40)
        except urllib.error.HTTPError as e:
            res = e
        body = res.read().decode("utf-8", errors="replace")
        try: parsed = json.loads(body)
        except ValueError: parsed = None
        csrf = re.search(r'<meta name="csrf-token" content="([^"]+)"', body)
        if csrf: self.csrf = csrf.group(1)
        return res.status, body, parsed, time.monotonic() - start, res.geturl()

def main():
    p = argparse.ArgumentParser()
    p.add_argument("--base", default="http://127.0.0.1:8088")
    a = p.parse_args()
    assert urllib.parse.urlparse(a.base).hostname in ("127.0.0.1", "localhost")
    OUT.mkdir(parents=True, exist_ok=True)
    accounts = json.loads((ROOT / "backend/storage/app/private/demo-credentials.json").read_text(encoding="utf-8"))["accounts"]
    results, clients, inventory = [], {}, {}
    def check(role, label, response, expected=(200,)):
        status, body, data, elapsed, url = response
        ok = status in expected
        results.append({"role":role,"check":label,"status":status,"ok":ok,"seconds":round(elapsed,4),"code":data.get("code") if isinstance(data,dict) else None})
        if not ok: print(role, label, status, (data or {}).get("code") if isinstance(data,dict) else "HTML")
        return data
    # Directorate first supplies record IDs for cross-role IDOR attempts.
    accounts.sort(key=lambda x: x["role"] != "mudur")
    for acc in accounts:
        role = acc["role"]
        c = Client(a.base)
        check(role,"login form",c.request("/giris"))
        response = c.request("/giris", acc | {"_token":c.csrf}, "POST", {"Content-Type":"application/x-www-form-urlencoded"})
        check(role,"web login without OTP",response)
        assert "/panel" in response[4], role + " did not reach portal"
        match = re.search(r'<script[^>]+id="portal-boot"[^>]*>(.*?)</script>',response[1],re.S)
        assert match, role + " missing boot"
        boot = json.loads(match.group(1))
        assert boot["demo"] is True, "Only explicitly synthetic demo accounts are allowed"
        c.boot = boot
        clients[role] = c
        inventory[role] = {}
        for typ, spec in boot["resources"].items():
            page = c.request("/panel/" + typ)
            check(role,"page:"+typ,page)
            assert 'id="workspace"' in page[1], typ + " missing workspace"
            response = c.request("/portal-api/v1/resources/" + typ + "?limit=1")
            payload = check(role,"list:"+typ,response)
            if isinstance(payload,dict) and "items" in payload:
                inventory[role][typ] = {"total":payload["total"],"items":payload["items"]}
                if payload["items"]:
                    rid = payload["items"][0]["id"]
                    check(role,"detail:"+typ,c.request("/portal-api/v1/resources/"+typ+"/"+rid))
                    check(role,"history:"+typ,c.request("/portal-api/v1/resources/"+typ+"/"+rid+"/history"))
                    check(role,"detail-page:"+typ,c.request("/panel/"+typ+"/"+rid))
                if payload.get("next_cursor"):
                    second = check(role,"pagination:"+typ,c.request("/portal-api/v1/resources/"+typ+"?limit=1&cursor="+payload["next_cursor"]))
                    assert not ({r["id"] for r in payload["items"]} & {r["id"] for r in second["items"]})
            check(role,"filter:"+typ,c.request("/portal-api/v1/resources/"+typ+"?q=QA_NO_MATCH_9b2b&state=QA_NO_STATE"))
            if spec["readable"] and role in ("mudur","mue_komisyon","bolum_komisyon","akademik_danisman"):
                check(role,"export:"+typ,c.request("/portal-api/v1/exports/"+typ+"?format=csv"), (200,) if role != "akademik_danisman" else (403,))
        for extra in ("ek",):
            check(role,"special-page:"+extra,c.request("/panel/"+extra))
        check(role,"special-page:preferences",c.request("/panel/preferences"),(200,) if "preferences" in boot["resources"] else (403,))
        if role in ("mudur","mue_komisyon","bolum_komisyon"):
            for extra in ("matching","reports","imports"):
                check(role,"special-page:"+extra,c.request("/panel/"+extra))
        if role == "sistem_yoneticisi": check(role,"accounts",c.request("/panel/accounts"))
        if role != "sistem_yoneticisi": check(role,"forbidden accounts",c.request("/panel/accounts"),(403,))
        check(role,"schema",c.request("/portal-api/v1/schema"))
        check(role,"forms",c.request("/portal-api/v1/forms"))
        check(role,"overview",c.request("/portal-api/v1/overview"))
        check(role,"assignment selection",c.request("/gorev"))
        check(role,"select own assignment",c.request("/gorev",{"assignment_id":boot["assignment"]["id"]},"POST",{"Content-Type":"application/x-www-form-urlencoded"}))
        check(role,"malformed UUID detail",c.request("/portal-api/v1/resources/students/not-a-uuid"),(404,422,403))
        check(role,"malformed UUID filter",c.request("/portal-api/v1/resources/students?student_id=not-a-uuid"),(422,))
        check(role,"malformed UUID cursor",c.request("/portal-api/v1/resources/students?cursor=not-a-uuid"),(422,))
        print(role,"checked",len(boot["resources"]),"resources")
    # Foreign demo student/company fixture is supplied by qa_seed.php when present.
    fixture_path = OUT / "foreign-fixture.json"
    if fixture_path.exists():
        f = json.loads(fixture_path.read_text(encoding="utf-8"))
        for role in ("ogrenci","akademik_danisman","egitici","isletme_yetkilisi","sistem_yoneticisi"):
            c=clients[role]
            for typ in ("students","companies","documents"):
                check(role,"foreign detail:"+typ,c.request("/portal-api/v1/resources/"+typ+"/"+f[typ]),(403,404))
                check(role,"foreign history:"+typ,c.request("/portal-api/v1/resources/"+typ+"/"+f[typ]+"/history"),(403,404))
                check(role,"foreign portal:"+typ,c.request("/panel/"+typ+"/"+f[typ]),(403,404))
            check(role,"foreign download",c.request("/portal-api/v1/documents/"+f["documents"]+"/download"),(403,404))
    c=clients["ogrenci"]
    # Real CSRF middleware runs over HTTP, unlike Laravel's testing bypass.
    saved=c.csrf;c.csrf=None
    check("ogrenci","missing CSRF",c.request("/portal-api/v1/appeals",{},"POST",{"Idempotency-Key":str(uuid.uuid4())}),(419,))
    c.csrf=saved
    director = clients["mudur"].boot["assignment"]["id"]
    check("ogrenci","foreign assignment",c.request("/gorev",{"assignment_id":director},"POST",{"Content-Type":"application/x-www-form-urlencoded"}),(403,))
    for role,c in clients.items():
        check(role,"logout",c.request("/cikis",{},"POST",{"Content-Type":"application/x-www-form-urlencoded"}))
        response=c.request("/panel")
        check(role,"protected after logout",response)
        assert "/giris" in response[4]
        check(role,"portal API after logout",c.request("/portal-api/v1/resources/students"),(401,))
    summary={"base":a.base,"checks":len(results),"passed":sum(x["ok"] for x in results),"failed":[x for x in results if not x["ok"]],"results":results,"inventory":inventory}
    (OUT/"http-results.json").write_text(json.dumps(summary,ensure_ascii=False,indent=2),encoding="utf-8")
    print(json.dumps({"checks":summary["checks"],"passed":summary["passed"],"failed_count":len(summary["failed"])},ensure_ascii=False))
    raise SystemExit(bool(summary["failed"]))

if __name__ == "__main__": main()
