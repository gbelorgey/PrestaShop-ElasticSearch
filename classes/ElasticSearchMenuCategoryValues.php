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
}
