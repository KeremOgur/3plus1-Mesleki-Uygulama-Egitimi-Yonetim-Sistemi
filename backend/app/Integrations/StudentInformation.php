<?php
namespace App\Integrations;
interface StudentInformation {
    public function fetchStudents(string $institutionCode,string $programCode,string $termCode): iterable;
    public function submitGrade(string $studentNo,string $termCode,float $grade,string $decisionReference): string;
}
