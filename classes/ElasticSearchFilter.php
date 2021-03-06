<?php

require_once(_ELASTICSEARCH_CORE_DIR_.'AbstractFilter.php');

class ElasticSearchFilter extends AbstractFilter
{
    const FILENAME = 'ElasticSearchFilter';

    public static $cache = array();
    public $id_category;
    public $id_manufacturer;
    public $all_category_products = array();
    public $price_filter = array();
    public $weight_filter = array();
    private $selected_filters;
    protected static $allowedFilters = array();

    public function __construct()
    {
        parent::__construct(SearchService::ELASTICSEARCH_INSTANCE);
        $this->id_category = (int)Tools::getValue('id_category', Tools::getValue('id_elasticsearch_category'));
        $this->id_manufacturer = (int)Tools::getValue('id_manufacturer', Tools::getValue('id_elasticsearch_manufacturer'));
    }

    public function getFiltersProductsCountsAggregationQuery($enabled_filters)
    {
        $selected_filters = $this->getSelectedFilters();
        $id_currency = Context::getContext()->currency->id;
        $required_filters = array();

        foreach ($enabled_filters as $type => $enabled_filter) {
            switch ($type) {
                default:
                    $required_filters[] = array(
                        'aggregation_type' => 'terms',
                        'field' => $type,
                        'alias' => $type,
                        'filter' => $this->getProductsQueryByFilters($selected_filters, $type)
                    );
                    break;
                case self::FILTER_TYPE_PRICE:
                    if (!isset($price_query)) {
                        $price_query = $this->getProductsQueryByFilters($selected_filters, $type);
                    }

                    $required_filters[] = array(
                        'aggregation_type' => 'min',
                        'field' => 'price_min_'.$id_currency,
                        'alias' => 'price_min_'.$id_currency,
                        'filter' => $price_query
                    );
                    $required_filters[] = array(
                        'aggregation_type' => 'max',
                        'field' => 'price_max_'.$id_currency,
                        'alias' => 'price_max_'.$id_currency,
                        'filter' => $price_query
                    );
                    break;
                case self::FILTER_TYPE_DISCOUNT:
                    $required_filters[] = array(
                        'aggregation_type' => 'terms',
                        'field' => 'discount_'.$id_currency,
                        'alias' => 'discount_'.$id_currency,
                        'filter' => $this->getProductsQueryByFilters($selected_filters, $type)
                    );
                    break;
                case self::FILTER_TYPE_WEIGHT:
                    if (!isset($weight_query)) {
                        $weight_query = $this->getProductsQueryByFilters($selected_filters, $type);
                    }

                    $required_filters[] = array(
                        'aggregation_type' => 'min',
                        'field' => 'weight',
                        'alias' => 'min_weight',
                        'filter' => $weight_query
                    );
                    $required_filters[] = array(
                        'aggregation_type' => 'max',
                        'field' => 'weight',
                        'alias' => 'max_weight',
                        'filter' => $weight_query
                    );
                    break;
                case self::FILTER_TYPE_QUANTITY:
                    if (!Configuration::get('PS_STOCK_MANAGEMENT')) {
                        $required_filters[] = array(
                            'aggregation_type' => 'value_count',
                            'field' => 'quantity',
                            'alias' => 'in_stock',
                            'filter' => $this->getProductsQueryByFilters($selected_filters, $type)
                        );
                        break;
                    }

                    $quantity_query = $this->getProductsQueryByFilters($selected_filters, $type);

                    $qty_filter = array(
                        'aggregation_type' => 'terms',
                        'field' => 'quantity',
                        'alias' => 'in_stock',
                        'filter' => $quantity_query
                    );

                    $should = array(
                        array(
                            'range' => array(
                                'quantity' => array('gt' => 0)
                            )
                        ),
                        array(
                            'term' => array(
                                'out_of_stock' => AbstractFilter::PRODUCT_OOS_ALLOW_ORDERS
                            )
                        )
                    );

                    $global_oos_deny_orders = !Configuration::get('PS_ORDER_OUT_OF_STOCK');

                    //if ordering out of stock products is allowed globally, include products with global oos value
                    if (!$global_oos_deny_orders) {
                        $should[] = array(
                            'term' => array(
                                'out_of_stock' => AbstractFilter::PRODUCT_OOS_USE_GLOBAL
                            )
                        );
                    }

                    $qty_filter['filter']['bool']['should'] = $should;

                    $required_filters[] = $qty_filter;

                    //Start building out of stock query

                    //include products with quantity lower than 1
                    $qty_filter = array(
                        'aggregation_type' => 'terms',
                        'field' => 'quantity',
                        'alias' => 'out_of_stock',
                        'filter' => $quantity_query
                    );

                    $should = array(
                        array(
                            'bool' => array(
                                'must' => array(
                                    array(
                                        'range' => array(
                                            'quantity' => array('lt' => 1)
                                        )
                                    )
                                )
                            )
                        )
                    );

                    //if global "deny out of stock orders" setting is enabled,
                    // include products that use global oos value
                    if ($global_oos_deny_orders) {
                        $should[0]['bool']['must'][] = array(
                            'bool' => array(
                                'should' => array(
                                    array(
                                        'term' => array(
                                            'out_of_stock' => AbstractFilter::PRODUCT_OOS_USE_GLOBAL
                                        )
                                    ),
                                    array(
                                        'term' => array(
                                            'out_of_stock' => AbstractFilter::PRODUCT_OOS_DENY_ORDERS
                                        )
                                    )
                                )
                            )
                        );
                    } else {
                        //include only products that deny orders if out of stock
                        $should[0]['bool']['must'][] = array(
                            'term' => array(
                                'out_of_stock' => AbstractFilter::PRODUCT_OOS_DENY_ORDERS
                            )
                        );
                    }

                    $qty_filter['filter']['bool']['should'] = $should;

                    $required_filters[] = $qty_filter;
                    break;
                case self::FILTER_TYPE_MANUFACTURER:
                    $required_filters[] = array(
                        'aggregation_type' => 'terms',
                        'field' => 'id_manufacturer',
                        'alias' => 'id_manufacturer',
                        'filter' => $this->getProductsQueryByFilters($selected_filters, $type)
                    );
                    break;
                case self::FILTER_TYPE_ATTRIBUTE_GROUP:
                    foreach ($enabled_filter as $value) {
                        $required_filters[] = array(
                            'aggregation_type' => 'terms',
                            'field' => 'attribute_group_'.$value['id_value'],
                            'alias' => 'attribute_group_'.$value['id_value'],
                            'filter' => $this->getProductsQueryByFilters($selected_filters, $type)
                        );
                    }
                    break;
                case self::FILTER_TYPE_FEATURE:
                    foreach ($enabled_filter as $value) {
                        $required_filters[] = array(
                            'aggregation_type' => 'terms',
                            'field' => 'feature_'.$value['id_value'],
                            'alias' => 'feature_'.$value['id_value'],
                            'filter' => $this->getProductsQueryByFilters($selected_filters, $type)
                        );
                    }
                    break;
                case self::FILTER_TYPE_CATEGORY:
                    $required_filters[] = array(
                        'aggregation_type' => 'terms',
                        'field' => 'categories',
                        'alias' => 'categories',
                        'filter' => $this->getProductsQueryByFilters($selected_filters, $type)
                    );
                    break;
            }
        }

        return AbstractFilter::$search_service->getAggregationQuery($required_filters);
    }

