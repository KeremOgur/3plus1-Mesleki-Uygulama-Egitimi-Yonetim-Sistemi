<?php
namespace App\Integrations;
interface InstitutionalIdentity {
    public function verify(string $credential,string $assertion): array;
}
