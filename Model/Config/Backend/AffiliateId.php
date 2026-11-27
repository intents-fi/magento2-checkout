<?php
declare(strict_types=1);

namespace IntentsFi\Checkout\Model\Config\Backend;

use IntentsFi\Checkout\Model\Affiliate;
use IntentsFi\Checkout\Model\Api\CurlTransport;
use IntentsFi\Checkout\Model\Config;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Value;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;

/**
 * The "Affiliate ID (optional)" field: refuses anything that is not a valid ID, saves it
 * lowercased (empty clears it), and when it changes asks Intents who it belongs to
 * (`GET /v0/ref/resolve`, which receives only the ID). The answer is shown, never required: the
 * value is saved even when Intents does not know it or cannot be reached.
 */
class AffiliateId extends Value
{
    /**
     * @param Context $context
     * @param Registry $registry
     * @param ScopeConfigInterface $config
     * @param TypeListInterface $cacheTypeList
     * @param CurlTransport $transport
     * @param Config $moduleConfig
     * @param ManagerInterface $messageManager
     * @param AbstractResource|null $resource
     * @param AbstractDb|null $resourceCollection
     * @param array $data
     */
    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        private readonly CurlTransport $transport,
        private readonly Config $moduleConfig,
        private readonly ManagerInterface $messageManager,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
    }

    /**
     * Refuse an invalid ID; store a valid one lowercased.
     *
     * @return $this
     * @throws LocalizedException
     */
    public function beforeSave()
    {
        $id = Affiliate::normalize($this->getValue());
        if ($id === null) {
            throw new LocalizedException(__('Affiliate IDs are 6 to 16 lowercase letters, digits or hyphens.'));
        }
        $this->setValue($id);
        return parent::beforeSave();
    }

    /**
     * Name the affiliate behind a new ID.
     *
     * @return $this
     */
    public function afterSave()
    {
        $id = (string) $this->getValue();
        if ($id !== '' && $this->isValueChanged()) {
            $result = Affiliate::resolve(
                $this->transport,
                $this->moduleConfig->apiBaseUrl(),
                $id,
                'intentsfi-checkout-magento/' . Config::VERSION
            );
            [$type, $text, $args] = Affiliate::notice($result);
            $phrase = __($text, ...$args);
            if ($type === 'success') {
                $this->messageManager->addSuccessMessage($phrase);
            } else {
                $this->messageManager->addWarningMessage($phrase);
            }
        }
        return parent::afterSave();
    }
}
