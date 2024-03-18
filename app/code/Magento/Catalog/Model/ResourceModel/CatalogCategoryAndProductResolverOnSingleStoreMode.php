<?php
/************************************************************************
 *
 * Copyright 2024 Adobe
 * All Rights Reserved.
 *
 * NOTICE: All information contained herein is, and remains
 * the property of Adobe and its suppliers, if any. The intellectual
 * and technical concepts contained herein are proprietary to Adobe
 * and its suppliers and are protected by all applicable intellectual
 * property laws, including trade secret and copyright laws.
 * Dissemination of this information or reproduction of this material
 * is strictly forbidden unless prior written permission is obtained
 * from Adobe.
 * ************************************************************************
 */
declare(strict_types=1);

namespace Magento\Catalog\Model\ResourceModel;

use Exception;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\Store;

/**
 * Migrate related catalog category and product tables for single store view mode
 */
class CatalogCategoryAndProductResolverOnSingleStoreMode
{
    /**
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * Process the Catalog and Product tables and migrate to single store view mode
     *
     * @param int $storeId
     * @param string $table
     * @return void
     * @throws CouldNotSaveException
     */
    private function process(int $storeId, string $table): void
    {
        $catalogProductTable = $this->resourceConnection->getTableName($table);

        $catalogProducts = $this->getCatalogProducts($table, $storeId);
        $rowIds = [];
        $attributeIds = [];
        $valueIds = [];
        try {
            if ($catalogProducts) {
                foreach ($catalogProducts as $catalogProduct) {
                    $rowIds[] = $catalogProduct['row_id'];
                    $attributeIds[] = $catalogProduct['attribute_id'];
                    $valueIds[] = $catalogProduct['value_id'];
                }
                $this->massDelete($catalogProductTable, $attributeIds, $rowIds);
                $this->massUpdate($catalogProductTable, $valueIds);
            }
        } catch (LocalizedException $e) {
            throw new CouldNotSaveException(
                __($e->getMessage()),
                $e
            );
        }
    }

    /**
     * Migrate catalog category and product tables
     *
     * @param int $storeId
     * @throws Exception
     */
    public function migrateCatalogCategoryAndProductTables(int $storeId): void
    {
        $connection = $this->resourceConnection->getConnection();
        $tables = [
            'catalog_category_entity_datetime',
            'catalog_category_entity_decimal',
            'catalog_category_entity_int',
            'catalog_category_entity_text',
            'catalog_category_entity_varchar',
            'catalog_product_entity_datetime',
            'catalog_product_entity_decimal',
            'catalog_product_entity_gallery',
            'catalog_product_entity_int',
            'catalog_product_entity_text',
            'catalog_product_entity_varchar',
        ];
        try {
            $connection->beginTransaction();
            foreach ($tables as $table) {
                $this->process($storeId, $table);
            }
            $connection->commit();
        } catch (Exception $exception) {
            $connection->rollBack();
        }
    }

    /**
     * Delete default store related products
     *
     * @param $catalogProductTable
     * @param array $attributeIds
     * @param array $rowIds
     * @return void
     */
    private function massDelete($catalogProductTable, array $attributeIds, array $rowIds): void
    {
        $connection = $this->resourceConnection->getConnection();

        $connection->delete(
            $catalogProductTable,
            [
                'store_id = ?' => Store::DEFAULT_STORE_ID,
                'attribute_id IN(?)' => $attributeIds,
                'row_id IN(?)' => $rowIds
            ]
        );
    }

    /**
     * Update default store related products
     *
     * @param $catalogProductTable
     * @param array $valueIds
     * @return void
     */
    private function massUpdate($catalogProductTable, array $valueIds): void
    {
        $connection = $this->resourceConnection->getConnection();

        $connection->update(
            $catalogProductTable,
            ['store_id' => Store::DEFAULT_STORE_ID],
            ['value_id IN(?)' => $valueIds]
        );
    }

    /**
     * Get list of products
     *
     * @param string $table
     * @param int $storeId
     * @return array
     */
    private function getCatalogProducts(string $table, int $storeId): array
    {
        $connection = $this->resourceConnection->getConnection();
        $catalogProductTable = $this->resourceConnection->getTableName($table);
        $select = $connection->select()
            ->from($catalogProductTable, ['value_id', 'attribute_id', 'row_id'])
            ->where('store_id = ?', $storeId);
        return $connection->fetchAll($select);
    }
}
