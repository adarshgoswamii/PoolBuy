$ErrorActionPreference = 'Stop'
Write-Output "=== oc_poolbuy_payment ==="
@(docker compose exec -T -e MYSQL_PWD=opencart mysql mysql -uroot opencart -N -B -e "SELECT CONCAT_WS(' | ', COLUMN_NAME, COLUMN_TYPE) FROM information_schema.columns WHERE table_schema='opencart' AND table_name='oc_poolbuy_payment' ORDER BY ORDINAL_POSITION;")
Write-Output ""
Write-Output "=== oc_poolbuy_settlement ==="
@(docker compose exec -T -e MYSQL_PWD=opencart mysql mysql -uroot opencart -N -B -e "SELECT CONCAT_WS(' | ', COLUMN_NAME, COLUMN_TYPE) FROM information_schema.columns WHERE table_schema='opencart' AND table_name='oc_poolbuy_settlement' ORDER BY ORDINAL_POSITION;")
Write-Output ""
Write-Output "=== payment rows ==="
@(docker compose exec -T -e MYSQL_PWD=opencart mysql mysql -uroot opencart -N -B -e "SELECT CONCAT_WS(' | ', status, currency_code, COUNT(*), SUM(amount)) FROM oc_poolbuy_payment GROUP BY status, currency_code;")
Write-Output ""
Write-Output "=== settlement rows ==="
@(docker compose exec -T -e MYSQL_PWD=opencart mysql mysql -uroot opencart -N -B -e "SELECT CONCAT_WS(' | ', status, currency_code, COUNT(*)) FROM oc_poolbuy_settlement GROUP BY status, currency_code;")
