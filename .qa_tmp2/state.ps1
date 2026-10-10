$ErrorActionPreference = 'Stop'
. "$PSScriptRoot\..\tools\poolbuy\admin.ps1"
$tick = [char]96
Write-Output "=== poolbuy settings ==="
@(docker compose exec -T -e MYSQL_PWD=opencart mysql mysql -uroot opencart -N -B -e "SELECT CONCAT_WS(' = ', ${tick}key${tick}, value) FROM oc_setting WHERE code='module_poolbuy' ORDER BY ${tick}key${tick};")
Write-Output "=== rate limit rows ==="
@(docker compose exec -T -e MYSQL_PWD=opencart mysql mysql -uroot opencart -N -B -e "SELECT CONCAT_WS(' | ', LEFT(bucket,12), hits, window_start, FROM_UNIXTIME(window_start)) FROM oc_poolbuy_rate_limit;")
Write-Output "=== now ==="
@(docker compose exec -T -e MYSQL_PWD=opencart mysql mysql -uroot opencart -N -B -e "SELECT CONCAT(UNIX_TIMESTAMP(), ' ', NOW());")
