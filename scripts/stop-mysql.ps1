# Cleanly shuts the local MySQL server down (the opposite of start-mysql.ps1).
# Run:  powershell -File scripts\stop-mysql.ps1
# A clean shutdown lets MySQL finish writing everything to disk. (Killing the process also works,
# because MySQL can recover, but this is the tidy way.)
$bin  = "C:\Program Files\MySQL\MySQL Server 8.4\bin"
$data = Join-Path $env:USERPROFILE "mysql-data"

if (-not (Get-Process mysqld -ErrorAction SilentlyContinue)) {
    Write-Output "MySQL is not running."
    return
}

# The root password is kept in a file outside the project (never in git).
# MYSQL_PWD hands it to mysqladmin without putting it on the command line.
$env:MYSQL_PWD = (Get-Content (Join-Path $data "secrets\root.txt") -Raw).Trim()
try {
    & "$bin\mysqladmin.exe" --user=root --host=127.0.0.1 shutdown
    Write-Output "MySQL stopped."
} finally {
    Remove-Item Env:MYSQL_PWD -ErrorAction SilentlyContinue
}
