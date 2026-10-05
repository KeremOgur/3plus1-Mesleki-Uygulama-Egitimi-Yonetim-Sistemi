<?php
namespace App\Domain;
use App\Models\DomainRecord;
class EducationModel
{
 public function semester(DomainRecord $student,DomainRecord $termProgram,?int $requested=null): array
 {
  if($termProgram->active_student_count<15)return ['semester'=>4,'reason'=>'15 öğrencinin altında ilk üç dönem okul, dördüncü dönem Mesleki Uygulama Eğitimi.'];
  if(!$termProgram->grouping_enabled)return ['semester'=>$termProgram->semester,'reason'=>'Yetkili kurulun kayıtlı program/dönem kararı.'];
  if($student->graduation_exception && in_array($requested,[3,4]))return ['semester'=>$requested,'reason'=>'Mezuniyet veya dönem uzatma istisnası; yetkili inceleme gerekir.'];
  $last=substr($student->student_no,-1);if(!ctype_digit($last))throw new DomainError('OGRENCI_NO','Grup önerisi için öğrenci numarasının son hanesi sayısal olmalıdır.',422);
  return ['semester'=>((int)$last)%2?3:4,'reason'=>((int)$last)%2?'Öğrenci numarası tek: güz yarıyılı.':'Öğrenci numarası çift: bahar yarıyılı.'];
 }
}
