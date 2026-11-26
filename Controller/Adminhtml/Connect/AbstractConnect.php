<?php
declare(strict_types=1);

namespace IntentsFi\Checkout\Controller\Adminhtml\Connect;

use IntentsFi\Checkout\Model\Connect\FlowProvider;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\Redirect;

/**
 * Shared by the Connect admin actions: the ACL resource, the scope being edited, and the way back
 * to the payment settings with a result the page words itself (never reflected text).
 */
abstract class AbstractConnect extends Action
{
    public const ADMIN_RESOURCE = 'IntentsFi_Checkout::config';

    public function __construct(Context $context, protected readonly FlowProvider $provider)
    {
        parent::__construct($context);
    }

    protected function websiteParam(): ?int
    {
        $website = $this->getRequest()->getParam('website');
        return is_string($website) && ctype_digit($website) ? (int) $website : null;
    }

    protected function userId(): int
    {
        return (int) $this->_auth->getUser()->getId();
    }

    protected function back(string $result, ?int $websiteId): Redirect
    {
        $messages = \IntentsFi\Checkout\Block\Adminhtml\System\Config\Connect::messages();
        if (isset($messages[$result])) {
            [$type, $text] = $messages[$result];
            match ($type) {
                'success' => $this->messageManager->addSuccessMessage($text),
                'warning' => $this->messageManager->addWarningMessage($text),
                'notice' => $this->messageManager->addNoticeMessage($text),
                default => $this->messageManager->addErrorMessage($text),
            };
        }
        $params = ['section' => 'payment'];
        if ($websiteId !== null) {
            $params['website'] = $websiteId;
        }
        $redirect = $this->resultRedirectFactory->create();
        return $redirect->setPath('adminhtml/system_config/edit', $params);
    }
}