    /**
     * @param $selected_filters array selected filters
     * @param bool $count_only return only number of results?
     * @return array|int array with products data | number of products
     */
    public function getProductsBySelectedFilters($selected_filters, $count_only = false)
    {
        //building search query for selected filters
        $query = $this->getProductsQueryByFilters($selected_filters);

        if ($count_only) {
            return AbstractFilter::$search_service->getDocumentsCount('products', $query);
        }

        $page = (int)Tools::getValue('p');

        if ($page < 1) {
            $page = 1;
        }

        $pagination = (int)Tools::getValue('n');
        $start = ($page - 1) * $pagination;

        if (empty($pagination)) {
            $pagination = $this->getProductsBySelectedFilters($selected_filters, true);
        }

        $order_by_values = array(0 => 'name', 1 => 'price', 6 => 'quantity', 7 => 'reference');
        $order_way_values = array(0 => 'asc', 1 => 'desc');

        $order_by = Tools::strtolower(
            Tools::getValue(
                'orderby',
                isset($order_by_values[(int)Configuration::get('PS_PRODUCTS_ORDER_BY')])
                    ? $order_by_values[(int)Configuration::get('PS_PRODUCTS_ORDER_BY')]
                    : null
            )
        );

        if ($order_by && !in_array($order_by, $order_by_values)) {
            $order_by = null;
        }

        $order_way = Tools::strtolower(
            Tools::getValue(
                'orderway',
                isset($order_way_values[(int)Configuration::get('PS_PRODUCTS_ORDER_WAY')])
                    ? $order_way_values[(int)Configuration::get('PS_PRODUCTS_ORDER_WAY')]
                    : null
            )
        );

        if ($order_by == 'name') {
            $order_by .= '_'.(int)Context::getContext()->language->id;
        }
        if (is_null($order_by) && $this->id_category) {
            $order_by = 'position_'.$this->id_category;
            $order_way = 'asc';
        }

        $required_fields = array(
            'out_of_stock',
            'id_category_default',
            'link_rewrite_'.Context::getContext()->language->id,
            'default_category_link_rewrite_'.Context::getContext()->language->id,
            'link_'.Context::getContext()->language->id,
            'name_'.Context::getContext()->language->id,
            'description_short_'.Context::getContext()->language->id,
            'description_'.Context::getContext()->language->id,
            'ean13',
            'id_image',
            'customizable',
            'minimal_quantity',
            'available_for_order',
            'show_price',
            'price',
            'quantity',
            'id_combination_default',
            'manufacturer_name',
            'in_stock_when_global_oos_deny_orders'
        );

        $partial_fields = $this->getPartialFields($required_fields);

        $products = AbstractFilter::$search_service->search(
            'products',
            $query,
            $pagination ? $pagination : null,
            $start,
            $order_by,
            $order_way,
            null,
            false,
            $partial_fields
        );

        $products_data = array();

        $global_allow_oosp = (int)Configuration::get('PS_ORDER_OUT_OF_STOCK');


        foreach ($products as $product) {
            $allow_oosp = $this->extractProductField($product, 'out_of_stock');
            $allow_oosp =
                $allow_oosp == AbstractFilter::PRODUCT_OOS_ALLOW_ORDERS ||
                ($allow_oosp == AbstractFilter::PRODUCT_OOS_USE_GLOBAL && $global_allow_oosp);

            $price = Product::getPriceStatic(
                $product['_id'],
                true,
                null,
                2
            );
            $price_without_reduction = Product::getPriceStatic(
                $product['_id'],
                true,
                null,
                2,
                null,
                false,
                false
            );
            $reduction_display = 0;
            if ($price_without_reduction > $price) {
                $reduction_display = round((1 - $price/$price_without_reduction) * 100);
            }

            $products_data[] = array(
                'id_product' => $product['_id'],
                'out_of_stock' => $this->extractProductField($product, 'out_of_stock'),
                'id_category_default' => $this->extractProductField($product, 'id_category_default'),
                'link_rewrite' => $this->extractProductField(
                    $product,
                    'link_rewrite_'.Context::getContext()->language->id
                ),
                'name' => $this->extractProductField($product, 'name_'.Context::getContext()->language->id),
                'description_short' => $this->extractProductField(
                    $product,
                    'description_short_'.Context::getContext()->language->id
                ),
                'ean13' => $this->extractProductField($product, 'ean13'),
                'id_image' => $this->extractProductField($product, 'id_image'),
                'customizable' => $this->extractProductField($product, 'customizable'),
                'minimal_quantity' => $this->extractProductField($product, 'minimal_quantity'),
                'available_for_order' => $this->extractProductField($product, 'available_for_order'),
                'show_price' => $this->extractProductField($product, 'show_price'),
                'quantity' => $this->extractProductField($product, 'quantity'),
                'id_product_attribute' => $this->extractProductField($product, 'id_combination_default'),
                'price' => $price,
                'price_tax_exc' => $this->extractProductField($product, 'price'),
                'price_without_reduction' => $price_without_reduction,
                'reduction_display' => $reduction_display,
                'allow_oosp' => $allow_oosp,
                'link' => $this->extractProductField($product, 'link_'.Context::getContext()->language->id),
                'manufacturer_name' => $this->extractProductField($product, 'manufacturer_name'),
                'instock' => $this->extractProductField($product, 'in_stock_when_global_oos_deny_orders')
            );
        }
        return $products_data;
    }

    public function extractProductField($product, $field_name)
    {
        return isset($product['fields']['data'][0][$field_name]) ? $product['fields']['data'][0][$field_name] : null;
    }

