# LAN deployment (Windows server)

Target architecture:

```
 Employee PC (Flutter)  ─┐
 Employee PC (Flutter)  ─┼── LAN / Wi-Fi ──►  Central PC
 Employee PC (Flutter)  ─┘     TCP 8000        ├─ Apache + PHP  (Laravel API, listens on 0.0.0.0:8000)
                                               └─ MySQL         (listens on 127.0.0.1:3306 only)
```

- Employees' computers talk **only** to the API on port 8000.
- MySQL is reachable **only** from the central PC itself.
- No internet connection is required at any point after installation.
- The API has no login. Who may call it is decided by the network: the Windows
  firewall (LAN only) and `CRM_ALLOWED_NETWORKS` in `.env`.

## 1. Give the server a fixed address

Employees' apps are configured with the server address, so it must not change.
Either reserve the address in the router's DHCP settings (preferred) or set a
static IP on the server's network adapter.

```powershell
ipconfig            # note the IPv4 address, e.g. 192.168.1.10, and subnet
```

## 2. Configure .env for the LAN

```ini
APP_ENV=production
APP_DEBUG=false
APP_URL=http://192.168.1.10:8000
APP_TIMEZONE=Africa/Algiers
# Only this machine and the office subnet:
CRM_ALLOWED_NETWORKS=127.0.0.1,::1,192.168.1.0/24
```

Then `php artisan config:cache`.

## 3. Run the API on the network interface

`php artisan serve` binds to 127.0.0.1 by default, which is unreachable from
other computers. Two ways to listen on the LAN:

### Option A — quick start (testing only)

```powershell
php artisan serve --host=0.0.0.0 --port=8000
```

PHP's built-in server processes one request at a time and stops when the
window is closed. Use it to validate the installation, not for daily use.

### Option B — Apache as a Windows service (recommended)

1. Download Apache 2.4 Win64 (Apache Lounge, VS17 build) and the Visual C++
   Redistributable while you have internet; copy them to the server. Unzip to
   `C:\Apache24`.
2. Use a **Thread Safe (TS)** x64 PHP build (the `winget` PHP package is TS and
   contains `php8apache2_4.dll`).
3. Copy [deploy/apache-softel-crm.conf](../deploy/apache-softel-crm.conf) to
   `C:\Apache24\conf\extra\`, adjust the PHP and project paths inside, and add
   to the end of `C:\Apache24\conf\httpd.conf`:

   ```apache
   Include conf/extra/apache-softel-crm.conf
   ```

   and make sure `LoadModule rewrite_module modules/mod_rewrite.so` is not commented out.
4. Grant write access for the service to `storage` and `bootstrap\cache`
   (the service runs as LocalSystem by default, which already has it).
5. Install and start the service (administrator PowerShell):

   ```powershell
   C:\Apache24\bin\httpd.exe -t                 # syntax check, must print "Syntax OK"
   C:\Apache24\bin\httpd.exe -k install -n "SoftelCRM-Apache"
   Start-Service SoftelCRM-Apache
   Set-Service SoftelCRM-Apache -StartupType Automatic
   ```

The API now starts with Windows and serves requests in parallel.

After every code update:

```powershell
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache
php artisan route:cache
Restart-Service SoftelCRM-Apache
```

## 4. Windows firewall

Run in an **administrator** PowerShell on the server.

Make sure the LAN connection is classified as *Private* (not *Public*):

```powershell
Get-NetConnectionProfile
Set-NetConnectionProfile -InterfaceAlias "Ethernet" -NetworkCategory Private   # adapter name from the previous command
```

Allow the API port from the local subnet only:

```powershell
New-NetFirewallRule -DisplayName "SOFTEL CRM API (TCP 8000)" `
  -Direction Inbound -Protocol TCP -LocalPort 8000 `
  -Action Allow -Profile Private,Domain -RemoteAddress LocalSubnet
```

Block MySQL from the network (block rules win over allow rules; local
connections from the API on the same PC are not affected):

```powershell
New-NetFirewallRule -DisplayName "Block MySQL from network" `
  -Direction Inbound -Protocol TCP -LocalPort 3306,33060 -Action Block
```

If the employees' PCs have restrictive outbound rules, allow outbound TCP 8000
to the server there.

## 5. Keep MySQL off the network

Firewall rules are one layer; also make MySQL listen on loopback only. In the
MySQL configuration file (for the MySQL84 service:
`C:\ProgramData\MySQL\MySQL Server 8.4\my.ini`), section `[mysqld]`:

```ini
bind-address=127.0.0.1
mysqlx-bind-address=127.0.0.1
```

Restart the service and verify:

```powershell
Restart-Service MySQL84
netstat -ano | findstr ":3306"     # must show 127.0.0.1:3306, not 0.0.0.0:3306
```

The application's MySQL user is created as `'softel_crm'@'localhost'`, so even
with a reachable port it could not log in from another computer.

## 6. Verify from an employee computer

```powershell
Test-NetConnection 192.168.1.10 -Port 8000      # TcpTestSucceeded : True
Test-NetConnection 192.168.1.10 -Port 3306      # TcpTestSucceeded : False  (expected)
Invoke-RestMethod http://192.168.1.10:8000/api/v1/health
```

Expected health response: `success: true`, `data.status: ok`, `data.database: ok`.

| Symptom | Likely cause |
|---|---|
| Connection timed out | firewall rule missing, network profile is Public, server IP changed |
| `403 NETWORK_NOT_ALLOWED` | client subnet not in `CRM_ALLOWED_NETWORKS` (then `php artisan config:cache`) |
| `500 SERVER_ERROR` | read `storage/logs/laravel-YYYY-MM-DD.log` on the server |
| health shows `database: unavailable` (503) | MySQL service stopped or wrong `DB_*` values |

## 7. Flutter client configuration

The Flutter app only needs the base URL, e.g. `http://192.168.1.10:8000/api/v1`.
Make it configurable in the app settings (not hard-coded) so a server change
does not require a new build. Call `GET /health` to test the address.

Traffic is plain HTTP on the internal network. If the Wi-Fi is shared with
guests, put guests on a separate network/VLAN, or terminate HTTPS in Apache
with an internal certificate.
