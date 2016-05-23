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

    public static function getAllAttributes($id = 0)
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
                AND `val`.`type` = "attribute"

                WHERE TRUE';

        if ($id) {
            $sql .= '
                AND   `attr`.`id_attribute_group` = ' . (int) $id;
        }

        $sql .= '
                ORDER BY `attr`.`position` ASC;';

        return array_map(function ($row) {
            $row['id'] = (int) $row['id'];
            $row['choosen'] = (bool) $row['choosen'];

            return $row;
        }, DB::getInstance(_PS_USE_SQL_SLAVE_)->executeS($sql));
    }
}
