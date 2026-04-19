<?php

declare(strict_types=1);

namespace Magneto\CustomerSalesStats\Service;

use Magento\Framework\Model\AbstractModel;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order;

class OrderStateDetector
{
    public function isNewlyCompleted(OrderInterface $order): bool
    {
        if ((string) $order->getState() !== Order::STATE_COMPLETE) {
            return false;
        }

        if (!($order instanceof AbstractModel)) {
            return false;
        }

        $previousState = (string) $order->getOrigData('state');

        return $previousState !== Order::STATE_COMPLETE;
    }
}