    /**
     * @param $selected_filters
     * @param array|string $exclude - exclude these filters from query
     * @return array
     */
    public function getProductsQueryByFilters($selected_filters, $exclude = array())
    {
        $query = array();
        $search_values = array();

        if (!is_array($exclude)) {
            $exclude = array($exclude);
        }

        $price_counter = 0;
        $weight_counter = 0;

        foreach ($selected_filters as $key => $filter_values) {
            if (!count($filter_values)) {
                continue;
            }

            preg_match('/^(.*[^_0-9])/', $key, $res);
            $key = $res[1];

            if (in_array($key, $exclude)) {
                continue;
            }

            foreach ($filter_values as $value) {
                switch ($key) {
                    case 'id_feature':
                        $parts = explode('_', $value);

                        if (count($parts) != 2) {
                            break;
                        }

                        $search_values['id_feature'][] = array(
                            'term' => array(
                                'feature_'.$parts[0] => $parts[1]
                            )
                        );
                        break;

                    case 'id_attribute_group':
                        $parts = explode('_', $value);

                        if (count($parts) != 2) {
                            break;
                        }

                        $search_values['id_attribute_group'][] = array(
                            'term' => array(
                                'attribute_group_'.$parts[0] => $parts[1]
                            )
                        );
                        break;

                    case 'category':
                        $search_values['categories'][] = array(
                            'term' => array(
                                'categories' => $value
                            )
                        );
                        break;

                    case 'quantity':
                        //If in_stock was already processed it means
                        // that both "In stock" and "Not available" values are selected in filter
                        //in this case we do not need to add quantity filter at all -
                        // need to remove previously set filter.
                        if (isset($search_values['in_stock'])) {
                            unset($search_values['in_stock']);
                            break;
                        }

                        //if stock management is disabled all products are available
                        if (!Configuration::get('PS_STOCK_MANAGEMENT')) {
                            break;
                        }

                        $global_oos_deny_orders = !Configuration::get('PS_ORDER_OUT_OF_STOCK');

                        $search_values['in_stock'][] = array(
                            'term' => array(
                                'in_stock_when_global_oos_'.($global_oos_deny_orders ? 'deny' : 'allow').'_orders' => $value
                            )
                        );

                        break;

                    case 'manufacturer':
                        $search_values['manufacturer'][] = array(
                            'term' => array(
                                'id_manufacturer' => $value
                            )
                        );
                        break;

                    case 'condition':
                        $search_values['condition'][] = array(
                            'term' => array(
                                'condition' => $value
                            )
                        );
                        break;

                    case 'weight':
                        if ($weight_counter == 0) {
                            $search_values['weight']['gte'] = $value;
                        } elseif ($weight_counter == 1) {
                            $search_values['weight']['lte'] = $value;
                        }

                        $weight_counter++;
                        break;

                    case 'price':
                        if ($price_counter == 0) {
                            $search_values['price']['gte'] = (int)$value;
                        } elseif ($price_counter == 1) {
                            $search_values['price']['lte'] = ceil($value);
                        }

                        $price_counter++;
                        break;

                    case 'discount':
                        $search_values['discount'][] = array(
                            'term' => array(
                                'discount' => $value
                            )
                        );
                        break;
                }
            }
        }

        $query['bool']['must'] = $this->getQueryFromSearchValues($search_values);

        //completing categories query
        $query['bool']['must'][] = $this->getCurrentControllerQuery();

        return $query;
    }

    /**
     * Gets ElasticSearch query for current category. If full tree setting is enabled, includes
     * subcategories in query too.
     * @return array query for category/ies
     */
    public function getCurrentControllerQuery()
    {
        if ($this->id_category) {
            if (!$this->full_tree) {
                return array(
                    'term' => array(
                        'categories' => $this->id_category

                    )
                );
            }
            $subcategories = $this->getSubcategories(true);

            if ($subcategories) {
                $query = array(
                    'bool' => array(
                        'should' => array()
                    )
                );

                foreach ($subcategories as $subcategory) {
                    $query['bool']['should'][] = array(
                        'term' => array(
                            'categories' => $subcategory['id_category']
                        )
                    );
                }

            } else {
                $query = array(
                    'term' => array(
                        'categories' => $this->id_category
                    )
                );
            }
        }

        if ($this->id_manufacturer) {
            $query = array(
                'term' => array(
                    'id_manufacturer' => $this->id_manufacturer
                )
            );
        }

        return $query;
    }

    /**
     * Returns formatted query to use in ElasticSearch requests
     * @param $search_values array values that should be included in search query
     * @return array
     */
    public function getQueryFromSearchValues(array $search_values)
    {
        $query = array();
        $should_query = array();
        foreach ($search_values as $key => $value) {
            if (in_array($key, array('categories', 'condition', 'manufacturer', 'in_stock', 'id_attribute_group'))) {
                $query[] = array(
                    'bool' => array(
                        'should' => $value
                    )
                );
            } elseif ($key == 'id_feature') {
                $features = array();
                foreach ($value as $val) {
                    $term = array_keys($val['term'])[0];
                    if (!isset($features[$term])) {
                        $features[$term] = array();
                    }
                    $features[$term][] = $val['term'][$term];
                }

                foreach ($features as $term => $values) {
                    if (count($values) > 1) {
                        $query[] = array(
                            'bool' => array(
                                'should' => array(
                                    'terms' => array(
                                        $term => $values
                                    )
                                )
                            )
                        );
                    } else {
                        $query[] = array(
                            'bool' => array(
                                'should' => array(
                                    'term' => array(
                                        $term => $values[0]
                                    )
                                )
                            )
                        );
                    }
                }
            } elseif ($key == 'weight') {
                $query[] = array(
                    'range' => array(
                        'weight' => $value
                    )
                );
            } elseif ($key == 'price') {
                $query[] = array(
                    'bool' => array(
                        'should' => array(
                            array(
                                'bool' => array(
                                    'must' => array(
                                        array(
                                            'range' => array(
                                                'price_min_'.(int)Context::getContext()->currency->id => array(
                                                    'gte' => $value['gte']
                                                )
                                            )
                                        ),
                                        array(
                                            'range' => array(
                                                'price_max_'.(int)Context::getContext()->currency->id => array(
                                                    'lte' => $value['lte']
                                                )
                                            )
                                        )
                                    )
                                )
                            ),
                            array(
                                'bool' => array(
                                    'must' => array(
                                        array(
                                            'range' => array(
                                                'price_min_'.(int)Context::getContext()->currency->id => array(
                                                    'lt' => $value['gte']
                                                )
                                            )
                                        ),
                                        array(
                                            'range' => array(
                                                'price_max_'.(int)Context::getContext()->currency->id => array(
                                                    'gt' => $value['gte']
                                                )
                                            )
                                        )
                                    )
                                )
                            ),
                            array(
                                'bool' => array(
                                    'must' => array(
                                        array(
                                            'range' => array(
                                                'price_min_'.(int)Context::getContext()->currency->id => array(
                                                    'lt' => $value['lte']
                                                )
                                            )
                                        ),
                                        array(
                                            'range' => array(
                                                'price_max_'.(int)Context::getContext()->currency->id => array(
                                                    'gt' => $value['lte']
                                                )
                                            )
                                        )
                                    )
                                )
                            )
                        )
                    )
                );
            } elseif ($key == 'discount') {
                $tmp = array();
                foreach ($value as $v) {
                    $tmp[] = array(
                        'term' => array(
                            'discount_'.(int)Context::getContext()->currency->id => $v['term']['discount']
                        )
                    );
                }
                $query[] = array(
                    'bool' => array(
                        'should' => $tmp
                    )
                );
            }
        }

        return $query;
    }

