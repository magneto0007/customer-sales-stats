<?php

declare(strict_types=1);

namespace Magneto\CustomerSalesStats\Plugin;

use Magento\Framework\App\ResourceConnection;
use Magento\Sales\Model\ResourceModel\Order\Grid\Collection;

class OrderGridCollectionPlugin
{
    private const AGGREGATE_TABLE = 'clr_customer_lifetime_revenue';
    private const ALIAS           = 'clr';

    public function __construct(private readonly ResourceConnection $resource) {}

    public function beforeLoad(
        Collection $subject,
        bool $printQuery = false,
        bool $logQuery = false
    ): array {
        if (!$subject->isLoaded()) {
            if (!array_key_exists(self::ALIAS, $subject->getSelect()->getPart('from'))) {
                $subject->getSelect()->joinLeft(
                    [self::ALIAS => $this->resource->getTableName(self::AGGREGATE_TABLE)],
                    \sprintf(
                        'main_table.customer_email = %s.customer_email',
                        self::ALIAS
                    ),
                    ['lifetime_revenue' => self::ALIAS . '.lifetime_revenue']
                );
            }
        }

        return [$printQuery, $logQuery];
    }
}
