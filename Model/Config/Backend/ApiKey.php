<?php
declare(strict_types=1);

namespace IntentsFi\Checkout\Model\Config\Backend;

use IntentsFi\Checkout\Model\KeyMode;
use Magento\Config\Model\Config\Backend\Encrypted;
use Magento\Framework\Exception\LocalizedException;

/**
 * The API key field: refuses anything that is not an `ik_test_` / `ik_live_` secret key (a
 * publishable `pk_` key is the usual mistake), then encrypts it like any obscured field.
 */
class ApiKey extends Encrypted
{
    public function beforeSave()
    {
        $value = trim((string) $this->getValue());
        // All asterisks is the obscured placeholder: the stored key is kept as it is.
        if ($value !== '' && preg_match('/^\*+$/', $value) !== 1) {
            $problem = KeyMode::problem($value);
            if ($problem !== null) {
                throw new LocalizedException(__($problem));
            }
            $this->setValue($value);
        }
        return parent::beforeSave();
    }
}
