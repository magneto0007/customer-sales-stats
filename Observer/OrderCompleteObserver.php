<?php

declare(strict_types=1);

namespace Magneto\CustomerSalesStats\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
use Magneto\CustomerSalesStats\Service\OrderStateDetector;
use Magneto\CustomerSalesStats\Service\QueueWriter;

/**
 * Listens to sales_order_save_after to detect orders transitioning to 'complete'.
 */
class OrderCompleteObserver implements ObserverInterface
{
    public function __construct(
        private readonly OrderStateDetector $stateDetector,
        private readonly QueueWriter $queueWriter
    ) {}

    public function execute(Observer $observer): void
    {
        /** @var Order $order */
        $order = $observer->getEvent()->getData('order');

        if ($order === null) {
            return;
        }

        if (!$this->stateDetector->isNewlyCompleted($order)) {
            return;
        }

        $this->queueWriter->enqueue($order);
    }
}
