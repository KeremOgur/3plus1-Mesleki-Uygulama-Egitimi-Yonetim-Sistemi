<?php
namespace App\Integrations;
use App\Domain\DomainError;
class UnavailableIntegration implements StudentInformation,InstitutionalIdentity,MessageDelivery,OfficialDocuments
{
    private function unavailable(): never { throw new DomainError('ENTEGRASYON_YOK','Kurumsal entegrasyon erişimi yapılandırılmadı.',503); }
    public function fetchStudents(string $institutionCode,string $programCode,string $termCode): iterable { $this->unavailable(); }
    public function submitGrade(string $studentNo,string $termCode,float $grade,string $decisionReference): string { $this->unavailable(); }
    public function verify(string $credential,string $assertion): array { $this->unavailable(); }
    public function send(string $recipient,string $message,string $eventKey): string { $this->unavailable(); }
    public function submit(string $privateDocumentPath,string $decisionReference): string { $this->unavailable(); }
    public function status(string $externalReference): array { $this->unavailable(); }
}
