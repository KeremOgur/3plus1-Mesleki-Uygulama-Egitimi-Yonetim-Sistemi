import itertools,random,unittest
from unittest.mock import patch
from runner import solve

def data(students=3):
    return dict(seed='audit-seed',time_limit_seconds=10,students=[dict(id=f'S{i}',gpa=i+1) for i in range(students)],offers=[dict(id='A',site_id='SITE',trainer_id='T',capacity=1),dict(id='B',site_id='SITE',trainer_id='T',capacity=1)],sites=[dict(id='SITE',capacity=2)],trainers=[dict(id='T',capacity=5)],candidates=[])

def pair(s,o,score=100,eligible=True):
    return dict(student_id=s,offer_id=o,score=score,eligible=eligible,reasons=[] if eligible else ['Zorunlu uygunluk yok.'])

class ComplianceTest(unittest.TestCase):
    def test_placement_count_precedes_score_and_gpa(self):
        d=data(2);d['candidates']=[pair('S0','A',100),pair('S0','B',0),pair('S1','A',0)]
        a=solve(d);self.assertEqual({(p['student_id'],p['offer_id']) for p in a['assignments']},{('S0','B'),('S1','A')})
    def test_score_precedes_gpa(self):
        d=data(2);d['offers']=d['offers'][:1];d['candidates']=[pair('S0','A',100),pair('S1','A',99)]
        self.assertEqual(solve(d)['assignments'][0]['student_id'],'S0')
    def test_gpa_breaks_equal_score_without_entering_score(self):
        d=data(2);d['offers']=d['offers'][:1];d['candidates']=[pair('S0','A'),pair('S1','A')]
        a=solve(d);self.assertEqual(a['assignments'][0]['student_id'],'S1');self.assertEqual(a['objective_values'][1]['value'],1000000)
    def test_shared_site_trainer_and_ineligible_pairs(self):
        for axis in ['sites','trainers']:
            d=data();d[axis][0]['capacity']=1;d['candidates']=[pair('S0','A'),pair('S1','B'),pair('S2','B',1000,False)]
            a=solve(d);self.assertEqual(len(a['assignments']),1);self.assertNotEqual(a['assignments'][0]['student_id'],'S2')
    def test_seeded_ties_are_repeatable_and_input_order_independent(self):
        d=data(2);d['students'][1]['gpa']=1;d['candidates']=[pair(s,o) for s in ['S0','S1'] for o in ['A','B']]
        a=solve(d);d['candidates'].reverse();b=solve(d);self.assertEqual(a['assignments'],b['assignments']);self.assertEqual(a['objective_values'],b['objective_values'])
    def test_fixed_placement_is_not_assigned_again(self):
        d=data(2);d['students'][0]['fixed_offer_id']='A';d['offers'][0]['capacity']=0;d['candidates']=[pair('S0','A'),pair('S1','B')]
        a=solve(d);self.assertEqual([p['student_id'] for p in a['assignments']],['S1']);self.assertEqual(a['unplaced'],[])
    def test_zero_assignment_feasible_survives_time_budget(self):
        d=data(1);d['time_limit_seconds']=10
        with patch('runner.time.monotonic',side_effect=[0,0,11,11]):
            a=solve(d)
        self.assertEqual(a['solver_status'],'FEASIBLE');self.assertFalse(a['proven_optimal']);self.assertEqual(a['assignments'],[])
    def test_small_random_instances_match_independent_exhaustive_lex_optimum(self):
        rng=random.Random(2026)
        for _ in range(20):
            d=data();d['sites'][0]['capacity']=rng.randint(1,2);d['trainers'][0]['capacity']=rng.randint(1,2)
            d['candidates']=[pair(s['id'],o['id'],rng.choice([0,50,100]),rng.choice([True,True,False])) for s in d['students'] for o in d['offers']]
            candidates=[p for p in d['candidates'] if p['eligible']];best=(-1,-1,-1)
            for bits in itertools.product([0,1],repeat=len(candidates)):
                chosen=[p for p,b in zip(candidates,bits) if b]
                if len({p['student_id'] for p in chosen})!=len(chosen) or any(sum(p['offer_id']==o['id'] for p in chosen)>o['capacity'] for o in d['offers']) or len(chosen)>min(d['sites'][0]['capacity'],d['trainers'][0]['capacity']):continue
                objective=(len(chosen),sum(round(p['score']*10000) for p in chosen),sum(round(next(s['gpa'] for s in d['students'] if s['id']==p['student_id'])/4*10000) for p in chosen))
                best=max(best,objective)
            result=solve(d);self.assertTrue(result['proven_optimal']);self.assertEqual(tuple(p['value'] for p in result['objective_values'][:3]),best)
if __name__=='__main__':unittest.main()
