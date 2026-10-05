<?php
namespace App\Domain;
class DomainError extends \RuntimeException
{
    public function __construct(public string $errorCode, string $message, public int $httpStatus=409, public array $fields=[])
    { parent::__construct($message); }
}
