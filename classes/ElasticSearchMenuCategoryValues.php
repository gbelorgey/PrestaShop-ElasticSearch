<?php

class ElasticSearchMenuCategoryValues extends ObjectModel
{
    public $id_menu_category;
    public $id_shop;
    public $type;
    public $type_id;
    public $value;
    public $date_add;
    public $date_upd;

    public static $definition = array(
        'table' => 'elasticsearch_menu_category_values',
        'primary' => 'id_elasticsearch_menu_category_values',
        'fields' => array(
            'id_menu_category' => array(
                'type'     => self::TYPE_INT,
                'validate' => 'isUnsignedId',
                'required' => true,
            ),
            'id_shop' => array(
                'type'     => self::TYPE_INT,
                'validate' => 'isUnsignedId',
                'required' => true,
            ),
            'type' => array(
                'type'     => self::TYPE_STRING,
                'size'     => 50,
                'required' => true,
            ),
            'type_id' => array(
                'type'     => self::TYPE_INT,
                'validate' => 'isUnsignedId',
                'required' => true,
            ),
            'value' => array(
                'type'     => self::TYPE_INT,
                'validate' => 'isInt',
                'required' => true,
            ),
            'date_add' => array(
                'type'      => self::TYPE_DATE,
                'validate'  => 'isDateFormat',
                'copy_post' => false,
            ),
            'date_upd' => array(
                'type'      => self::TYPE_DATE,
                'validate'  => 'isDateFormat',
                'copy_post' => false,
            ),
        ),
    );

    public static function getAllAttributes(array $categoriesId, array $shopsId, array $typeId = array())
    {
        $sql = 'SELECT
                      `attr`.`id_attribute` AS `id`
                    , `attr_lang`.`name` AS `name`
                    , !ISNULL(`val`.`id_elasticsearch_menu_category_values`) AS `choosen`

                FROM `' . _DB_PREFIX_ . 'attribute` AS `attr`

                LEFT JOIN `' . _DB_PREFIX_ . 'attribute_lang` AS `attr_lang`
                ON  `attr_lang`.`id_attribute` = `attr`.`id_attribute`
                AND `attr_lang`.`id_lang` = ' . (int) Context::getContext()->language->id . '

                LEFT JOIN `' . _DB_PREFIX_ . 'elasticsearch_menu_category_values` AS `val`
                ON  `val`.`type_id` = `attr`.`id_attribute_group`
                AND `val`.`value` = `attr`.`id_attribute`
                AND `val`.`id_menu_category` IN (' . implode(', ', array_map('intval', (array) $categoriesId)) . ')
                AND `val`.`id_shop` IN (' . implode(', ', array_map('intval', (array) $shopsId)) . ')
                AND `val`.`type` = "attribute"

                WHERE TRUE';

        if ($typeId) {
            $sql .= '
                AND   `attr`.`id_attribute_group` IN (' . implode(', ', array_map('intval', (array) $typeId)) . ')';
        }

        $sql .= '
                ORDER BY `attr`.`position` ASC;';

        return array_map(function ($row) {
            $row['id'] = (int) $row['id'];
            $row['choosen'] = (bool) $row['choosen'];

            return $row;
        }, DB::getInstance(_PS_USE_SQL_SLAVE_)->executeS($sql));
    }

    public static function getAllFeatures(array $categoriesId, array $shopsId, array $typeId = array())
    {
        $sql = 'SELECT
                      `fv`.`id_feature_value` AS `id`
                    , `fvl`.`value` AS `name`
                    , !ISNULL(`val`.`id_elasticsearch_menu_category_values`) AS `choosen`

                FROM `' . _DB_PREFIX_ . 'feature_value` AS `fv`

                LEFT JOIN `' . _DB_PREFIX_ . 'feature_value_lang` AS `fvl`
                ON  `fvl`.`id_feature_value` = `fv`.`id_feature_value`
                AND `fvl`.id_lang = ' . (int) Context::getContext()->language->id . '

                LEFT JOIN `' . _DB_PREFIX_ . 'elasticsearch_menu_category_values` AS `val`
                ON  `val`.`type_id` = `fv`.`id_feature`
                AND `val`.`value` = `fv`.`id_feature_value`
                AND `val`.`id_menu_category` IN (' . implode(', ', array_map('intval', (array) $categoriesId)) . ')
                AND `val`.`id_shop` IN (' . implode(', ', array_map('intval', (array) $shopsId)) . ')
                AND `val`.`type` = "feature"

                WHERE TRUE';

        if ($typeId) {
            $sql .= '
                AND   `fv`.`id_feature` IN (' . implode(', ', array_map('intval', (array) $typeId)) . ')';
        }

        $sql .= '
                ORDER BY `fv`.`position` ASC;';

        return array_map(function ($row) {
            $row['id'] = (int) $row['id'];
            $row['choosen'] = (bool) $row['choosen'];

            return $row;
        }, DB::getInstance(_PS_USE_SQL_SLAVE_)->executeS($sql));
    }

