$ErrorActionPreference = 'Stop'
. "$PSScriptRoot\..\tools\poolbuy\admin.ps1"

$tick = [char]96

Write-Output ("duplicate keys before : " + (Get-OcDuplicateSettingCount))

# Keep the LOWEST setting_id per key, because that is the row the seed wrote, and
# delete the later duplicates that the upserts appended.
Invoke-OcSql @"
DELETE s FROM oc_setting s
JOIN (
  SELECT store_id, ${tick}key${tick}, MIN(setting_id) AS keep_id
  FROM oc_setting WHERE code='module_poolbuy'
  GROUP BY store_id, ${tick}key${tick}
) k ON k.store_id=s.store_id AND k.${tick}key${tick}=s.${tick}key${tick}
WHERE s.code='module_poolbuy' AND s.setting_id <> k.keep_id;
"@

Write-Output ("duplicate keys after  : " + (Get-OcDuplicateSettingCount))
Write-Output "=== effective poolbuy settings ==="
@(docker compose exec -T -e MYSQL_PWD=opencart mysql mysql -uroot opencart -N -B -e "SELECT CONCAT_WS(' = ', ${tick}key${tick}, value) FROM oc_setting WHERE code='module_poolbuy' ORDER BY ${tick}key${tick};")
