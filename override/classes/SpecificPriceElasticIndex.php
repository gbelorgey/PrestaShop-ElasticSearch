<?php

class SpecificPriceElasticIndex
{
    public static function setProductIndex($id_product, $id_shop, array $specific_prices)
    {
        $delete_query = 'DELETE FROM `'._DB_PREFIX_.'elasticsearch_specific_price_index` '.
            'WHERE `id_product` = '.(int)$id_product.' '.
            'AND `id_shop` = '.(int)$id_shop.' ';
        if (count($specific_prices) > 0) {
            $delete_query .= 'AND `id_specific_price` NOT IN ('.implode(',', $specific_prices).')';
        }
        Db::getInstance()->execute($delete_query);

        foreach ($specific_prices as $id_specific_price) {
            Db::getInstance()->execute(
                'INSERT IGNORE INTO `'._DB_PREFIX_.'elasticsearch_specific_price_index` '.
                'VALUES ('.(int)$id_specific_price.', '.(int)$id_product.', '.(int)$id_shop.', NOW())'
            );
        }

        return true;
    }
}