    /**
     * @param $id_entity int entity ID
     * @param $entity string entity
     * @return array enabled filters for given category
     */
    public function getEnabledFilters($id_entity, $entity = 'category')
    {
        try {
            $filters = Db::getInstance(_PS_USE_SQL_SLAVE_)->query(
                'SELECT `id_value`, `type`, `position`, `filter_type`, `filter_show_limit`
                FROM `'._DB_PREFIX_.'elasticsearch_'.pSQL($entity).'`
                WHERE `id_'.pSQL($entity).'` = '.(int)$id_entity.'
                    AND `id_shop` = "'.(int)Context::getContext()->shop->id.'"
                GROUP BY `type`, `id_value`
                ORDER BY `position` ASC'
            );
            $formatted_filters = array();

            while ($row = Db::getInstance()->nextRow($filters)) {
                $formatted_filters[$row['type']][] = array(
                    'id_value' => $row['id_value'],
                    'filter_type' => $row['filter_type'],
                    'filter_show_limit' => $row['filter_show_limit'],
                    'position' => $row['position']
                );
            }

            return $formatted_filters;
        } catch (Exception $e) {
            self::log('Unable to get filters from database', array('id_category' => $id_entity));
            return array();
        }
    }

    /**
     * @return array selected filters
     */
    public function getSelectedFilters()
    {
        if ($this->selected_filters === null) {
            /* Analyze all the filters selected by the user and store them into a tab */
            $selected_filters = array(
                'category' => array(),
                'manufacturer' => array(),
                'quantity' => array(),
                'condition' => array(),
                'discount' => array()
            );

            foreach ($_GET as $key => $value) {
                if (Tools::strpos($key, 'elasticsearch_') === 0) {
                    preg_match(
                        '/^(.*)_([0-9]+|new|used|refurbished|slider)$/',
                        Tools::substr($key, 14, Tools::strlen($key) - 14),
                        $res
                    );

                    if (isset($res[1])) {
                        $tmp_tab = explode('_', $this->sanitizeValue($value));
                        $value = $this->sanitizeValue($tmp_tab[0]);
                        $id_key = false;

                        if (isset($tmp_tab[1])) {
                            $id_key = $tmp_tab[1];
                        }

                        switch ($res[1]) {
                            case self::FILTER_TYPE_DISCOUNT:
                                $selected_filters['discount'][] = $value;
                                break;
                            case self::FILTER_TYPE_CONDITION:
                                if (in_array($value, array('new', 'used', 'refurbished'))) {
                                    $selected_filters['condition'][] = $value;
                                }
                                break;
                            case self::FILTER_TYPE_QUANTITY:
                                if (!$value || $value == 1) {
                                    $selected_filters['quantity'][] = $value;
                                }
                                break;
                            case self::FILTER_TYPE_CATEGORY:
                            case self::FILTER_TYPE_MANUFACTURER:
                                if (!isset($selected_filters[$res[1].($id_key ? '_'.$id_key : '')])) {
                                    $selected_filters[$res[1].($id_key ? '_'.$id_key : '')] = array();
                                }

                                $selected_filters[$res[1].($id_key ? '_'.$id_key : '')][] = (int)$value;
                                break;
                            case self::FILTER_TYPE_ATTRIBUTE_GROUP:
                            case self::FILTER_TYPE_FEATURE:
                                if (!isset($selected_filters[$res[1]])) {
                                    $selected_filters[$res[1]] = array();
                                }
                                $selected_filters[$res[1]][(int)$value] = $id_key.'_'.(int)$value;
                                break;
                            case self::FILTER_TYPE_WEIGHT:
                                $selected_filters[$res[1]] = $tmp_tab;
                                break;
                            case self::FILTER_TYPE_PRICE:
                                $selected_filters[$res[1]] = $tmp_tab;
                                break;
                        }
                    }
                }
            }

            $this->selected_filters = $selected_filters;
        }

        return $this->selected_filters;
    }

    /**
     * Formats partial fields in correct syntax to use in ElasticSearch calls
     * @param array $required_fields
     * @return array
     */
    public function getPartialFields(array $required_fields)
    {
        $partial_fields = array();

        if ($required_fields) {
            $partial_fields['data'] = array('include' => array());

            foreach ($required_fields as $field) {
                $partial_fields['data']['include'][] = $field;
            }
        }

        return $partial_fields;
    }

    /**
     * @param array $filter
     * @return array price filter data to be used in template
     */
    protected function getPriceFilter($filter)
    {
        if (isset($filter[0])) {
            $filter = $filter[0];
        }

        $currency = Context::getContext()->currency;

        $price_array = array(
            'type_lite' => self::FILTER_TYPE_PRICE,
            'type' => self::FILTER_TYPE_PRICE,
            'id_key' => 0,
            'name' => $this->getModuleInstance()->l('Price', self::FILENAME),
            'slider' => true,
            'max' => '0',
            'min' => null,
            'values' => array('1' => 0),
            'unit' => $currency->sign,
            'format' => $currency->format,
            'filter_show_limit' => $filter['filter_show_limit'],
            'filter_type' => $filter['filter_type'],
            'position' => $filter['position']
        );

        //getting min and max prices from aggregations
        $min_price = $this->getAggregation('price_min_'.$currency->id);
        $max_price = $this->getAggregation('price_max_'.$currency->id);

        $price_array['min'] = $min_price;
        $price_array['values'][0] = $price_array['min'];

        $price_array['max'] = $max_price;
        $price_array['values'][1] = $price_array['max'];

        if ($price_array['max'] != $price_array['min'] && $price_array['min'] !== null) {
            if ($filter['filter_type'] == AbstractFilter::FILTER_STYLE_LIST_OF_VALUES) {
                $price_array['list_of_values'] = array();
                $nbr_of_value = $filter['filter_show_limit'];

                if ($nbr_of_value < 2) {
                    $nbr_of_value = 4;
                }

                $delta = ($price_array['max'] - $price_array['min']) / $nbr_of_value;

                for ($i = 0; $i < $nbr_of_value; $i++) {
                    $price_array['list_of_values'][] = array(
                        (int)$price_array['min'] + $i * (int)$delta,
                        (int)$price_array['min'] + ($i + 1) * (int)$delta
                    );
                }
            }

            $selected_filters = $this->getSelectedFilters();

            if ($selected_filters && isset($selected_filters['price'][0]) && isset($selected_filters['price'][1])) {
                $price_array['values'][0] = $selected_filters['price'][0];
                $price_array['values'][1] = $selected_filters['price'][1];
            }

            return $price_array;
        }

        return null;
    }

