$ErrorActionPreference = 'Stop'
Write-Output "=== oc_setting indexes ==="
@(docker compose exec -T -e MYSQL_PWD=opencart mysql mysql -uroot opencart -N -B -e "SELECT CONCAT_WS(' | ', INDEX_NAME, NON_UNIQUE, COLUMN_NAME, SEQ_IN_INDEX) FROM information_schema.statistics WHERE table_schema='opencart' AND table_name='oc_setting' ORDER BY INDEX_NAME, SEQ_IN_INDEX;")
Write-Output ""
Write-Output "=== duplicate poolbuy settings, with ids in insertion order ==="
@(docker compose exec -T -e MYSQL_PWD=opencart mysql mysql -uroot opencart -N -B -e "SELECT CONCAT_WS(' | ', setting_id, store_id, ``key``, value) FROM oc_setting WHERE code='module_poolbuy' ORDER BY setting_id;")
Write-Output ""
Write-Output "=== keys with more than one row (any code) ==="
@(docker compose exec -T -e MYSQL_PWD=opencart mysql mysql -uroot opencart -N -B -e "SELECT CONCAT_WS(' | ', code, ``key``, COUNT(*)) FROM oc_setting GROUP BY store_id, code, ``key`` HAVING COUNT(*) > 1 ORDER BY COUNT(*) DESC LIMIT 20;")
