<?php

declare(strict_types=1);

namespace Magneto\CustomerSalesStats\Service;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Psr\Log\LoggerInterface;

/**
 * Core queue processing logic.
 *
 * Can be invoked by the cron entry point, a RabbitMQ consumer,
 * a CLI command, or any other trigger.
 */
class QueueProcessor
{
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly QueueReader $queueReader,
        private readonly QueueCleaner $queueCleaner,
        private readonly QueueRowParser $rowParser,
        private readonly RevenueAggregator $aggregator,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * Drain the queue in batches until empty.
     */
    public function processAll(): void
    {
        $connection = $this->resource->getConnection();

        do {
            $processedCount = $this->claimAndProcess($connection);
        } while ($processedCount === $this->queueReader->getBatchSize());
    }

    /**
     * Claim a batch, aggregate, upsert, delete — all in one transaction.
     *
     * @return int Number of rows processed (0 = queue empty or batch failed).
     */
    private function claimAndProcess(AdapterInterface $connection): int
    {
        $connection->beginTransaction();

        try {
            $rows = $this->queueReader->claimBatch($connection);

            if (empty($rows)) {
                $connection->commit();
                return 0;
            }

            $this->processBatch($connection, $rows);
            $connection->commit();

            return count($rows);
        } catch (\Exception $e) {
            $connection->rollBack();
            $this->handleBatchFailure($connection, $rows ?? [], $e);
            return 0;
        }
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function processBatch(AdapterInterface $connection, array $rows): void
    {
        $parsed = $this->rowParser->parse($rows);

        $this->queueCleaner->markAsExhausted($connection, $parsed['invalidIds']);

        if (empty($parsed['queueIds'])) {
            return;
        }

        $this->aggregator->upsert($connection, $parsed['totals']);
        $this->queueCleaner->deleteProcessed($connection, $parsed['queueIds']);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function handleBatchFailure(
        AdapterInterface $connection,
        array $rows,
        \Exception $e
    ): void {
        $queueIds = array_map(
            static fn (array $row): int => (int) $row['queue_id'],
            $rows
        );

        $this->logger->error(
            'CustomerSalesStats: batch failed, incrementing retry counts',
            ['exception' => $e->getMessage(), 'batch_size' => count($queueIds)]
        );

        if (empty($queueIds)) {
            return;
        }

        $this->queueCleaner->incrementRetryCount($connection, $queueIds);
        $this->logExhaustedRows($connection, $queueIds);
    }

    /**
     * @param array<int, int> $queueIds
     */
    private function logExhaustedRows(AdapterInterface $connection, array $queueIds): void
    {
        $exhausted = $this->queueReader->fetchExhaustedRows($connection, $queueIds);

        foreach ($exhausted as $row) {
            $this->logger->critical(
                'CustomerSalesStats: queue row exhausted retries — requires manual review',
                [
                    'queue_id'    => $row['queue_id'],
                    'order_id'    => $row['order_id'],
                    'email'       => $row['customer_email'],
                    'retry_count' => $row['retry_count'],
                ]
            );
        }
    }
}