    /**
     * @param array $filter
     * @return array discount filter data to be used in template
     */
    protected function getDiscountFilter($filter)
    {
        if (isset($filter[0])) {
            $filter = $filter[0];
        }

        $currency = Context::getContext()->currency;

        $discount_filter = array(
            'type_lite' => self::FILTER_TYPE_DISCOUNT,
            'type' => self::FILTER_TYPE_DISCOUNT,
            'id_key' => 0,
            'name' => $this->getModuleInstance()->l('Discount', self::FILENAME),
            'values' => array(),
            'filter_show_limit' => $filter['filter_show_limit'],
            'filter_type' => $filter['filter_type'],
            'position' => $filter['position']
        );

        $aggregation = $this->getAggregation(self::FILTER_TYPE_DISCOUNT.'_'.$currency->id);
        if (!$aggregation) {
            return $discount_filter;
        }
        ksort($aggregation);

        $selected_filters = $this->getSelectedFilters();
        $discount_array = array();

        foreach ($aggregation as $value => $nbr) {
            $discount_array[$value] = array(
                'name' => $value.'%',
                'nbr' => $nbr
            );
            if (isset($selected_filters['discount']) && in_array($value, $selected_filters['discount'])) {
                $discount_array[$value]['checked'] = true;
            }
        }
        $discount_filter['values'] = $discount_array;

        return $discount_filter;
    }

    /**
     * @param array $filter
     * @return array weight filter data to be used in template
     */
    protected function getWeightFilter($filter)
    {
        if (isset($filter[0])) {
            $filter = $filter[0];
        }

        $weight_array = array(
            'type_lite' => self::FILTER_TYPE_WEIGHT,
            'type' => self::FILTER_TYPE_WEIGHT,
            'id_key' => 0,
            'name' => $this->getModuleInstance()->l('Weight', self::FILENAME),
            'slider' => true,
            'max' => '0',
            'min' => null,
            'values' => array('1' => 0),
            'unit' => Configuration::get('PS_WEIGHT_UNIT'),
            'format' => 5, // Ex: xxxxx kg
            'filter_show_limit' => $filter['filter_show_limit'],
            'filter_type' => $filter['filter_type'],
            'position' => $filter['position']
        );

        //getting min and max weight from aggregations
        $min_weight = $this->getAggregation('min_weight');
        $max_weight = $this->getAggregation('max_weight');

        $weight_array['min'] = floor($min_weight);
        $weight_array['values'][0] = $weight_array['min'];

        $weight_array['max'] = ceil($max_weight);
        $weight_array['values'][1] = $weight_array['max'];

        if ($weight_array['max'] != $weight_array['min'] && $weight_array['min'] !== null) {
            $selected_filters = $this->getSelectedFilters();

            if ($selected_filters
                && isset($selected_filters['weight'])
                && isset($selected_filters['weight'][0])
                && isset($selected_filters['weight'][1])
            ) {
                $weight_array['values'][0] = $selected_filters['weight'][0];
                $weight_array['values'][1] = $selected_filters['weight'][1];
            }

            return $weight_array;
        }

        return null;
    }

    /**
     * @param $filter array available condition values - ID of condition => name of condition
     * @return array product condition filter data to be used in template
     */
    protected function getConditionFilter($filter)
    {
        if (isset($filter[0])) {
            $filter = $filter[0];
        }

        $condition_filter = array(
            'type_lite' => self::FILTER_TYPE_CONDITION,
            'type' => self::FILTER_TYPE_CONDITION,
            'id_key' => 0,
            'name' => $this->getModuleInstance()->l('Condition', self::FILENAME),
            'values' => array(),
            'filter_show_limit' => $filter['filter_show_limit'],
            'filter_type' => $filter['filter_type'],
            'position' => $filter['position']
        );

        $aggregation = $this->getAggregation(self::FILTER_TYPE_CONDITION);

        if (!$aggregation) {
            return $condition_filter;
        }

        $selected_filters = $this->getSelectedFilters();
        $condition_array = array();

        if (isset($aggregation['new']) && ($aggregation['new'] || !$this->hide_0_values)) {
            $condition_array['new'] = array(
                'name' => $this->getModuleInstance()->l('New', self::FILENAME),
                'nbr' => $aggregation['new']
            );

            if (isset($selected_filters['condition']) && in_array('new', $selected_filters['condition'])) {
                $condition_array['new']['checked'] = true;
            }
        }

        if (isset($aggregation['used']) && ($aggregation['used'] || !$this->hide_0_values)) {
            $condition_array['used'] = array(
                'name' => $this->getModuleInstance()->l('Used', self::FILENAME),
                'nbr' => $aggregation['used'],
                'checked' => isset($selected_filters['condition']) && in_array('used', $selected_filters['condition'])
            );

            if (isset($selected_filters['condition']) && in_array('used', $selected_filters['condition'])) {
                $condition_array['used']['checked'] = true;
            }
        }

        if (isset($aggregation['refurbished']) && ($aggregation['refurbished'] || !$this->hide_0_values)) {
            $condition_array['refurbished'] = array(
                'name' => $this->getModuleInstance()->l('Refurbished', self::FILENAME),
                'nbr' => $aggregation['refurbished']
            );

            if (isset($selected_filters['condition']) && in_array('refurbished', $selected_filters['condition'])) {
                $condition_array['refurbished']['checked'] = true;
            }
        }

        $condition_filter['values'] = $condition_array;

        return $condition_filter;
    }

