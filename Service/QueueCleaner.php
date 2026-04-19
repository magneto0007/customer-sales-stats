<?php

declare(strict_types=1);

namespace Magneto\CustomerSalesStats\Service;

use Magento\Framework\DB\Adapter\AdapterInterface;

/**
 * Modifies queue rows: deletes processed, marks invalid as exhausted,
 * increments retry counts on failure.
 */
class QueueCleaner
{
    public function __construct(
        private readonly QueueReader $queueReader
    ) {}

    /**
     * Delete successfully processed rows from the queue.
     *
     * @param array<int, int> $queueIds
     */
    public function deleteProcessed(AdapterInterface $connection, array $queueIds): void
    {
        if (empty($queueIds)) {
            return;
        }

        $connection->delete(
            $this->queueReader->getQueueTableName(),
            ['queue_id IN (?)' => $queueIds]
        );
    }

    /**
     * Mark rows with invalid data as exhausted so they stop being picked up
     * but remain in the table for auditing.
     *
     * @param array<int, int> $queueIds
     */
    public function markAsExhausted(AdapterInterface $connection, array $queueIds): void
    {
        if (empty($queueIds)) {
            return;
        }

        $connection->update(
            $this->queueReader->getQueueTableName(),
            ['retry_count' => $this->queueReader->getMaxRetries()],
            ['queue_id IN (?)' => $queueIds]
        );
    }

    /**
     * Increment retry_count for rows that fail to process.
     *
     * @param array<int, int> $queueIds
     */
    public function incrementRetryCount(AdapterInterface $connection, array $queueIds): void
    {
        if (empty($queueIds)) {
            return;
        }

        $connection->update(
            $this->queueReader->getQueueTableName(),
            ['retry_count' => new \Zend_Db_Expr('retry_count + 1')],
            ['queue_id IN (?)' => $queueIds]
        );
    }
}
