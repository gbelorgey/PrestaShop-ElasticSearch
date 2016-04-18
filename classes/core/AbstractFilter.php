<?php

require_once(_ELASTICSEARCH_CORE_DIR_.'SearchService.php');
require_once(_ELASTICSEARCH_CORE_DIR_.'AbstractLogger.php');

abstract class AbstractFilter extends Brad\AbstractLogger
{
    const FILENAME = 'AbstractFilter';

    /* Available filters types */
    const FILTER_TYPE_PRICE = 'price';
    const FILTER_TYPE_DISCOUNT = 'discount';
    const FILTER_TYPE_WEIGHT = 'weight';
    const FILTER_TYPE_CONDITION = 'condition';
    const FILTER_TYPE_QUANTITY = 'quantity';
    const FILTER_TYPE_MANUFACTURER = 'manufacturer';
    const FILTER_TYPE_ATTRIBUTE_GROUP = 'id_attribute_group';
    const FILTER_TYPE_FEATURE = 'id_feature';
    const FILTER_TYPE_CATEGORY = 'category';

    const FILTER_STYLE_SLIDER = 0;
    const FILTER_STYLE_INPUTS_AREA = 1;
    const FILTER_STYLE_LIST_OF_VALUES = 2;

    //product out of stock constants
    const PRODUCT_OOS_DENY_ORDERS = 0;
    const PRODUCT_OOS_ALLOW_ORDERS = 1;
    const PRODUCT_OOS_USE_GLOBAL = 2;

    public static $search_service;
    public $enabled_filters;
    public $hide_0_values;
    public $full_tree;

    protected $filters_products_counts;

    private $filters;
    private $filters_block;
    private $module_instance;//for translations

    public function __construct($service_type)
    {
        self::$search_service = SearchService::getInstance($service_type);

        // Loading up some settings
        $this->hide_0_values = (int)Configuration::get('ELASTICSEARCH_HIDE_0_VALUES');
        $this->full_tree = (int)Configuration::get('ELASTICSEARCH_FULL_TREE');
    }

