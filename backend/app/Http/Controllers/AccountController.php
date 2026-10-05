<?php
namespace App\Http\Controllers;
use App\Domain\{Records,ScopeAccess,DomainError};
use App\Models\User;
use Illuminate\Http\Request;
class AccountController extends ResourceController
{
    private function technical(): void
    { if(app(ScopeAccess::class)->assignment(auth()->user())->role!=='sistem_yoneticisi') throw new DomainError('FORBIDDEN_SCOPE','Teknik hesap yönetimi yetkisi gerekir.',403); }
    public function createAccount(Request $r)
    {
        $this->technical();
        $d=$r->validate(['name'=>'required|string|max:250','email'=>'required|email|unique:users','password'=>['required','confirmed',\Illuminate\Validation\Rules\Password::min(12)->mixedCase()->numbers()->symbols()]]);
        return $this->transaction($r,function($a)use($d){$u=User::create($d); \Illuminate\Support\Facades\DB::table('audit_events')->insert(['actor_id'=>auth()->id(),'assignment_id'=>$a->id,'institution_id'=>$a->institution_id,'action'=>'ACCOUNT_CREATE','target_type'=>'users','reason'=>'Teknik hesap oluşturma','occurred_at'=>now()]);return response()->json($u->only('id','name','email'),201);});
    }
    public function deactivate(Request $r,int $id)
    {
        $this->technical();$r->validate(['reason'=>'required|string']);
        return $this->transaction($r,function($a)use($r,$id){$u=User::findOrFail($id);$u->active=false;$u->save();$u->tokens()->delete();\Illuminate\Support\Facades\DB::table('audit_events')->insert(['actor_id'=>auth()->id(),'assignment_id'=>$a->id,'institution_id'=>$a->institution_id,'action'=>'ACCOUNT_DEACTIVATE','target_type'=>'users','reason'=>$r->reason,'occurred_at'=>now()]);return ['message'=>'Hesap pasife alındı; oturum erişimi kaldırıldı.'];});
    }
    public function updateAccount(Request $r,int $id)
    {
        $this->technical();$d=$r->validate(['name'=>'required|string|max:250','email'=>'required|email|unique:users,email,'.$id,'active'=>'required|boolean','password'=>['nullable','confirmed',\Illuminate\Validation\Rules\Password::min(12)->mixedCase()->numbers()->symbols()],'reason'=>'required|string']);
        return $this->transaction($r,function($a)use($d,$id){$u=User::findOrFail($id);if(empty($d['password']))unset($d['password']);unset($d['reason']);$u->fill($d)->save();$u->tokens()->delete();\Illuminate\Support\Facades\DB::table('audit_events')->insert(['actor_id'=>auth()->id(),'assignment_id'=>$a->id,'institution_id'=>$a->institution_id,'action'=>'ACCOUNT_UPDATE','target_type'=>'users','reason'=>'Yetkili hesap düzenlemesi','occurred_at'=>now()]);return $u->only('id','name','email','active');});
    }
}
