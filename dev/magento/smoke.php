<?php
/**
 * Helper for smoke.sh, run inside the Magento install:
 *
 *   php smoke.php pay-url <increment id>   create (or reuse) the order's invoice; print its hosted URL
 *   php smoke.php state <increment id>     print the order's state, status, invoices and our payment info
 *   php smoke.php sign <secret> <file>     print an X-Intents-Signature header for a body
 */
declare(strict_types=1);

use Magento\Framework\App\Bootstrap;

require getcwd() . '/app/bootstrap.php';

$bootstrap = Bootstrap::create(BP, $_SERVER);
$om = $bootstrap->getObjectManager();
$om->get(\Magento\Framework\App\State::class)->setAreaCode('frontend');

[, $command, $arg, $arg2] = array_pad($argv, 4, '');

$load = static function (string $incrementId) use ($om): \Magento\Sales\Model\Order {
    $order = $om->create(\Magento\Sales\Model\Order::class)->loadByIncrementId($incrementId);
    if (!$order->getEntityId()) {
        fwrite(STDERR, "no order {$incrementId}\n");
        exit(1);
    }
    return $order;
};

switch ($command) {
    case 'pay-url':
        echo $om->get(\IntentsFi\Checkout\Model\InvoiceManager::class)->paymentUrl($load($arg)), "\n";
        break;
    case 'state':
        $order = $load($arg);
        echo json_encode([
            'state' => $order->getState(),
            'status' => $order->getStatus(),
            'invoices' => $order->getInvoiceCollection()->getSize(),
            'grand_total' => $order->getGrandTotal(),
            'payment' => array_intersect_key(
                (array) $order->getPayment()->getAdditionalInformation(),
                array_flip(['intentsfi_invoice_id', 'intentsfi_reference', 'intentsfi_mode', 'intentsfi_event_rank', 'intentsfi_held_by', 'intentsfi_sequence'])
            ),
            'comments' => array_map(
                static fn ($h) => (string) $h->getComment(),
                array_values($order->getStatusHistories())
            ),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
        break;
    case 'sign':
        echo \IntentsFi\Checkout\Model\Signature::sign($arg, (string) file_get_contents($arg2), time()), "\n";
        break;
    default:
        fwrite(STDERR, "usage: smoke.php pay-url|state <increment id> | sign <secret> <file>\n");
        exit(2);
}
