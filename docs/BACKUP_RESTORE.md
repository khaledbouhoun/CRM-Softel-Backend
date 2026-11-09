# Backup and restore

All CRM data lives in the MySQL database `softel_crm`. The application code can
be reinstalled at any time; the database and the `.env` file are what must be
protected.

| What | Where | How often |
|---|---|---|
| Database dump | `scripts/backup-database.ps1` → `D:\Backups\softel_crm\*.sql.zip` | daily (scheduled) |
| `.env` (DB password, APP_KEY, settings) | project folder | after every change |
| Off-machine copy | USB drive / NAS / another PC | at least weekly |

A backup kept only on the server's own disk does not survive a disk failure,
theft or ransomware: copy the backup folder to another device regularly.

## 1. Manual backup

PowerShell, from the project folder:

```powershell
powershell -ExecutionPolicy Bypass -File scripts\backup-database.ps1
```

Options:

```powershell
scripts\backup-database.ps1 -BackupDir "E:\CRM-Backups" -KeepDays 60 `
  -MySqlBin "C:\Program Files\MySQL\MySQL Server 8.4\bin"
```

The script:

- reads the database name and credentials from `.env` (they are passed to
  `mysqldump` through a temporary option file, never on the command line);
- runs `mysqldump --single-transaction` (consistent snapshot, no locking — the
  CRM stays usable during the backup);
- compresses the dump to `softel_crm_YYYYMMDD_HHMMSS.sql.zip`;
- deletes backups older than `-KeepDays` (default 30) **in that folder only**;
- exits with a non-zero code on failure (visible in Task Scheduler history).

## 2. Scheduled daily backup

Administrator PowerShell (adjust the project path):

```powershell
$project = "D:\SOFTEL CRM\Laravel Backend"
$action  = New-ScheduledTaskAction -Execute "powershell.exe" `
  -Argument "-NoProfile -ExecutionPolicy Bypass -File `"$project\scripts\backup-database.ps1`""
$trigger = New-ScheduledTaskTrigger -Daily -At 13:00
Register-ScheduledTask -TaskName "SOFTEL CRM backup" -Action $action -Trigger $trigger `
  -User "SYSTEM" -RunLevel Highest -Description "Daily mysqldump of softel_crm"
```

Pick a time when the server is on (e.g. lunch time if it is shut down at night).
Check the result in Task Scheduler (*Last Run Result* `0x0`) and in the backup folder.

## 3. Restore

Restoring **replaces** the current content of the database with the backup.
Everything entered after the backup was taken is lost.

1. Stop the API so nobody writes during the restore:
   `Stop-Service SoftelCRM-Apache` (or close the `php artisan serve` window).
2. Take a backup of the current state first (in case the wrong file is restored):
   `scripts\backup-database.ps1`
3. Restore:

   ```powershell
   powershell -ExecutionPolicy Bypass -File scripts\restore-database.ps1 `
     -BackupFile "D:\Backups\softel_crm\softel_crm_20261101_130000.sql.zip"
   ```

   The script asks you to type the database name to confirm.
4. Bring the schema up to date (if the backup is older than the code):
   `php artisan migrate --force`
5. Start the API: `Start-Service SoftelCRM-Apache`, then check
   `http://<server-ip>:8000/api/v1/health`.

## 4. Moving to a new server / disaster recovery

1. Install PHP, MySQL and (optionally) Apache as in the README.
2. Create the database and the `softel_crm` user (README, step 1).
3. Copy the project folder (or reinstall it) and the saved `.env`.
4. `composer install --no-dev --optimize-autoloader` (only if `vendor/` was not copied).
5. Restore the latest dump with `scripts\restore-database.ps1`.
6. `php artisan migrate --force`, `php artisan config:cache`, `php artisan route:cache`.
7. Configure the firewall and service (docs/DEPLOYMENT_LAN.md). If the server
   IP changed, update the address in each Flutter client.

## 5. Test your backups

Once a month, restore the latest backup into a scratch database and check it:

```sql
CREATE DATABASE softel_crm_restore_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON softel_crm_restore_test.* TO 'softel_crm'@'localhost';
```

```powershell
scripts\restore-database.ps1 -BackupFile <latest.sql.zip> -Database softel_crm_restore_test
```

Then compare a few counts (`SELECT COUNT(*) FROM prospects;`) with production and
drop the scratch database.
