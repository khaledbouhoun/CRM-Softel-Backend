<#
.SYNOPSIS
    Restores a SOFTEL CRM backup (.sql or .sql.zip) into the database.
    The existing tables of the target database are replaced.
.EXAMPLE
    powershell -ExecutionPolicy Bypass -File scripts\restore-database.ps1 -BackupFile D:\Backups\softel_crm\softel_crm_20261101_130000.sql.zip
.EXAMPLE
    # Restore into another (scratch) database to test a backup:
    scripts\restore-database.ps1 -BackupFile <file> -Database softel_crm_restore_test
#>
param(
    [Parameter(Mandatory = $true)][string]$BackupFile,
    [string]$Database,
    [string]$MySqlBin = 'C:\Program Files\MySQL\MySQL Server 8.4\bin'
)

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'CrmEnv.ps1')

$db = Get-CrmDbSettings (Split-Path -Parent $PSScriptRoot)
if ($Database) { $db.Database = $Database }

$mysql = Join-Path $MySqlBin 'mysql.exe'
if (-not (Test-Path -LiteralPath $mysql)) { throw "mysql not found: $mysql (use -MySqlBin)" }
if (-not (Test-Path -LiteralPath $BackupFile)) { throw "Backup file not found: $BackupFile" }

Write-Warning "This replaces the content of database '$($db.Database)' with '$BackupFile'."
$answer = Read-Host "Type the database name ($($db.Database)) to continue"
if ($answer -ne $db.Database) { Write-Output 'Cancelled.'; exit 1 }

$workDir = Join-Path ([IO.Path]::GetTempPath()) ("crm_restore_" + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $workDir | Out-Null
$optionFile = New-CrmMySqlOptionFile $db

try {
    $sqlFile = $BackupFile
    if ($BackupFile -like '*.zip') {
        Expand-Archive -LiteralPath $BackupFile -DestinationPath $workDir
        $sqlFile = (Get-ChildItem -LiteralPath $workDir -Filter '*.sql' | Select-Object -First 1).FullName
        if (-not $sqlFile) { throw 'No .sql file inside the archive.' }
    }

    $process = Start-Process -FilePath $mysql `
        -ArgumentList "--defaults-extra-file=`"$optionFile`"", $db.Database `
        -RedirectStandardInput $sqlFile -NoNewWindow -Wait -PassThru
    if ($process.ExitCode -ne 0) { throw "mysql import failed (exit code $($process.ExitCode))" }

    Write-Output "Database '$($db.Database)' restored from $BackupFile."
    Write-Output 'Next: php artisan migrate --force   (brings an older backup up to the current schema)'
}
finally {
    Remove-Item -LiteralPath $optionFile -Force -ErrorAction SilentlyContinue
    Remove-Item -LiteralPath $workDir -Recurse -Force -ErrorAction SilentlyContinue
}
