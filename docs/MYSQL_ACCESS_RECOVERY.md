# MySQL access recovery (root password unknown)

Use this when nobody knows the MySQL `root` password, so the CRM database and
its `softel_crm` user cannot be created the normal way (README, step 1).

It uses MySQL's documented `--init-file` mechanism: the server is started once
by hand with a small SQL file that it executes with full privileges at startup,
before any login is checked.

Applies to this server: Windows service **MySQL84**, MySQL Server 8.4,
configuration `C:\ProgramData\MySQL\MySQL Server 8.4\my.ini`, service account
`NT AUTHORITY\NetworkService`.

## Before you start

- **MySQL is down for 2–5 minutes.** Every application using this MySQL server
  (not only the CRM) loses its database during that time. Do it when nobody works.
- **Changing the root password can break other applications.** Other software
  on this PC may log in to MySQL as `root` with the current (forgotten)
  password. If you change it, those programs stop connecting until they are
  reconfigured. That is why there are two options:
  - **Option A (recommended):** create only the CRM database and user. `root` is untouched.
  - **Option B:** also set a new root password — only if you are sure nothing else uses `root`,
    or you are ready to update those programs.
- You need a **Windows administrator** account.
- The init file contains passwords: delete it as soon as you are done (step 6).

## 1. Write the init file

Open Notepad and paste **one** of the blocks below. Use the CRM password that is
in the project's `.env` (`DB_PASSWORD`); the examples use the current value
`SoftelCrm#2026`. Keep one statement per line, no comments, no blank lines at the top.

**Option A — CRM database and user only:**

```sql
CREATE DATABASE IF NOT EXISTS softel_crm CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS softel_crm_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'softel_crm'@'localhost' IDENTIFIED BY 'SoftelCrm#2026';
ALTER USER 'softel_crm'@'localhost' IDENTIFIED BY 'SoftelCrm#2026' ACCOUNT UNLOCK;
GRANT ALL PRIVILEGES ON softel_crm.* TO 'softel_crm'@'localhost';
GRANT ALL PRIVILEGES ON softel_crm_test.* TO 'softel_crm'@'localhost';
```

**Option B — same, plus a new root password** (replace `NewRootPassword` with a
strong password and write it down somewhere safe):

```sql
ALTER USER 'root'@'localhost' IDENTIFIED BY 'NewRootPassword';
CREATE DATABASE IF NOT EXISTS softel_crm CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS softel_crm_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'softel_crm'@'localhost' IDENTIFIED BY 'SoftelCrm#2026';
ALTER USER 'softel_crm'@'localhost' IDENTIFIED BY 'SoftelCrm#2026' ACCOUNT UNLOCK;
GRANT ALL PRIVILEGES ON softel_crm.* TO 'softel_crm'@'localhost';
GRANT ALL PRIVILEGES ON softel_crm_test.* TO 'softel_crm'@'localhost';
```

`softel_crm_test` is only needed on the development machine (automated tests);
on the production server you may leave out the two lines that mention it.

Save as **`C:\mysql-init.txt`**, encoding **UTF-8** (Notepad's default — not
"UTF-8 with BOM"; a BOM makes the first statement fail).

## 2. Stop the MySQL service

Open **PowerShell as administrator** (Start menu → right-click PowerShell →
*Run as administrator*):

```powershell
Stop-Service MySQL84
Get-Service MySQL84          # Status must be: Stopped
```

## 3. Start MySQL once with the init file

In the same administrator window (one line):

```powershell
& "C:\Program Files\MySQL\MySQL Server 8.4\bin\mysqld.exe" --defaults-file="C:\ProgramData\MySQL\MySQL Server 8.4\my.ini" --init-file=C:/mysql-init.txt --console
```

The window prints the server log and keeps running. Wait for a line containing:

```
ready for connections
```

- If you see `[ERROR]` mentioning `init_file` or a statement from the file, the
  server stops by itself. Read the message (usually a typo or a BOM in the file),
  fix `C:\mysql-init.txt` and run step 3 again.
- If it complains that the port is in use, the service is still running: redo step 2.

## 4. Stop the temporary server

Once `ready for connections` is shown, the statements have been applied.
Press **Ctrl+C** in that window and wait until the prompt returns (the log shows
`Shutdown complete`). This can take up to a minute.

## 5. Start the service again

```powershell
Start-Service MySQL84
Get-Service MySQL84          # Status must be: Running
```

If the service refuses to start, look at the end of
`C:\ProgramData\MySQL\MySQL Server 8.4\Data\SALIM-MSI.err`. A permissions
error on a file in the `Data` folder means a file created during step 3 is not
writable by NetworkService; give `NETWORK SERVICE` full control on the `Data`
folder (Properties → Security) and start the service again.

## 6. Delete the init file

```powershell
Remove-Item C:\mysql-init.txt
```

## 7. Check, then install the CRM database

In a normal (non-admin) PowerShell, in the project folder:

```powershell
& "C:\Program Files\MySQL\MySQL Server 8.4\bin\mysql.exe" -u softel_crm -p -e "SHOW DATABASES;"
```

Type the CRM password; the list must contain `softel_crm` (and `softel_crm_test`).
Then:

```powershell
php artisan migrate --seed
php artisan test
```

`GET http://<server>:8000/api/v1/health` should now return `"database": "ok"`.

## While you are here: keep MySQL off the network

This server's MySQL currently listens on all interfaces (`0.0.0.0:3306`), so
other computers on the LAN can reach it. The CRM never needs that. See
[DEPLOYMENT_LAN.md](DEPLOYMENT_LAN.md), section 5 (`bind-address=127.0.0.1`) and
the firewall rule in section 4. Check first that no other program on another
computer connects to this MySQL directly.
