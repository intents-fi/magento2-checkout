<?php
declare(strict_types=1);

namespace IntentsFi\Checkout\Block\Adminhtml\System\Config;

use IntentsFi\Checkout\Model\Connect\FlowProvider;
use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * The Connect panel at the top of the settings: "Connect intents.fi", or who this scope is
 * connected as, with "Go live" and "Disconnect". Never shows a key.
 */
class Connect extends Field
{
    /**
     * @var string
     */
    protected $_template = 'IntentsFi_Checkout::system/config/connect.phtml';

    public function __construct(Context $context, private readonly FlowProvider $provider, array $data = [])
    {
        parent::__construct($context, $data);
    }

    /**
     * What each result of Start, Callback and Disconnect says, as [message type, text].
     *
     * @return array<string, array{0: string, 1: \Magento\Framework\Phrase}>
     */
    public static function messages(): array
    {
        $again = __('Press Connect to Intents again.');
        return [
            'connected' => ['success', __('Connected. Your keys and payment notifications are set up.')],
            'connected_no_webhook' => ['warning', __('Connected, but payment notifications could not be set up, so paid orders will not update by themselves. Intents only sends them to an https:// address your store answers on. Press Connect to Intents again once that is fixed.')],
            'disconnected' => ['success', __('Disconnected. The keys this store used have been revoked.')],
            'not_connected' => ['notice', __('This scope is not connected through Connect, so there was nothing to disconnect.')],
            'cancelled' => ['notice', __('Connection cancelled. Nothing was changed.')],
            'live_locked' => ['warning', __('Live payments are not open for your account yet. Finish the go-live checklist on your Intents dashboard (a verified email and a payout wallet), then press Go live again.')],
            'origin' => ['error', __('Connect needs this store\'s addresses on one domain. Enter your keys manually instead.')],
            'https' => ['error', __('Connect needs your store on https://. Enter your keys manually instead, or switch the store to https.')],
            'expired' => ['error', __('That connection took too long and has expired. %1', $again)],
            'state' => ['error', __('That connection was not started from this admin account, so it was ignored. %1', $again)],
            'no_request' => ['error', __('There was no connection waiting for this admin account. %1', $again)],
            'bad_code' => ['error', __('Intents sent back something this store could not use. %1', $again)],
            'exchange_failed' => ['error', __('The connection could not be finished. Details are in var/log/intentsfi_checkout.log. %1', $again)],
        ];
    }

    public function render(AbstractElement $element)
    {
        $element->unsScope()->unsCanUseWebsiteValue()->unsCanUseDefaultValue();
        return parent::render($element);
    }

    protected function _getElementHtml(AbstractElement $element)
    {
        return $this->_toHtml();
    }

    /** @return array<string, mixed>|null */
    public function getConnection(): ?array
    {
        return $this->provider->settings($this->websiteId())->connection();
    }

    public function hasKey(): bool
    {
        return $this->provider->settings($this->websiteId())->apiKey() !== '';
    }

    public function getStartUrl(string $mode): string
    {
        return $this->getUrl('intentsfi_checkout/connect/start', $this->scopeParams() + ['mode' => $mode]);
    }

    public function getDisconnectUrl(): string
    {
        return $this->getUrl('intentsfi_checkout/connect/disconnect', $this->scopeParams());
    }

    public function getSiteUrl(): string
    {
        return $this->provider->urls()->siteUrl($this->websiteId());
    }

    private function websiteId(): ?int
    {
        $website = $this->getRequest()->getParam('website');
        return is_string($website) && ctype_digit($website) ? (int) $website : null;
    }

    /** @return array<string, int> */
    private function scopeParams(): array
    {
        $id = $this->websiteId();
        return $id === null ? [] : ['website' => $id];
    }
}