    /**
     * @param $filter array quantity filter data
     * @return array product quantity filter data to be used in template
     */
    protected function getQuantityFilter($filter)
    {
        if (isset($filter[0])) {
            $filter = $filter[0];
        }

        $out_of_stock = $this->getAggregation('out_of_stock');

        if (is_array($out_of_stock)) {
            $out_of_stock = reset($out_of_stock);
        }

        $in_stock = $this->getAggregation('in_stock');

        if (is_array($in_stock)) {
            $in_stock = reset($in_stock);
        }

        $quantity_array = array();

        if (!$this->hide_0_values) {
            $quantity_array[0] = array(
                'name' => $this->getModuleInstance()->l('Not available', self::FILENAME),
                'nbr' => $out_of_stock
            );
            $quantity_array[1] = array(
                'name' => $this->getModuleInstance()->l('In stock', self::FILENAME),
                'nbr' => $in_stock
            );
        } else {
            if ($out_of_stock) {
                $quantity_array[0] = array(
                    'name' => $this->getModuleInstance()->l('Not available', self::FILENAME),
                    'nbr' => $out_of_stock
                );
            }
            if ($in_stock) {
                $quantity_array[1] = array(
                    'name' => $this->getModuleInstance()->l('In stock', self::FILENAME),
                    'nbr' => $in_stock
                );
            }
        }

        $selected_filters = $this->getSelectedFilters();

        //selecting filters where needed
        foreach (array_keys($quantity_array) as $key) {
            if (isset($selected_filters['quantity']) && in_array($key, $selected_filters['quantity'])) {
                $quantity_array[$key]['checked'] = true;
            }
        }

        if (!empty($quantity_array[0]['nbr']) || !empty($quantity_array[1]['nbr']) || !$this->hide_0_values) {
            return array(
                'type_lite' => self::FILTER_TYPE_QUANTITY,
                'type' => self::FILTER_TYPE_QUANTITY,
                'id_key' => 0,
                'name' => $this->getModuleInstance()->l('Availability', self::FILENAME),
                'values' => $quantity_array,
                'filter_show_limit' => $filter['filter_show_limit'],
                'filter_type' => $filter['filter_type'],
                'position' => $filter['position']
            );
        }

        return false;
    }

    /**
     * @param $filter array manufacturers filter data
     * @return array product manufacturers filter data to be used in template
     */
    protected function getManufacturerFilter($filter)
    {
        if (isset($filter[0])) {
            $filter = $filter[0];
        }

        $selected_filters = $this->getSelectedFilters();
        $manufacturers = $this->getAggregation('id_manufacturer');

        if (!$manufacturers) {
            return array();
        }

        $manufacturers_with_names = $this->getModuleInstance()->getObjectsNamesByIds(
            array_keys($manufacturers),
            'manufacturer',
            'id_manufacturer',
            'name',
            false
        );
        $manufacturers_values = array();

        foreach ($manufacturers_with_names as $id_manufacturer => $name) {
            if ($manufacturers[$id_manufacturer] == 0 && $this->hide_0_values) {
                continue;
            }

            $manufacturers_values[$id_manufacturer] = array(
                'name' => $name,
                'nbr' => $manufacturers[$id_manufacturer]
            );

            if (array_search($id_manufacturer, $selected_filters[self::FILTER_TYPE_MANUFACTURER]) !== false) {
                $manufacturers_values[$id_manufacturer]['checked'] = true;
            }
        }

        uasort($manufacturers_values, function ($a, $b) {
            if ($a['name'] == $b['name']) {
                return 0;
            }
            return ($a['name'] < $b['name']) ? -1 : 1;
        });

        return array(
            'type_lite' => self::FILTER_TYPE_MANUFACTURER,
            'type' => self::FILTER_TYPE_MANUFACTURER,
            'id_key' => 0,
            'name' => $this->getModuleInstance()->l('Manufacturer', self::FILENAME),
            'values' => $manufacturers_values,
            'filter_show_limit' => $filter['filter_show_limit'],
            'filter_type' => $filter['filter_type'],
            'position' => $filter['position']
        );
    }

    /**
     * @param $filter array attribute group filter data
     * @return array product attributes groups filter data to be used in template
     */
    protected function getAttributeGroupFilter($filter)
    {
        $selected_filters = $this->getSelectedFilters();
        $attributes_array = array();
        $attributes_groups_names = array();
        $attributes_names = array();

        foreach ($filter as $attribute_group_filter) {
            $hide_filter = true; //if all values are empty and hide_0_values is false we hide the filter

            $id_attribute_group = $attribute_group_filter['id_value'];
            $attributes_groups_names[] = $id_attribute_group;

            $attributes_array[$id_attribute_group] = array(
                'type_lite' => 'id_attribute_group',
                'type' => 'id_attribute_group',
                'id_key' => $id_attribute_group,
                'name' => '',
                'values' => array(),
                'filter_show_limit' => $attribute_group_filter['filter_show_limit'],
                'filter_type' => $attribute_group_filter['filter_type'],
                'position' => $attribute_group_filter['position']
            );

            $aggregation = $this->getAggregation('attribute_group_'.$id_attribute_group);

            if (!$aggregation) {
                continue;
            }

            foreach ($aggregation as $id_attribute => $nbr) {
                if ($nbr == 0 && $this->hide_0_values) {
                    continue;
                }

                if ($this->disallowFilter('attribute', $id_attribute_group, $id_attribute)) {
                    continue;
                }

                $hide_filter = false;

                $attributes_names[] = $id_attribute;
                $attributes_array[$id_attribute_group]['values'][$id_attribute] = array(
                    'nbr' => (int)$nbr,
                    'name' => ''
                );

                if (!empty($selected_filters[self::FILTER_TYPE_ATTRIBUTE_GROUP])
                    && array_search($id_attribute_group.'_'.$id_attribute, $selected_filters[self::FILTER_TYPE_ATTRIBUTE_GROUP])
                    !== false
                ) {
                    $attributes_array[$id_attribute_group]['values'][$id_attribute]['checked'] = true;
                }
            }

            if ($hide_filter) {
                unset($attributes_array[$id_attribute_group]);
                continue;
            }
        }

        $color_groups = $this->getModuleInstance()->getIsColorGroups($attributes_groups_names);
        $attributes_groups_names = $this->getModuleInstance()->getObjectsNamesByIds(
            $attributes_groups_names,
            'attribute_group_lang',
            'id_attribute_group'
        );

        $colors = $this->getModuleInstance()->getAttributesColors($attributes_names);
        $attributes_names = $this->getModuleInstance()->getObjectsNamesByIds(
            $attributes_names,
            'attribute_lang',
            'id_attribute'
        );

        //adding names to values
        foreach ($attributes_array as &$attribute_group) {
            $attribute_group['name'] =
                isset($attributes_groups_names[$attribute_group['id_key']])
                    ? $attributes_groups_names[$attribute_group['id_key']]
                    : '';

            $attribute_group['is_color_group'] =
                isset($color_groups[$attribute_group['id_key']])
                    ? $color_groups[$attribute_group['id_key']]
                    : 0;

            foreach ($attribute_group['values'] as $id_attribute => &$fields) {
                $fields['name'] = isset($attributes_names[$id_attribute])
                    ? $attributes_names[$id_attribute]
                    : '';

                if (isset($colors[$id_attribute])) {
                    $fields['color'] = $colors[$id_attribute];
                }
            }
        }

        return $attributes_array;
    }

