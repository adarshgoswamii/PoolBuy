-- Registers the PoolBuy pool lifecycle job with OpenCart's cron system.
--
-- upload/cron.php calls catalog/controller/cron/cron.php, which iterates oc_cron
-- and invokes each enabled row's `action` as a catalog controller route once per
-- `cycle`. The action below resolves to
-- upload/extension/poolbuy/catalog/controller/cron/poolbuy.php::index().
--
-- Cycle is `hour` so pools expire and reprice promptly rather than only once a
-- day. The job is idempotent, so running it more often is safe.
--
-- Idempotent: re-running will not create duplicates.
--
-- Usage:
--   docker compose exec -T mysql mysql -uroot -popencart opencart < tools/poolbuy/register_cron.sql

INSERT INTO `oc_cron` (`code`, `description`, `cycle`, `action`, `status`, `date_added`, `date_modified`)
SELECT
    'poolbuy',
    'Processes PoolBuy pools: activates due pools, marks pools that reached their MOQ, expires pools that did not, applies retroactive pricing, converts commitments into orders and notifies participants.',
    'hour',
    'extension/poolbuy/cron/poolbuy',
    1,
    NOW(),
    -- Dated in the past so the very first cron run picks the job up immediately
    '2020-01-01 00:00:00'
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM `oc_cron` WHERE `code` = 'poolbuy'
);
