<?php

declare(strict_types=1);

namespace Magneto\CustomerSalesStats\Ui\Component\Listing\Column;

use Magento\Directory\Model\Currency;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

class LifetimeRevenue extends Column
{
    private ?Currency $loadedCurrency = null;

    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly Currency $currency,
        private readonly ScopeConfigInterface $scopeConfig,
        array $components = [],
        array $data = []
    ) {
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    public function prepareDataSource(array $dataSource): array
    {
        if (!isset($dataSource['data']['items'])) {
            return $dataSource;
        }

        $fieldName     = $this->getData('name');
        $currencyModel = $this->getCurrencyModel();

        foreach ($dataSource['data']['items'] as &$item) {
            $value = isset($item[$fieldName]) && $item[$fieldName] !== null
                ? (float) $item[$fieldName]
                : null;

            $item[$fieldName] = $value !== null
                ? $currencyModel->format($value, [], false)
                : '—';
        }

        return $dataSource;
    }

    private function getCurrencyModel(): Currency
    {
        if ($this->loadedCurrency === null) {
            $currencyCode = (string) $this->scopeConfig->getValue(
                Currency::XML_PATH_CURRENCY_BASE,
                ScopeConfigInterface::SCOPE_TYPE_DEFAULT
            );
            $this->loadedCurrency = $this->currency->load($currencyCode);
        }

        return $this->loadedCurrency;
    }
}
