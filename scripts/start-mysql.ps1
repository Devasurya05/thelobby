# Starts the local MySQL server (localhost only) if it is not already running.
# Run this once after every reboot:  powershell -File scripts\start-mysql.ps1
# Why not a Windows service? Installing a service needs admin rights; this keeps setup simple.
$bin  = "C:\Program Files\MySQL\MySQL Server 8.4\bin"
$data = Join-Path $env:USERPROFILE "mysql-data"

if (Get-Process mysqld -ErrorAction SilentlyContinue) {
    Write-Output "MySQL is already running."
    return
}

# --bind-address=127.0.0.1 means only this laptop can connect to the database
# (phones on Wi-Fi only talk to the PHP web server, never to MySQL directly).
# --mysqlx=OFF turns off MySQL's extra "X Protocol" port (33060), which we do not use and which
# would otherwise listen on every network interface.
Start-Process -FilePath "$bin\mysqld.exe" `
    -ArgumentList "--datadir=`"$data`"", "--bind-address=127.0.0.1", "--port=3306", "--mysqlx=OFF", "--console" `
    -WindowStyle Hidden -RedirectStandardError "$data\server-stderr.log"
Start-Sleep -Seconds 6
Write-Output "MySQL started on 127.0.0.1:3306."
