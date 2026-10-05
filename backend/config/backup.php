<?php
return [
    'enabled'=>env('BACKUP_ENABLED',false),
    'pg_dump'=>env('BACKUP_PG_DUMP','pg_dump'),
    'directory'=>env('BACKUP_DIRECTORY')?:storage_path('app/backups'),
    'timeout_seconds'=>(int)env('BACKUP_TIMEOUT_SECONDS',600),
];
