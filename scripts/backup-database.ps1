<#
.SYNOPSIS
    Dumps the SOFTEL CRM MySQL database to a timestamped .sql.zip file.
.EXAMPLE
    powershell -ExecutionPolicy Bypass -File scripts\backup-database.ps1
    powershell -ExecutionPolicy Bypass -File scripts\backup-database.ps1 -BackupDir E:\CRM-Backups -KeepDays 60
#>
param(
    [string]$BackupDir = 'D:\Backups\softel_crm',
    [int]$KeepDays = 30,
    [string]$MySqlBin = 'C:\Program Files\MySQL\MySQL Server 8.4\bin'
)

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'CrmEnv.ps1')

$db = Get-CrmDbSettings (Split-Path -Parent $PSScriptRoot)
$mysqldump = Join-Path $MySqlBin 'mysqldump.exe'
if (-not (Test-Path -LiteralPath $mysqldump)) { throw "mysqldump not found: $mysqldump (use -MySqlBin)" }

New-Item -ItemType Directory -Force -Path $BackupDir | Out-Null
$stamp = Get-Date -Format 'yyyyMMdd_HHmmss'
$sqlFile = Join-Path $BackupDir "$($db.Database)_$stamp.sql"
$optionFile = New-CrmMySqlOptionFile $db

try {
    & $mysqldump "--defaults-extra-file=$optionFile" `
        --single-transaction --quick --no-tablespaces --triggers `
        --hex-blob --set-gtid-purged=OFF `
        "--result-file=$sqlFile" $db.Database
    if ($LASTEXITCODE -ne 0) { throw "mysqldump failed (exit code $LASTEXITCODE)" }
}
finally {
    Remove-Item -LiteralPath $optionFile -Force -ErrorAction SilentlyContinue
}

Compress-Archive -LiteralPath $sqlFile -DestinationPath "$sqlFile.zip" -Force
Remove-Item -LiteralPath $sqlFile -Force
$size = [math]::Round((Get-Item -LiteralPath "$sqlFile.zip").Length / 1KB, 1)
Write-Output "Backup written: $sqlFile.zip ($size KB)"

# Retention: only this database's dumps, only in the backup folder.
$cutoff = (Get-Date).AddDays(-$KeepDays)
Get-ChildItem -LiteralPath $BackupDir -Filter "$($db.Database)_*.sql.zip" |
    Where-Object { $_.LastWriteTime -lt $cutoff } |
    ForEach-Object {
        Remove-Item -LiteralPath $_.FullName -Force
        Write-Output "Removed old backup: $($_.Name)"
    }
