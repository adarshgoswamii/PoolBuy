-- Registers the PoolBuy extension package with OpenCart.
--
-- Why this exists
-- ---------------
-- OpenCart normally creates the `oc_extension_install` row when an extension is
-- uploaded as a .ocmod.zip through Extensions > Installer. PoolBuy is developed
-- in place inside upload/extension/poolbuy/, so that row has to be created once
-- by hand. Without it, admin/controller/startup/extension.php (which reads
-- `oc_extension_install`) will not register the extension's admin autoloader,
-- template or language paths, and the settings page would 404.
--
-- This script is idempotent - re-running it will not create duplicates.
--
-- Usage:
--   docker compose exec -T mysql mysql -uroot -popencart opencart < tools/poolbuy/register_extension.sql
--
-- Note: this only registers the package. Creating the PoolBuy tables and the
-- `oc_extension` row is done by installing the module from
-- Extensions > Modules > PoolBuy Wholesale, which runs the extension's own
-- install() hook.

INSERT INTO `oc_extension_install`
    (`extension_id`, `extension_download_id`, `name`, `description`, `code`, `version`, `author`, `link`, `status`, `date_added`)
SELECT
    0,
    0,
    'PoolBuy Wholesale Marketplace',
    'B2B wholesale marketplace with collective pool buying.',
    'poolbuy',
    '1.0.0',
    'PoolBuy',
    '',
    1,
    NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM `oc_extension_install` WHERE `code` = 'poolbuy'
);