    /**
     * @param $filter array features filter data
     * @return array product features filter data to be used in template
     */
    protected function getFeatureFilter($filter)
    {
        $this->formatFilterValues($filter);

        $selected_filters = $this->getSelectedFilters();
        $feature_array = array();

        $features_names = array();
        $features_values = array();
        $features_values_names = array();
        $features_values_positions = array();

        foreach ($filter as $id_feature => $feature_filter) {
            $hide_filter = true;
            $features_names[] = $id_feature;

            $feature_array[$id_feature] = array(
                'type_lite' => self::FILTER_TYPE_FEATURE,
                'type' => self::FILTER_TYPE_FEATURE,
                'id_key' => $id_feature,
                'values' => array(),
                'name' => '',
                'filter_show_limit' => $feature_filter['filter_show_limit'],
                'filter_type' => $feature_filter['filter_type'],
                'position' => $feature_filter['position']
            );

            $aggregation = $this->getAggregation('feature_'.$id_feature);
            if (!$aggregation) {
                continue;
            }

            foreach ($aggregation as $id_feature_value => $nbr) {
                if ($nbr == 0 && $this->hide_0_values) {
                    continue;
                }

                if ($this->disallowFilter('feature', $id_feature, $id_feature_value)) {
                    continue;
                }

                $hide_filter = false;

                $features_values[] = $id_feature_value;

                $feature_array[$id_feature]['values'][$id_feature_value] = array(
                    'nbr' => (int)$nbr,
                    'name' => '',
                    'position' => 0
                );

                if (!empty($selected_filters[self::FILTER_TYPE_FEATURE])
                    && array_search($id_feature.'_'.$id_feature_value, $selected_filters[self::FILTER_TYPE_FEATURE])
                    !== false
                ) {
                    $feature_array[$id_feature]['values'][$id_feature_value]['checked'] = true;
                }
            }

            if ($hide_filter) {
                unset($feature_array[$id_feature]);
                continue;
            }
        }

        $features_names = $this->getModuleInstance()->getObjectsNamesByIds(
            $features_names,
            'feature_lang',
            'id_feature'
        );
        $features_values_names = $this->getModuleInstance()->getObjectsNamesByIds(
            $features_values,
            'feature_value_lang',
            'id_feature_value',
            'value'
        );
        $features_values_positions = $this->getModuleInstance()->getObjectsPositionsByIds(
            $features_values,
            'feature_value',
            'id_feature_value',
            'position'
        );

        //adding names to values
        foreach ($feature_array as &$feature) {
            $feature['name'] = isset($features_names[$feature['id_key']]) ? $features_names[$feature['id_key']] : '';

            foreach ($feature['values'] as $id_feature_value => &$fields) {
                $fields['name'] = isset($features_values_names[$id_feature_value])
                    ? $features_values_names[$id_feature_value]
                    : '';
                $fields['position'] = isset($features_values_positions[$id_feature_value])
                    ? $features_values_positions[$id_feature_value]
                    : '';
            }
            uasort($feature['values'], function ($a, $b) {
                if ($a['position'] == $b['position']) {
                    return 0;
                }
                return ($a['position'] < $b['position']) ? -1 : 1;
            });
        }


        return $feature_array;
    }

    /**
     * @param $filter array categories filter data
     * @return array categories filter data to be used in template
     */
    protected function getCategoryFilter($filter)
    {
        if (isset($filter[0])) {
            $filter = $filter[0];
        }

        $aggregation = $this->getAggregation('categories');

        if (empty($aggregation)) {
            return array();
        }

        $subcategories = $this->getSubcategories();

        if (empty($subcategories))
            return array();

        $selected_filters = $this->getSelectedFilters();

        $categories_with_products_count = 0;
        $values = array();

        foreach ($subcategories as $id_subcategory => $subcategory) {
            if (isset($aggregation[$id_subcategory])) {
                // Checks if filter should not be displayed
                if ($this->hide_0_values && $aggregation[$id_subcategory] < 1) {
                    continue;
                }

                $values[$id_subcategory] = array(
                    'name' => $subcategory['name'],
                    'nbr' => $aggregation[$id_subcategory]
                );

                if ($aggregation[$id_subcategory] > 0) {
                    $categories_with_products_count++;
                }

                if (isset($selected_filters['category']) && in_array($id_subcategory, $selected_filters['category']))
                    $values[$id_subcategory]['checked'] = true;
            }
        }

        // If there are no categories to display - return empty array
        if ($this->hide_0_values && !$categories_with_products_count) {
            return array();
        }

        $category_filter = array(
            'type_lite' => 'category',
            'type' => 'category',
            'id_key' => 0,
            'name' => $this->getModuleInstance()->l('Categories', self::FILENAME),
            'values' => $values,
            'filter_show_limit' => $filter['filter_show_limit'],
            'filter_type' => $filter['filter_type'],
            'position' => $filter['position']
        );

        return $category_filter;
    }

