<?php
namespace App\Integrations;
interface OfficialDocuments { public function submit(string $privateDocumentPath,string $decisionReference): string;public function status(string $externalReference): array; }
