# Shared helpers for the backup/restore scripts: read DB settings from .env and
# write them to a temporary MySQL option file (keeps the password off the
# command line and out of the process list).

function Get-CrmEnvValue {
    param([string]$EnvFile, [string]$Name, [string]$Default = $null)

    $line = Get-Content -LiteralPath $EnvFile -Encoding UTF8 |
        Where-Object { $_ -match "^\s*$Name\s*=" } |
        Select-Object -Last 1
    if (-not $line) { return $Default }

    $value = ($line -split '=', 2)[1].Trim()
    if ($value -match '^"(.*)"$' -or $value -match "^'(.*)'$") { $value = $Matches[1] }
    if ($value -eq '') { return $Default }
    return $value
}

function Get-CrmDbSettings {
    param([string]$ProjectRoot)

    $envFile = Join-Path $ProjectRoot '.env'
    if (-not (Test-Path -LiteralPath $envFile)) { throw ".env not found in $ProjectRoot" }

    [pscustomobject]@{
        Host     = Get-CrmEnvValue $envFile 'DB_HOST' '127.0.0.1'
        Port     = Get-CrmEnvValue $envFile 'DB_PORT' '3306'
        Database = Get-CrmEnvValue $envFile 'DB_DATABASE'
        User     = Get-CrmEnvValue $envFile 'DB_USERNAME'
        Password = Get-CrmEnvValue $envFile 'DB_PASSWORD' ''
    }
}

function New-CrmMySqlOptionFile {
    param($Db)

    $path = [IO.Path]::GetTempFileName()
    $escaped = $Db.Password.Replace('\', '\\').Replace('"', '\"')
    @(
        '[client]'
        "user=$($Db.User)"
        "password=`"$escaped`""
        "host=$($Db.Host)"
        "port=$($Db.Port)"
        'default-character-set=utf8mb4'
    ) | Set-Content -LiteralPath $path -Encoding ascii
    return $path
}