    /**
     * @param bool|false $all if true, all categories will be returned (to the deepest),
     * if yes - return categories only from the 1st level
     * @return mixed
     */
    public function getSubcategories($all = false)
    {
        if (!isset(self::$cache['subcategories_'.$this->id_category.'_'.(int)$all])) {
            $subcategories = array();

            if ($all) {
                if (Group::isFeatureActive()) {
                    $groups = FrontController::getCurrentCustomerGroups();
                }

                $categories = Category::getNestedCategories(
                    $this->id_category,
                    Context::getContext()->language->id,
                    true,
                    isset($groups) && count($groups) > 0 ? $groups : null
                );

                if (isset($categories[$this->id_category]) && !empty($categories[$this->id_category]['children'])) {
                    $subcategories = $this->getChildrenCategoriesRecursive($categories[$this->id_category]['children']);
                }
            } else {
                $sql_groups_where = '';
                $sql_groups_join = '';
                if (Group::isFeatureActive()) {
                    $sql_groups_join = 'LEFT JOIN `'._DB_PREFIX_.'category_group` cg ON (cg.`id_category` = c.`id_category`)';
                    $groups = FrontController::getCurrentCustomerGroups();
                    $sql_groups_where = 'AND cg.`id_group` '.(count($groups) ? 'IN ('.implode(',',
                                $groups).')' : '='.(int)Group::getCurrent()->id);
                }

                $resource = Db::getInstance()->query('
                SELECT c.`id_category`, cl.`name`
                FROM `'._DB_PREFIX_.'category` c
                LEFT JOIN `'._DB_PREFIX_.'category_lang` cl ON c.`id_category` = cl.`id_category`
                JOIN `'._DB_PREFIX_.'category_shop` cs ON cs.`id_category` = c.`id_category`
                '.$sql_groups_join.'
                WHERE cl.`id_lang` = "'.(int)Context::getContext()->language->id.'"
                    AND cs.`id_shop` = "'.(int)Context::getContext()->shop->id.'"
                    AND c.`id_parent` = "'.(int)$this->id_category.'"
                    '.$sql_groups_where.'
            ');

                while ($row = Db::getInstance()->nextRow($resource)) {
                    $subcategories[$row['id_category']] = $row;
                }
            }

            self::$cache['subcategories_'.$this->id_category.'_'.(int)$all] = $subcategories;
        }

        return self::$cache['subcategories_'.$this->id_category.'_'.(int)$all];
    }

    public function getChildrenCategoriesRecursive($categories)
    {
        $subcategories = array();

        foreach ($categories as $category) {
            if (!empty($category['children'])) {
                $subcategories = array_merge(
                    $subcategories,
                    $this->getChildrenCategoriesRecursive($category['children'])
                );
            }

            $subcategories[$category['id_category']] = array(
                'id_category' => $category['id_category'],
                'name' => $category['name']
            );
        }

        return $subcategories;
    }

    public function formatFilterValues(&$filter_values)
    {
        $formatted_filter = array();

        foreach ($filter_values as $filter_row) {
            $formatted_filter[$filter_row['id_value']] = $filter_row;
        }

        $filter_values = $formatted_filter;
    }

    /**
     * Returns count of products for each filter
     * @return array
     */
    public function getAggregations()
    {
        if ($this->filters_products_counts === null) {
            $query_all = array(
                'aggs' => $this->getFiltersProductsCountsAggregationQuery($this->enabled_filters)
            );
            $result = AbstractFilter::$search_service->search(
                'products',
                $query_all,
                0,
                null,
                null,
                null,
                null,
                true
            );

            if (!isset($result['aggregations'])) {
                $this->filters_products_counts = array();
            } else {
                $aggregations = array();

                foreach ($result['aggregations'] as $alias => $aggregation) {
                    if (isset($aggregation[$alias]['buckets'])
                        && !in_array($alias, array('in_stock', 'out_of_stock'))
                    ) {
                        $aggregations[$alias] = array();

                        foreach ($aggregation[$alias]['buckets'] as $bucket) {
                            $aggregations[$alias][$bucket['key']] = $bucket['doc_count'];
                        }
                    } elseif (isset($aggregation[$alias]['value'])) {
                        $aggregations[$alias] = $aggregation[$alias]['value'];
                    } elseif (isset($aggregation['doc_count'])) {
                        $aggregations[$alias] = $aggregation['doc_count'];
                    } else {
                        $aggregations[$alias] = 0;
                    }
                }

                $this->filters_products_counts = $aggregations;
            }
        }

        return $this->filters_products_counts;
    }

    /**
     * Gets aggregation value(s) by given name
     * @param $name - aggregation name
     * @param bool $partial_name is the provided name ($name) partial
     * @return array|int
     */
    public function getAggregation($name, $partial_name = false)
    {
        $aggregations = $this->getAggregations();
        if ($partial_name) {
            //caching the result
            $cache_key = 'aggregation_'.$name;

            if (!isset(self::$cache[$cache_key])) {
                $result = array();
                foreach ($aggregations as $key => $aggregation) {
                    if (Tools::strpos($key, $name) !== false) {
                        $result[$key] = $aggregation;
                    }
                }

                self::$cache[$cache_key] = $result;
            }

            return self::$cache[$cache_key];
        } elseif (!isset($aggregations[$name])) {
            return 0;
        }

        return $aggregations[$name];
    }

    public static function getCategoriesSameLevel($id_parent, $id_lang, $active = true)
    {
        $sql_groups_where = '';
        $sql_groups_join = '';
        if (Group::isFeatureActive()) {
            $sql_groups_join = 'LEFT JOIN `'._DB_PREFIX_.'category_group` cg ON (cg.`id_category` = c.`id_category`)';
            $groups = FrontController::getCurrentCustomerGroups();
            $sql_groups_where = 'AND cg.`id_group` '.(count($groups) ? 'IN ('.implode(',', $groups).')' : '='.(int)Group::getCurrent()->id);
        }

        $result = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS('
            SELECT c.*, cl.id_lang, cl.name, cl.description, cl.link_rewrite, cl.meta_title, cl.meta_keywords, cl.meta_description
            FROM `'._DB_PREFIX_.'category` c
            '.Shop::addSqlAssociation('category', 'c').'
            LEFT JOIN `'._DB_PREFIX_.'category_lang` cl ON (c.`id_category` = cl.`id_category` AND `id_lang` = '.(int)$id_lang.' '.Shop::addSqlRestrictionOnLang('cl').')
            '.$sql_groups_join.'
            WHERE `id_parent` = '.(int)$id_parent.'
            '.($active ? 'AND `active` = 1' : '').'
            '.$sql_groups_where.'
            GROUP BY c.`id_category`
            ORDER BY `level_depth` ASC, category_shop.`position` ASC');

        return $result;
    }

    public function allowFilter($type, $typeId, $id)
    {
        if ($this->entity != 'menu_category') {
            return true;
        }

        $cache_key = $type.'_'.$typeId.'_'.$this->id_entity;
        if (!array_key_exists($cache_key, static::$allowedFilters)) {
            static::$allowedFilters[$cache_key] = array();

            $sql = 'SELECT `value`
                    FROM `' . _DB_PREFIX_ . 'elasticsearch_menu_category_values`
                    WHERE TRUE
                    AND   `id_menu_category` = ' . $this->id_category . '
                    AND   `id_shop` = ' . Context::getContext()->shop->id . '
                    AND   `type` = "' . pSQL($type) . '"
                    AND   `type_id` = ' . $typeId . '
                    ;';

            foreach (Db::getInstance()->executeS($sql) as $row) {
                static::$allowedFilters[$cache_key][] = $row['value'];
            }
        }

        // Si pas de config, on accpete tout, sinon on filtre
        return !count(static::$allowedFilters[$cache_key]) || in_array($id, static::$allowedFilters[$cache_key]);
    }

    public function disallowFilter($type, $typeId, $id)
    {
        return !$this->allowFilter($type, $typeId, $id);
    }
}
