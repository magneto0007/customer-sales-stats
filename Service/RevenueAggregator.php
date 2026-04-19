<?php

declare(strict_types=1);

namespace Magneto\CustomerSalesStats\Service;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;

class RevenueAggregator
{
    private const AGG_TABLE = 'clr_customer_lifetime_revenue';

    public function __construct(
        private readonly ResourceConnection $resource
    ) {}

    public function getAggTableName(): string
    {
        return $this->resource->getTableName(self::AGG_TABLE);
    }

    /**
     * @param array<string, float> $totals Map of customer_email => revenue amount.
     */
    public function upsert(AdapterInterface $connection, array $totals): void
    {
        if (empty($totals)) {
            return;
        }

        $upsertRows = [];
        foreach ($totals as $email => $amount) {
            $upsertRows[] = [
                'customer_email'   => $email,
                'lifetime_revenue' => $amount,
            ];
        }

        $connection->insertOnDuplicate(
            $this->getAggTableName(),
            $upsertRows,
            [
                'lifetime_revenue' => new \Zend_Db_Expr(
                    $connection->quoteIdentifier('lifetime_revenue')
                    . ' + VALUES(' . $connection->quoteIdentifier('lifetime_revenue') . ')'
                ),
            ]
        );
    }
}
