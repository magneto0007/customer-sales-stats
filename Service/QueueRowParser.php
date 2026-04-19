<?php

declare(strict_types=1);

namespace Magneto\CustomerSalesStats\Service;

use Psr\Log\LoggerInterface;

/**
 * Parses raw queue rows into validated totals, valid IDs, and invalid IDs.
 */
class QueueRowParser
{
    public function __construct(
        private readonly RevenueCalculator $calculator,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array{totals: array<string, float>, queueIds: array<int, int>, invalidIds: array<int, int>}
     */
    public function parse(array $rows): array
    {
        $totals     = [];
        $queueIds   = [];
        $invalidIds = [];

        foreach ($rows as $row) {
            $queueId = (int) $row['queue_id'];
            $email   = strtolower(trim((string) $row['customer_email']));

            if ($email === '') {
                $invalidIds[] = $queueId;
                $this->logger->warning(
                    'CustomerSalesStats: skipping queue row with empty email',
                    ['queue_id' => $queueId, 'order_id' => $row['order_id']]
                );
                continue;
            }

            $globalTotal = $this->calculator->toGlobalCurrency(
                (float) $row['base_grand_total'],
                (float) $row['base_to_global_rate']
            );

            $totals[$email] = round(($totals[$email] ?? 0.0) + $globalTotal, 4);
            $queueIds[]     = $queueId;
        }

        return [
            'totals'     => $totals,
            'queueIds'   => $queueIds,
            'invalidIds' => $invalidIds,
        ];
    }
}
