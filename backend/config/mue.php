<?php
return [
    'python'=>env('MATCHING_PYTHON','python3'),
    'optimizer_runner'=>env('MATCHING_RUNNER',base_path('../optimizer/runner.py')),
    'scanner'=>env('DOCUMENT_SCANNER'),
    'token_minutes'=>120,
    'integration_enabled'=>['obs'=>false,'sso'=>false,'sms'=>false,'ebys'=>false],
];
