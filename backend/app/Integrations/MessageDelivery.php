<?php
namespace App\Integrations;
interface MessageDelivery { public function send(string $recipient,string $message,string $eventKey): string; }
