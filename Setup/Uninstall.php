<?php
/**
 * `bin/magento module:uninstall IntentsFi_Checkout --remove-data`.
 */
declare(strict_types=1);

namespace IntentsFi\Checkout\Setup;

use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Magento\Framework\Setup\UninstallInterface;

/**
 * Removes what the module added: its webhook event table and its settings
 * (`payment/intentsfi_checkout/*`, including the encrypted key and webhook secret, at every scope).
 * Orders keep their payment information and comments: they are the store's records.
 *
 * Runs only for `--remove-data`; without it, the uninstall leaves the data in place.
 */
class Uninstall implements UninstallInterface
{
    private const TABLE = 'intentsfi_checkout_webhook_event';

    public function uninstall(SchemaSetupInterface $setup, ModuleContextInterface $context)
    {
        $setup->startSetup();
        $connection = $setup->getConnection();

        $table = $setup->getTable(self::TABLE);
        if ($connection->isTableExists($table)) {
            $connection->dropTable($table);
        }
        $connection->delete(
            $setup->getTable('core_config_data'),
            ['path LIKE ?' => 'payment/intentsfi_checkout/%']
        );

        $setup->endSetup();
    }
}
