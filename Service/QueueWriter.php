<?php

declare(strict_types=1);

namespace Magneto\CustomerSalesStats\Service;

use Magento\Framework\App\ResourceConnection;
use Magento\Sales\Api\Data\OrderInterface;
use Psr\Log\LoggerInterface;

class QueueWriter
{
    private const QUEUE_TABLE = 'clr_order_complete_queue';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly LoggerInterface $logger
    ) {}

    public function enqueue(OrderInterface $order): void
    {
        $orderId = (int) $order->getEntityId();
        $email   = strtolower(trim((string) $order->getCustomerEmail()));

        if ($orderId === 0 || $email === '') {
            return;
        }

        try {
            $this->resource->getConnection()->insertOnDuplicate(
                $this->resource->getTableName(self::QUEUE_TABLE),
                [
                    'order_id'            => $orderId,
                    'customer_email'      => $email,
                    'base_grand_total'    => (float) $order->getBaseGrandTotal(),
                    'base_to_global_rate' => (float) ($order->getBaseToGlobalRate() ?: 1.0),
                ],
                ['customer_email', 'base_grand_total', 'base_to_global_rate']
            );
        } catch (\Exception $e) {
            $this->logger->error(
                \sprintf('CustomerSalesStats: failed to queue order %d', $orderId),
                ['exception' => $e->getMessage()]
            );
        }
    }
}