    /**
     * @param $id_category int ID of category for which this filters block should be generated
     * @param $extra_filters array extra filters to be added to filters block - optional
     * @return string html of filters block
     */
    public function generateFiltersBlock($id_entity, $entity = 'category', array $extra_filters = array())
    {
        $this->enabled_filters = $this->getEnabledFilters($id_entity, $entity);
        $filters = array();
        foreach ($this->enabled_filters as $type => $enabled_filter) {
            $filter = array();

            /* Getting filters by types */
            switch ($type) {
                case self::FILTER_TYPE_PRICE:
                    $filter = $this->getPriceFilter($enabled_filter);
                    break;
                case self::FILTER_TYPE_DISCOUNT:
                    $filter = $this->getDiscountFilter($enabled_filter);
                    break;
                case self::FILTER_TYPE_WEIGHT:
                    $filter = $this->getWeightFilter($enabled_filter);
                    break;
                case self::FILTER_TYPE_CONDITION:
                    $filter = $this->getConditionFilter($enabled_filter);
                    break;
                case self::FILTER_TYPE_QUANTITY:
                    $filter = $this->getQuantityFilter($enabled_filter);
                    break;
                case self::FILTER_TYPE_MANUFACTURER:
                    $filter = $this->getManufacturerFilter($enabled_filter);
                    break;
                case self::FILTER_TYPE_ATTRIBUTE_GROUP:
                    $filter = $this->getAttributeGroupFilter($enabled_filter);
                    break;
                case self::FILTER_TYPE_FEATURE:
                    $filter = $this->getFeatureFilter($enabled_filter);
                    break;
                case self::FILTER_TYPE_CATEGORY:
                    $filter = $this->getCategoryFilter($enabled_filter);
                    break;
            }
            //Merging filters to one array
            if ($filter) {
                if (is_array(reset($filter))) {
                    $filters = array_merge($filters, $filter);
                } else {
                    $filters[] = $filter;
                }
            }
        }

        //adding extra filters
        if ($extra_filters) {
            $filters = array_merge($filters, $extra_filters);
        }

        $this->sortFilters($filters);

        $translate = array();
        $translate['price'] = $this->getModuleInstance()->l('price', self::FILENAME);
        $translate['weight'] = $this->getModuleInstance()->l('weight', self::FILENAME);

        $this->filters = $filters;

        $selected_filters = $this->getSelectedFilters();
        if (!is_array($selected_filters)) {
            $selected_filters = array();
        }

        //@todo unset price and weight filters from $selected_filters if they are on default values
        // it might not be necessary though

        $filters_count = 0;
        if (is_array($selected_filters)) {
            foreach ($selected_filters as $selected_filter) {
                $filters_count += count($selected_filter);
            }
        }

        $id_lang = Context::getContext()->language->id;
        if ($entity == 'category') {
            $category = new Category($id_entity, $id_lang);
            $subcategories = $category->getSubCategories($id_lang);
            if (Configuration::get('ELASTICSEARCH_DISPLAY_CATEGORIES_SAME_LEVEL')) {
                $is_last_tree_branch = false;
                if (count($subcategories) == 0) {
                    $is_last_tree_branch = true;
                    $subcategories = ElasticSearchFilter::getCategoriesSameLevel($category->id_parent, $id_lang);
                }

                Context::getContext()->smarty->assign(array(
                   'is_last_tree_branch' => $is_last_tree_branch,
                    'id_current_category' => $category->id
                ));
            }
            Context::getContext()->smarty->assign(array(
                'subcategories' => $subcategories
            ));
        }
        Context::getContext()->smarty->assign(array(
            'filters' => $filters,
            'selected_filters' => $selected_filters,
            'n_filters' => $filters_count,
            'nbr_filterBlocks' => count($this->enabled_filters),
            'id_elasticsearch_'.$entity => $id_entity,
            'elasticsearchSliderName' => $translate,
            'hide_0_values' => $this->hide_0_values,
            'elasticsearch_show_qties' => (int)Configuration::get('ELASTICSEARCH_SHOW_QTIES')
        ));

        return Context::getContext()->smarty->fetch(_ELASTICSEARCH_TEMPLATES_DIR_.'hook/column.tpl');
    }

    /*
     * Sorts filters by position ascending
     */
    private function sortFilters(&$filters)
    {
        usort($filters, function($a, $b) {
            return $a['position'] - $b['position'];
        });
    }

    public function getFilters()
    {
        return $this->filters;
    }

