$ErrorActionPreference = 'Stop'
$mueRoot = $PSScriptRoot
$php = 'C:\xampp\php\php.exe'
$ini = Join-Path $mueRoot 'tools\php.ini'
$env:PHPRC = $ini
$projectProcesses = @(Get-CimInstance Win32_Process | Where-Object {
    $_.Name -eq 'php.exe' -and $_.CommandLine -and
    $_.CommandLine.Replace('/', '\').Contains($ini)
})
$pg = 'C:\Program Files\PostgreSQL\18\bin'
$pgData = Join-Path $mueRoot 'tools\pgdata'
& (Join-Path $pg 'pg_isready.exe') -h 127.0.0.1 -p 55432 -q
if ($LASTEXITCODE -ne 0) {
    Start-Process -FilePath (Join-Path $pg 'pg_ctl.exe') -ArgumentList @('-D', ('"'+$pgData+'"'), '-l', ('"'+(Join-Path $mueRoot 'tools\postgres.log')+'"'), '-o', '"-p 55432 -h 127.0.0.1"', 'start') -WindowStyle Hidden
    for ($attempt = 0; $attempt -lt 30; $attempt++) {
        Start-Sleep -Milliseconds 500
        & (Join-Path $pg 'pg_isready.exe') -h 127.0.0.1 -p 55432 -q
        if ($LASTEXITCODE -eq 0) { break }
    }
    if ($LASTEXITCODE -ne 0) { throw 'Yerel PostgreSQL başlatılamadı.' }
}
Push-Location (Join-Path $mueRoot 'backend')
try {
    & $php -c $ini artisan migrate --force
    if ($LASTEXITCODE -ne 0) { throw 'Migration başarısız.' }
    if (-not ($projectProcesses | Where-Object { $_.CommandLine -match 'artisan\s+queue:work\b' })) {
        Start-Process -FilePath $php -ArgumentList @('-c',('"'+$ini+'"'),'artisan','queue:work','--timeout=150','--tries=3') -WorkingDirectory (Join-Path $mueRoot 'backend') -WindowStyle Hidden -RedirectStandardOutput (Join-Path $mueRoot 'tools\queue.log') -RedirectStandardError (Join-Path $mueRoot 'tools\queue-error.log')
    }
    if (-not ($projectProcesses | Where-Object { $_.CommandLine -match 'artisan\s+schedule:work\b' })) {
        Start-Process -FilePath $php -ArgumentList @('-c',('"'+$ini+'"'),'artisan','schedule:work') -WorkingDirectory (Join-Path $mueRoot 'backend') -WindowStyle Hidden -RedirectStandardOutput (Join-Path $mueRoot 'tools\schedule.log') -RedirectStandardError (Join-Path $mueRoot 'tools\schedule-error.log')
    }
    Write-Host 'MUE Türkçe portalı: http://127.0.0.1:8088'
    if ($projectProcesses | Where-Object { $_.CommandLine -match 'artisan\s+serve\b' -and $_.CommandLine -match '--port=8088\b' }) {
        Write-Host 'Bu projeye ait localhost sunucusu zaten çalışıyor.'
        return
    }
    & $php -c $ini artisan serve --host=127.0.0.1 --port=8088 --no-reload
} finally { Pop-Location }
