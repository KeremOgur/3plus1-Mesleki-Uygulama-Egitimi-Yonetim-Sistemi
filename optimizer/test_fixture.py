import unittest
from runner import solve
class FixtureTest(unittest.TestCase):
    def test_lexicographic_three_student_example(self):
        d={'seed':'fixture','time_limit_seconds':10,'students':[{'id':'O1','gpa':3.2},{'id':'O2','gpa':2.8},{'id':'O3','gpa':3.5}], 'offers':[{'id':'A','site_id':'A','trainer_id':'T1','capacity':1},{'id':'B','site_id':'B','trainer_id':'T2','capacity':1}], 'sites':[{'id':'A','capacity':1},{'id':'B','capacity':1}], 'trainers':[{'id':'T1','capacity':5},{'id':'T2','capacity':5}], 'candidates':[]}
        for sid,oid,score in [('O1','A',100),('O1','B',92.5),('O2','A',100),('O3','B',100)]:d['candidates'].append({'student_id':sid,'offer_id':oid,'eligible':True,'score':score,'reasons':[]})
        a=solve(d); b=solve(d)
        self.assertEqual(a['assignments'],b['assignments']);self.assertEqual(a['objective_values'],b['objective_values']);self.assertTrue(a['proven_optimal'])
        self.assertEqual({(p['student_id'],p['offer_id']) for p in a['assignments']},{('O1','A'),('O3','B')})
if __name__=='__main__':unittest.main()