    /**
     * This method is called when filtering is processed (e.g. user selects a filter)
     * @return array all variables that are needed to display filters page
     */
    public function ajaxCall()
    {
        $t = microtime(true);

        if ($this->id_category) {
            $entity = 'category';
            $id_entity = $this->id_category;
            $object_entity = new Category($this->id_category, (int)Context::getContext()->cookie->id_lang);
        }
        if ($this->id_manufacturer) {
            $entity = 'manufacturer';
            $id_entity = $this->id_manufacturer;
            $object_entity = new Manufacturer($this->id_manufacturer, (int)Context::getContext()->cookie->id_lang);
        }

        $products_per_page_default = (int)Configuration::get('PS_PRODUCTS_PER_PAGE');
        $n_array = $products_per_page_default > 0 ?
            array(
                $products_per_page_default,
                $products_per_page_default * 2,
                $products_per_page_default * 3,
                $products_per_page_default * 5
            ) :
            array(10, 20, 50);

        $products = $this->getProductsBySelectedFilters($this->getSelectedFilters());
        $nb_products = $this->getProductsBySelectedFilters($this->getSelectedFilters(), true);

        $pagination_variables = $this->getPaginationVariables($nb_products);

        if ($entity == 'category') {
            Context::getContext()->smarty->assign(array(
                'category' => $object_entity,
                'page_name' => 'category'
            ));
        }
        if ($entity == 'manufacturer') {
            Context::getContext()->smarty->assign(array(
                'manufacturer' => $object_entity,
                'page_name' => 'manufacturer'
            ));
        }
        Context::getContext()->smarty->assign(array_merge(
            array(
                'homeSize' => Image::getSize(ImageType::getFormatedName('home')),
                'n_array' => $n_array,
                'comparator_max_item' => (int)Configuration::get('PS_COMPARATOR_MAX_ITEM'),
                'products' => $products,
                'products_per_page' => $products_per_page_default,
                'static_token' => Tools::getToken(false),
                'nArray' => $n_array,
                'compareProducts' => CompareProduct::getCompareProducts((int)Context::getContext()->cookie->id_compare),
            ),
            $pagination_variables
        ));

        $pagination_bottom_html = Context::getContext()->smarty->fetch(_PS_THEME_DIR_.'pagination.tpl');

        return array(
            'filtersBlock' => utf8_encode($this->getFiltersBlock($id_entity, $entity)),
            'productList' => utf8_encode(
                $pagination_variables['nb_products'] == 0 ?
                    Context::getContext()->smarty->fetch(_ELASTICSEARCH_TEMPLATES_DIR_.'hook/elasticsearch-filter-no-products.tpl') :
                    Context::getContext()->smarty->fetch(_PS_THEME_DIR_.'product-list.tpl')
            ),
            'pagination_bottom' => $pagination_bottom_html,
            //pagination is identical in top and bottom so just remove the _bottom suffix and use the same html
            'pagination' => preg_replace('/(_bottom)/i', '', $pagination_bottom_html),
            'categoryCount' => file_exists(_PS_THEME_DIR_.'category-count.tpl') ?
                Context::getContext()->smarty->fetch(_PS_THEME_DIR_.'category-count.tpl') : '',
            'current_friendly_url' => $this->getCurrentFriendlyUrl(),
            'filters' => $this->getFilters(),
            'nbRenderedProducts' => $pagination_variables['nb_products'],
            'nbAskedProducts' => $pagination_variables['n'],
            'time' => microtime(true) - $t
        );
    }

    protected function getCurrentFriendlyUrl()
    {
        $friendly_url = $_SERVER['REQUEST_URI'];
        $friendly_url = str_replace(_ELASTICSEARCH_AJAX_URI_.'?', '#', $friendly_url);
        $friendly_url = explode('&token', $friendly_url);
        return str_replace('&submitElasticsearchFilter=1', '', $friendly_url[0]);
    }

    /**
     * @param $id_category - category for which filter block is generated
     * @return string html of filters block
     */
    public function getFiltersBlock($id_entity, $entity = 'category')
    {
        if ($this->filters_block === null) {
            $this->filters_block = $this->generateFiltersBlock($id_entity, $entity);
        }

        return $this->filters_block;
    }

    protected function getPaginationVariables($nb_products)
    {
        $nb_products = (int)$nb_products;
        $range = 2; // how many pages around selected page
        $n = (int)Tools::getValue('n'); // how many products per page
        $p = (int)Tools::getValue('p'); // current page

        if ($n < 1) {
            $n = (int)Configuration::get('PS_PRODUCTS_PER_PAGE');
        }

        if ($p < 1) {
            $p = 1;
        }

        if ($p > ($nb_products / $n)) {
            $p = ceil($nb_products / $n);
        }

        $pages_nb = ceil($nb_products / $n);
        $start = $p - $range;
        $stop = $p + $range;

        if ($start < 1) {
            $start = 1;
        }

        if ($stop > $pages_nb) {
            $stop = $pages_nb;
        }

        return array(
            'nb_products' => $nb_products,
            'pages_nb' => $pages_nb,
            'p' => $p,
            'n' => $n,
            'range' => 2,
            'start' => $start,
            'stop' => $stop,
            'paginationId' => 'bottom'
        );
    }

    public function sanitizeValue($value)
    {
        if (version_compare(_PS_VERSION_, '1.6.0.7', '>=') === true) {
            return Tools::purifyHTML($value);
        }

        return filter_var($value, FILTER_SANITIZE_STRING);
    }

