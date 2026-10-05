<?php
namespace App\Domain;
use App\Models\User;
use Illuminate\Support\Facades\DB;
/** RFC 6238 SHA-1 / six digits / 30 seconds, with one-use counters. */
class Mfa
{
 public const ROLES=['sistem_yoneticisi','mudur','mue_komisyon','bolum_komisyon','program_baskani','belge_gorevlisi'];
 public function required(User $u): bool {return (bool)$u->mfa_confirmed_at || (app()->environment('production') && Records::query('role_assignments')->where('user_id',$u->id)->where('state','aktif')->whereIn('role',self::ROLES)->where('valid_from','<=',now())->where(fn($q)=>$q->whereNull('valid_until')->orWhere('valid_until','>=',now()))->exists());}
 public function secret(): string {$alphabet='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';$s='';for($i=0;$i<32;$i++)$s.=$alphabet[random_int(0,31)];return $s;}
 public function code(string $secret,int $counter): string {
  $alphabet='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';$bits='';foreach(str_split($secret) as $char){$v=strpos($alphabet,$char);if($v===false)throw new \InvalidArgumentException('Geçersiz TOTP anahtarı.');$bits.=str_pad(decbin($v),5,'0',STR_PAD_LEFT);}
  $key='';for($i=0;$i+8<=strlen($bits);$i+=8)$key.=chr(bindec(substr($bits,$i,8)));
  $hash=hash_hmac('sha1',pack('N2',intdiv($counter,4294967296),$counter%4294967296),$key,true);$offset=ord($hash[19])&15;$number=unpack('N',substr($hash,$offset,4))[1]&0x7fffffff;return str_pad((string)($number%1000000),6,'0',STR_PAD_LEFT);
 }
 public function counter(string $secret,string $code,?int $after=null): ?int {
  if(!preg_match('/^[0-9]{6}$/',$code))return null;$now=intdiv(now()->timestamp,30);
  foreach([$now,$now-1,$now+1] as $c)if(($after===null||$c>$after)&&hash_equals($this->code($secret,$c),$code))return $c;return null;
 }
 public function verify(User $user,?string $code): void {
  if(!$this->required($user))return;
  if(!$user->mfa_confirmed_at||!$user->mfa_secret)throw new DomainError('MFA_KURULUMU','Bu yetkili hesap için doğrulayıcı uygulama kurulumu gerekir; kurumun hesap sorumlusuna başvurun.',503);
  DB::transaction(function()use($user,$code){$u=User::query()->lockForUpdate()->findOrFail($user->id);$counter=$this->counter($u->mfa_secret,$code??'',$u->mfa_last_counter);if($counter===null)throw new DomainError('MFA_GEREKLI','Doğrulayıcı uygulamadaki güncel altı haneli kodu girin.',401);$u->mfa_last_counter=$counter;$u->save();});
 }
}
