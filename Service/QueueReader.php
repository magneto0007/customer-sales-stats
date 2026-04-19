<?php

declare(strict_types=1);

namespace Magneto\CustomerSalesStats\Service;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;

/**
 * Reads from the CLR queue table. Does not modify data.
 */
class QueueReader
{
    private const QUEUE_TABLE = 'clr_order_complete_queue';
    private const BATCH_SIZE  = 1000;
    private const MAX_RETRIES = 3;

    public function __construct(
        private readonly ResourceConnection $resource
    ) {}

    public function getBatchSize(): int
    {
        return self::BATCH_SIZE;
    }

    public function getMaxRetries(): int
    {
        return self::MAX_RETRIES;
    }

    public function getQueueTableName(): string
    {
        return $this->resource->getTableName(self::QUEUE_TABLE);
    }

    /**
     * Claim a batch of unprocessed rows with FOR UPDATE SKIP LOCKED.
     * Must be called inside an active transaction.
     *
     * @return array<int, array<string, mixed>>
     */
    public function claimBatch(AdapterInterface $connection): array
    {
        $select = $connection->select()
            ->from($this->getQueueTableName())
            ->where('retry_count < ?', self::MAX_RETRIES)
            ->order('queue_id ASC')
            ->limit(self::BATCH_SIZE);

        $sql = $select->assemble() . ' FOR UPDATE SKIP LOCKED';

        return $connection->fetchAll($sql, $select->getBind());
    }

    /**
     * Fetch rows that have reached the retry ceiling.
     *
     * @param array<int, int> $queueIds
     * @return array<int, array<string, mixed>>
     */
    public function fetchExhaustedRows(AdapterInterface $connection, array $queueIds): array
    {
        if (empty($queueIds)) {
            return [];
        }

        return $connection->fetchAll(
            $connection->select()
                ->from($this->getQueueTableName())
                ->where('queue_id IN (?)', $queueIds)
                ->where('retry_count >= ?', self::MAX_RETRIES)
        );
    }
}