    /**
     * @return ElasticSearch module object
     */
    public function getModuleInstance()
    {
        if (!$this->module_instance) {
            $this->module_instance = Module::getInstanceByName('elasticsearch');
        }

        return $this->module_instance;
    }

    public function generateFilters($id_entity, $entity = 'category', array $extra_filters = array())
    {
        $this->enabled_filters = $this->getEnabledFilters($id_entity, $entity);
        $filters = array();
        foreach ($this->enabled_filters as $type => $enabled_filter) {
            $filter = array();
            /* Getting filters by types */
            switch ($type) {
                case self::FILTER_TYPE_PRICE:
                    $filter = $this->getPriceFilter($enabled_filter);
                    break;
                case self::FILTER_TYPE_WEIGHT:
                    $filter = $this->getWeightFilter($enabled_filter);
                    break;
                case self::FILTER_TYPE_CONDITION:
                    $filter = $this->getConditionFilter($enabled_filter);
                    break;
                case self::FILTER_TYPE_QUANTITY:
                    $filter = $this->getQuantityFilter($enabled_filter);
                    break;
                case self::FILTER_TYPE_MANUFACTURER:
                    $filter = $this->getManufacturerFilter($enabled_filter);
                    break;
                case self::FILTER_TYPE_ATTRIBUTE_GROUP:
                    $filter = $this->getAttributeGroupFilter($enabled_filter);
                    var_dump($filter);
                    break;
                case self::FILTER_TYPE_FEATURE:
                    $filter = $this->getFeatureFilter($enabled_filter);
                    break;
                case self::FILTER_TYPE_CATEGORY:
                    $filter = $this->getCategoryFilter($enabled_filter);
                    break;
            }

            //Merging filters to one array
            if ($filter) {
                if (is_array(reset($filter))) {
                    $filters = array_merge($filters, $filter);
                } else {
                    $filters[] = $filter;
                }
            }
        }
        return $filters;
    }

    /**
     * @param $selected_filters array selected filters
     * @param bool $count_only return only number of results?
     * @return array|int array with products data | number of products
     */
    abstract public function getProductsBySelectedFilters($selected_filters, $count_only = false);

    /**
     * @param $id_category int category ID
     * @return array enabled filters for given category
     */
    abstract public function getEnabledFilters($id_entity, $entity = 'category');

    /**
     * @return array selected filters
     */
    abstract public function getSelectedFilters();

    /**
     * @param $values array price filter values
     * @return array price filter data to be used in template
     */
    abstract protected function getPriceFilter($values);

    /**
     * @param $values array discount filter values
     * @return array discount filter data to be used in template
     */
    abstract protected function getDiscountFilter($values);

    /**
     * @param $values array weight filter values
     * @return array weight filter data to be used in template
     */
    abstract protected function getWeightFilter($values);

    /**
     * @param $values array available condition values - ID of condition => name of condition
     * @return array product condition filter data to be used in template
     */
    abstract protected function getConditionFilter($values);

    /**
     * @param $values array available quantity values - ID of quantity type => name of quantity type
     * @return array product quantity filter data to be used in template
     */
    abstract protected function getQuantityFilter($values);

    /**
     * @param $values array available manufacturer values - ID of manufacturer => name of manufacturer
     * @return array product manufacturers filter data to be used in template
     */
    abstract protected function getManufacturerFilter($values);

    /**
     * @param $values array available attributes groups values - ID of attribute group => name of attribute group
     * @return array product attributes groups filter data to be used in template
     */
    abstract protected function getAttributeGroupFilter($values);

    /**
     * @param $values array available features - IDs of features
     * @return array product features filter data to be used in template
     */
    abstract protected function getFeatureFilter($values);

    /**
     * @param $values array available categories values - ID of category => name of category
     * @return array categories filter data to be used in template
     */
    abstract protected function getCategoryFilter($values);

    /**
     * Returns count of products for each filter
     * @return array
     */
    abstract public function getAggregations();
}