    public static function deleteValues(array $categoriesId, array $shopsId, $type, array $typeId, array $ids)
    {
        $sql = 'DELETE

                FROM `' . _DB_PREFIX_ . 'elasticsearch_menu_category_values`

                WHERE TRUE
                AND   `id_menu_category` IN (' . implode(', ', array_map('intval', (array) $categoriesId)) . ')
                AND   `id_shop` IN (' . implode(', ', array_map('intval', (array) $shopsId)) . ')
                AND   `type` = "' . pSQL($type) . '"
                AND   `type_id` IN (' . implode(', ', array_map('intval', (array) $typeId)) . ')
                AND   `value` NOT IN (' . implode(', ', array_map('intval', (array) $ids)) . ');';

        return Db::getInstance()->execute($sql);
    }

    public static function setAttributes(array $categoriesId, array $shopsId, array $typeId, array $ids)
    {
        if (!static::deleteValues($categoriesId, $shopsId, 'attribute', $typeId, $ids)) {
            return false;
        }

        $sql = 'INSERT INTO `' . _DB_PREFIX_ . 'elasticsearch_menu_category_values`
                  (
                      `id_menu_category`
                    , `id_shop`
                    , `type`
                    , `type_id`
                    , `value`
                    , `date_add`
                  )

                SELECT
                      `cat`.`id_category`
                    , `shop`.`id_shop`
                    , "attribute"
                    , `attr`.`id_attribute_group`
                    , `attr`.`id_attribute`
                    , NOW()

                FROM `' . _DB_PREFIX_ . 'attribute` AS `attr`

                JOIN `' . _DB_PREFIX_ . 'category` AS `cat`

                JOIN `' . _DB_PREFIX_ . 'shop` AS `shop`

                WHERE TRUE
                AND   `attr`.`id_attribute_group` IN (' . implode(', ', array_map('intval', $typeId)) . ')
                AND   `attr`.`id_attribute` IN (' . implode(', ', array_map('intval', $ids)) . ')
                AND   `cat`.`id_category` IN (' . implode(', ', array_map('intval', $categoriesId)) . ')
                AND   `shop`.`id_shop` IN (' . implode(', ', array_map('intval', $shopsId)) . ')

                ON DUPLICATE KEY UPDATE `date_upd` = NOW();';

        return DB::getInstance()->execute($sql);
    }

    public static function setFeatures(array $categoriesId, array $shopsId, array $typeId, array $ids)
    {
        if (!static::deleteValues($categoriesId, $shopsId, 'feature', $typeId, $ids)) {
            return false;
        }

        $sql = 'INSERT INTO `' . _DB_PREFIX_ . 'elasticsearch_menu_category_values`
                  (
                      `id_menu_category`
                    , `id_shop`
                    , `type`
                    , `type_id`
                    , `value`
                    , `date_add`
                  )

                SELECT
                      `cat`.`id_category`
                    , `shop`.`id_shop`
                    , "feature"
                    , `fv`.`id_feature`
                    , `fv`.`id_feature_value`
                    , NOW()

                FROM `' . _DB_PREFIX_ . 'feature_value` AS `fv`

                JOIN `' . _DB_PREFIX_ . 'category` AS `cat`

                JOIN `' . _DB_PREFIX_ . 'shop` AS `shop`

                WHERE TRUE
                AND   `fv`.`id_feature` IN (' . implode(', ', array_map('intval', $typeId)) . ')
                AND   `fv`.`id_feature_value` IN (' . implode(', ', array_map('intval', $ids)) . ')
                AND   `cat`.`id_category` IN (' . implode(', ', array_map('intval', $categoriesId)) . ')
                AND   `shop`.`id_shop` IN (' . implode(', ', array_map('intval', $shopsId)) . ')

                ON DUPLICATE KEY UPDATE `date_upd` = NOW();';

        return DB::getInstance()->execute($sql);
    }
}
