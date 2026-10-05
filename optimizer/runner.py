"""Frozen JSON in, recommendation JSON out. No database or network access."""
import hashlib,json,sys,time
from collections import defaultdict
from ortools.sat.python import cp_model
VERSION='mue-cpsat-1.0'
def solve(data):
    started=time.monotonic(); limit=data['time_limit_seconds']
    model=cp_model.CpModel()
    students={s['id']:s for s in data['students'] if not s.get('fixed_offer_id')}
    offers={o['id']:o for o in data['offers']}
    pairs=sorted([p for p in data['candidates'] if p['eligible'] and p['student_id'] in students],key=lambda p:(p['student_id'],p['offer_id']))
    x=[model.new_bool_var(f'x_{i}') for i in range(len(pairs))]
    by_student=defaultdict(list); by_offer=defaultdict(list); by_site=defaultdict(list); by_trainer=defaultdict(list)
    for i,p in enumerate(pairs):
        o=offers[p['offer_id']]; by_student[p['student_id']].append(x[i]); by_offer[p['offer_id']].append(x[i]); by_site[o['site_id']].append(x[i]); by_trainer[o['trainer_id']].append(x[i])
    for sid in students: model.add(sum(by_student[sid])<=1)
    for oid,o in offers.items(): model.add(sum(by_offer[oid])<=o['capacity'])
    for site in data['sites']:
        if site['capacity'] is not None: model.add(sum(by_site[site['id']])<=site['capacity'])
    for trainer in data['trainers']: model.add(sum(by_trainer[trainer['id']])<=trainer['capacity'])
    objectives=[sum(x),sum(round(p['score']*10000)*x[i] for i,p in enumerate(pairs)),sum(round(students[p['student_id']]['gpa']/4*10000)*x[i] for i,p in enumerate(pairs))]
    order=sorted(range(len(x)),key=lambda i:hashlib.sha256((data['seed']+':'+pairs[i]['student_id']+':'+pairs[i]['offer_id']).encode()).hexdigest())
    # Exact deterministic lexicographic bit sequence in safe 40-bit objective chunks.
    for offset in range(0,len(order),40):
        chunk=order[offset:offset+40]; objectives.append(sum((1<<(len(chunk)-j-1))*x[i] for j,i in enumerate(chunk)))
    phases=[]; chosen=[]; status='UNKNOWN'; previous=[]; has_solution=False
    for stage,obj in enumerate(objectives):
        remaining=limit-(time.monotonic()-started)
        if remaining<=0: status='FEASIBLE' if has_solution else 'UNKNOWN'; break
        model.maximize(obj); solver=cp_model.CpSolver(); solver.parameters.max_time_in_seconds=remaining
        solver.parameters.num_search_workers=1; solver.parameters.random_seed=int(hashlib.sha256(data['seed'].encode()).hexdigest()[:7],16)
        result=solver.solve(model); current=solver.status_name(result)
        if result not in (cp_model.OPTIMAL,cp_model.FEASIBLE):
            status='FEASIBLE' if has_solution else current; break
        chosen=[i for i in range(len(x)) if solver.value(x[i])]; previous=chosen[:]; status=current; has_solution=True
        value=int(round(solver.objective_value)); phases.append({'stage':stage+1,'status':current,'value':value,'best_bound':solver.best_objective_bound,'gap':abs(solver.best_objective_bound-value)})
        if result!=cp_model.OPTIMAL: break
        model.add(obj==value)
    assignments=[{'student_id':pairs[i]['student_id'],'offer_id':pairs[i]['offer_id'],'explanation':pairs[i]} for i in chosen]
    placed={p['student_id'] for p in assignments}; unplaced=[]
    candidates_by_student=defaultdict(list)
    for p in data['candidates']: candidates_by_student[p['student_id']].append(p)
    for sid in students:
        if sid in placed: continue
        candidates=candidates_by_student[sid]
        reasons=sorted({r for p in candidates for r in p['reasons']})
        if any(p['eligible'] for p in candidates): reasons=['Uygun tercihlerin kontenjanı dolu veya daha yüksek öncelikli öneri seçildi.']
        if not candidates: reasons=['Kesinleşmiş uygun tercih bulunmuyor.']
        unplaced.append({'student_id':sid,'reasons':reasons})
    return {'algorithm_version':VERSION,'solver_status':status,'proven_optimal':status=='OPTIMAL' and len(phases)==len(objectives),'objective_values':phases,'assignments':assignments,'unplaced':unplaced,'elapsed_seconds':round(time.monotonic()-started,4)}
if __name__=='__main__':
    sys.stdin.reconfigure(encoding='utf-8'); sys.stdout.reconfigure(encoding='utf-8')
    try:
        data=json.load(sys.stdin); result=solve(data); print(json.dumps(result,ensure_ascii=False))
    except Exception as exc:
        print(json.dumps({'solver_status':'ERROR','message':'Eşleştirme çalışması başarısız.','error_type':type(exc).__name__},ensure_ascii=False)); sys.exit(1)
