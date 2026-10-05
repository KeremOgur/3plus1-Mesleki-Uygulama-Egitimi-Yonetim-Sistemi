"""Synthetic sparse 2,000 student / 500 workplace benchmark; no business DB access."""
import json,time
from runner import solve
if __name__=='__main__':
    d=dict(seed='audit-scale-2026',time_limit_seconds=90,students=[dict(id=f'S{i:04}',gpa=2+(i%200)/100) for i in range(2000)],offers=[dict(id=f'O{i:03}',site_id=f'SITE{i}',trainer_id=f'T{i}',capacity=4) for i in range(500)],sites=[dict(id=f'SITE{i}',capacity=4) for i in range(500)],trainers=[dict(id=f'T{i}',capacity=5) for i in range(500)],candidates=[])
    for i in range(2000):
        for rank in range(4):d['candidates'].append(dict(student_id=f'S{i:04}',offer_id=f'O{(i//4+rank)%500:03}',score=75*(4-rank)/4+25,eligible=True,reasons=[]))
    start=time.monotonic();r=solve(d);elapsed=time.monotonic()-start
    summary=dict(students=2000,workplaces=500,candidates=len(d['candidates']),placed=len(r['assignments']),solver_status=r['solver_status'],proven_optimal=r['proven_optimal'],elapsed_seconds=round(elapsed,4),objective_phases=len(r['objective_values']))
    assert elapsed<120,summary
    assert len(r['assignments'])==2000,summary
    print(json.dumps(summary,ensure_ascii=False))
