from pathlib import Path
root=Path('D:/MUE/backend')
workflow=root/'app/Domain/Workflow.php'
s=workflow.read_text(encoding='utf8')
start=s.index('        $roles=config(')
end=s.index('        $assignment=',start)
rules=s[start:end].replace('$roles=config("domain.$type.write");','$roles=config("domain.$type.write",[]);')
rules+="        if ($type==='weekly_reports' && $to==='iade') $roleList='egitici akademik_danisman';\n        return ['roles'=>explode(' ',$roleList),'decision'=>$decision];\n"
s=s[:start]+"        $rule=$this->rulesFor($type,$to); $roleList=implode(' ',$rule['roles']); $decision=$rule['decision'];\n"+s[end:]
s=s.replace('    public function decision(',"    public function rulesFor(string $type,string $to): array\n    {\n"+rules+"    }\n\n    public function decision(")
needle="        if ($type==='weekly_reports' && $to==='gonderildi'"
pos=s.index(needle)
s=s[:pos]+"        if ($type==='weekly_reports' && in_array($assignment->role,['egitici','akademik_danisman'])) {\n            $field=$assignment->role==='egitici'?'trainer_review':'adviser_review';\n            if(!empty($input['reason'])) $r->$field=$input['reason'];\n        }\n"+s[pos:]
workflow.write_text(s,encoding='utf8')
auth=root/'app/Http/Controllers/AuthController.php'
s=auth.read_text(encoding='utf8')
start=s.index('        $d=$request->validate(')
end=s.index('        $token=',start)
logic=s[start:end]+"        return $u;\n"
s=s[:start]+"        $u=$this->authenticate($request);\n"+s[end:]
s=s.replace('    public function me(',"    public function authenticate(Request $request): User\n    {\n"+logic+"    }\n    public function me(")
auth.write_text(s,encoding='utf8')
print('Shared authentication and workflow permissions extracted without changing the API contract.')
