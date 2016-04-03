<?php
/**
 * Copyright (c) 2015 Invertus, JSC
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"),
 * to deal in the Software without restriction,
 * including without limitation the rights to use, copy, modify, merge, publish, distribute, sublicense,
 * and/or sell copies of the Software, and to permit persons to whom the Software is furnished to do so,
 * subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED,
 * INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT.
 * IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT,
 * TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
 */

class ElasticSearchService extends SearchService
{
    const INSTANCE_TYPE = SearchService::ELASTICSEARCH_INSTANCE;
    const FILENAME = 'ElasticSearchService';

    public $module_instance = null;

    private $host = null;

    public function __construct($id_shop, $module_name = 'elasticsearch')
    {
        $this->initIndex($id_shop);
        $this->module_instance = Module::getInstanceByName($module_name);
        $this->host = Configuration::get('ELASTICSEARCH_HOST');

        if (Tools::strpos($this->host, 'http://') === false && Tools::strpos($this->host, 'https://') === false)
            $this->host = 'http://'.$this->host;

        $this->initClient();
    }

    protected function initIndexPrefix($id_shop, $force = false)
    {
        if ($this->index_prefix && !$force)
            return;

        $prefix = Configuration::get('ELASTICSEARCH_INDEX_PREFIX', null, null, $id_shop);
        if (!$prefix || $force) {
            $prefix = Tools::strtolower(Tools::passwdGen()).'_';
            Configuration::updateValue('ELASTICSEARCH_INDEX_PREFIX', $prefix, false, null, $id_shop);
        }

        $this->index_prefix = $prefix;
    }

    public function getDocumentById($type, $id)
    {
        $params = array(
            'index' => $this->index,
            'type' => $type,
            'id' => $id
        );

        return $this->client->get($params);
    }

    protected function initClient()
    {
        if (!$this->host)
        {
            $this->errors[] = $this->module_instance->l('Service host must be entered in order to use elastic search', self::FILENAME);
            return false;
        }

        $params = array();
        $params['hosts'] = array(
            $this->host         				// Domain + Port
        );

        $this->client = new Elasticsearch\Client($params);
    }

    public function testSearchServiceConnection()
    {
        if (!$this->client || !$this->host)
            return false;

        $response = Tools::jsonDecode(Tools::file_get_contents($this->host));

        if (!$response)
            return false;

        return isset($response->status) && $response->status = '200';
    }

    /**
     * @param $product - product object or id_product
     * @return array
     */
    private function generateFilterBodyByProduct($product)
    {
        if (!is_object($product)) {
            $product = new Product($product, true);
        }

        $attributes = Product::getAttributesInformationsByProduct($product->id);
        $features = $product->getFeatures();
        if (class_exists('FeatureCombination', true)) {
            $featurecombination = FeatureCombination::getFeatureValuesExistsOfProductCombination($product->id);
            if (count($featurecombination) > 0) {
                $features = array_merge($features, $featurecombination);
            }
        }

        $body = array();
        $body['categories'] = $product->getCategories();
        $category_products = Db::getInstance()->executeS(
            'SELECT cp.`id_category`, cp.`position` '.
            'FROM `'._DB_PREFIX_.'category_product` cp '.
            'WHERE `id_product` = '.(int)$product->id
        );
        foreach ($category_products as $cp) {
            $body['position_'.$cp['id_category']] = (int)$cp['position'];
        }
        $body['condition'] = $product->condition;
        $body['id_manufacturer'] = $product->id_manufacturer;
        $body['manufacturer_name'] = $product->manufacturer_name;
        $body['weight'] = $product->weight;
        $body['out_of_stock'] = $product->out_of_stock;
        $body['id_category_default'] = $product->id_category_default;
        $body['ean13'] = $product->ean13;
        $body['available_for_order'] = $product->available_for_order;
        $body['customizable'] = $product->customizable;
        $body['minimal_quantity'] = $product->minimal_quantity;
        $body['show_price'] = $product->show_price;
        $body['id_combination_default'] = Product::getDefaultAttribute($product->id);

        //is product in stock when "PS_ORDER_OUT_OF_STOCK" is true
        $body['in_stock_when_global_oos_allow_orders'] = (int)($product->quantity > 0 || $product->out_of_stock != 0);

        //is product in stock when "PS_ORDER_OUT_OF_STOCK" is false
        $body['in_stock_when_global_oos_deny_orders'] = (int)($product->quantity > 0 || $product->out_of_stock == 1);

        $cover = Product::getCover($product->id);
        $body['id_image'] = isset($cover['id_image']) ? $cover['id_image'] : $cover;

        if ($attributes)
            foreach ($attributes as $attribute)
            {
                $attribute_obj = new Attribute($attribute['id_attribute']);

                foreach ($attribute_obj->name as $id_lang => $name)
                    $body['lang_attribute_'.$attribute['id_attribute'].'_'.$id_lang] = $name;

                $body['attribute_group_'.$attribute['id_attribute_group']][] = $attribute['id_attribute'];
            }

        if ($features)
            foreach ($features as $feature)
            {
                $feature_obj = new Feature($feature['id_feature']);
                $feature_value_obj = new FeatureValue($feature['id_feature_value']);

                foreach ($feature_obj->name as $id_lang => $name)
                {
                    $body['lang_feature_'.$feature['id_feature'].'_'.$id_lang] = $name;
                    $body['lang_feature_value_'.$feature['id_feature_value'].'_'.$id_lang] = $feature_value_obj->value[$id_lang];
                }

                $body['feature_'.$feature['id_feature']][] = $feature['id_feature_value'];
            }

        return array_merge($body, $this->getProductPricesForIndexing($product->id));
    }

    /**
     * @param $product - product object or id_product
     * @return array
     */
    private function generateSearchKeywordsBodyByProduct($product)
    {
        if (!is_object($product))
            $product = new Product($product, true);

        $body = array();
        $body['reference'] = $product->reference;

        if (is_array($product->name)) {
            foreach ($product->name as $id_lang => $name)
            {
                $category_link_rewrite = Category::getLinkRewrite((int)$product->id_category_default, $id_lang);

                $body['name_'.$id_lang] = $name;
                $body['link_rewrite_'.$id_lang] = $product->link_rewrite[$id_lang];
                $body['description_short_'.$id_lang] = $product->description_short[$id_lang];
                $body['description_'.$id_lang] = $product->description[$id_lang];
                $body['default_category_link_rewrite_'.$id_lang] = $category_link_rewrite;
                $body['link_'.$id_lang] = Context::getContext()->link->getProductLink((int)$product->id, $product->link_rewrite[$id_lang], $category_link_rewrite, $product->ean13);
                $body['search_keywords_'.$id_lang][] = $product->reference;
                $body['search_keywords_'.$id_lang][] = $name;
                $body['search_keywords_'.$id_lang][] = strip_tags($product->description[$id_lang]);
                $body['search_keywords_'.$id_lang][] = strip_tags($product->description_short[$id_lang]);
                $body['search_keywords_'.$id_lang][] = $product->manufacturer_name;
            }
        }
        $category = new Category($product->id_category_default);
        if (Validate::isLoadedObject($category) && is_array($category->name)) {
            foreach ($category->name as $id_lang => $category_name) {
                $body['search_keywords_'.$id_lang][] = $category_name;
            }
        }

        foreach (Language::getLanguages() as $lang) {
            if (isset($body['search_keywords_'.$lang['id_lang']])) {
                $body['search_keywords_'.$lang['id_lang']] = Tools::strtolower(implode(' ', array_filter($body['search_keywords_'.$lang['id_lang']])));
            }
        }
        $body['quantity'] = $product->quantity;
        $body['price'] = $product->price;

        return $body;
    }

    /**
     * @param $product - product object or id_product
     * @return array
     */
    public function generateSearchBodyByProduct($product)
    {
        return array_merge($this->generateSearchKeywordsBodyByProduct($product), $this->generateFilterBodyByProduct($product));
    }

    public function generateSearchBodyByCategory($category)
    {
        if (!is_object($category)) {
            $category = new Category($id_category);
        }

        $body = array();

        if (is_array($category->name)) {
            foreach ($category->name as $id_lang => $name) {
                $body['name_'.$id_lang] = $name;
            }
        }

        $body['id_parent'] = $category->id_parent;
        $body['level_depth'] = $category->level_depth;
        $body['nleft'] = $category->nleft;
        $body['nright'] = $category->nright;
        $body['is_root_category'] = $category->is_root_category;

        return $body;
    }

    public function createDocument($body, $id = null, $type = 'products')
    {
        try
        {
            $params = array();

            if ($id)
                $params['id'] = $id;

            $params['index'] = $this->index;
            $params['type'] = $type;
            $params['body'] = $body;

            return $this->client->index($params);
        } catch (Exception $e) {
            self::log('Unable to create document', array_merge(array('Message' => $e->getMessage()), get_defined_vars()));
            return false;
        }
    }

    public static function getProductPricesForIndexing($id_product)
    {
        $id_shop = (int)Context::getContext()->shop->id;
        $min_price = array();
        $max_price = array();
        $reduction_display = array();
        $specific_prices_index = array();

        static $currency_list = null;
        if (is_null($currency_list)) {
            $currency_list = Currency::getCurrencies(false, 1, new Shop($id_shop));
        }
        foreach ($currency_list as $currency) {
            $max_price[$currency['id_currency']] = null;
            $min_price[$currency['id_currency']] = null;
            $reduction_display[$currency['id_currency']] = array();
        }

        if (Configuration::get('ELASTICSEARCH_PRICE_USETAX')) {
            $max_tax_rate = Db::getInstance()->getValue('
                SELECT max(t.rate) max_rate
                FROM `'._DB_PREFIX_.'product_shop` p
                LEFT JOIN `'._DB_PREFIX_.'tax_rules_group` trg ON (trg.id_tax_rules_group = p.id_tax_rules_group AND p.id_shop = '.(int)$id_shop.')
                LEFT JOIN `'._DB_PREFIX_.'tax_rule` tr ON (tr.id_tax_rules_group = trg.id_tax_rules_group)
                LEFT JOIN `'._DB_PREFIX_.'tax` t ON (t.id_tax = tr.id_tax AND t.active = 1)
                WHERE id_product = '.(int)$id_product.'
                GROUP BY id_product');
        } else {
            $max_tax_rate = 0;
        }

        // Get price for all combinations + default (base)
        $combinations_query = new DbQuery();
        $combinations_query->select('pas.`id_product_attribute`');
        $combinations_query->from('product_attribute_shop', 'pas');
        $combinations_query->where('pas.`id_product` = '.(int)$id_product);
        $combinations_query->where('pas.`id_shop` = '.(int)$id_shop);
        $combinations = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($combinations_query);
        if (!$combinations) {
            $combinations = array();
        } else {
            $combinations = array_map(
                function ($c) {
                    return (int)$c['id_product_attribute'];
                },
                $combinations
            );
        }
        array_unshift($combinations, null); // Add default

        foreach ($combinations as $combination) {
            foreach ($currency_list as $currency) {
                $price = Product::priceCalculation(
                    $id_shop,
                    (int)$id_product,
                    $combination,
                    null,
                    null,
                    null,
                    $currency['id_currency'],
                    null,
                    null,
                    false,
                    6,
                    false,
                    true,
                    true,
                    $specific_price_output,
                    true
                );
                if ($price == 0) {
                    continue;
                }
                if ($specific_price_output !== false) {
                    $specific_prices_index[] = (int)$specific_price_output['id_specific_price'];
                }
                if ($price > $max_price[$currency['id_currency']]) {
                    $max_price[$currency['id_currency']] = $price;
                }
                if ($price < $min_price[$currency['id_currency']]) {
                    $min_price[$currency['id_currency']] = $price;
                }

                $price_without_reduction = Product::priceCalculation(
                    $id_shop,
                    (int)$id_product,
                    $combination,
                    null,
                    null,
                    null,
                    $currency['id_currency'],
                    null,
                    null,
                    false,
                    6,
                    false,
                    false,
                    true,
                    $specific_price_output,
                    true
                );

                if ($price_without_reduction > $price) {
                    $reduction_display[$currency['id_currency']][] = round((1 - $price/$price_without_reduction) * 100);
                }
            }
        }

        $values = array();
        foreach ($currency_list as $currency) {
            $values['price_min_'.(int)$currency['id_currency']] = (int)floor($min_price[$currency['id_currency']] * (100 + $max_tax_rate)) / 100;
            $values['price_max_'.(int)$currency['id_currency']] = (int)ceil($max_price[$currency['id_currency']] * (100 + $max_tax_rate)) / 100;
            $values['discount_'.(int)$currency['id_currency']] = array_unique($reduction_display[$currency['id_currency']]);
        }

        // Save specific prices index state
        if (class_exists('SpecificPriceElasticIndex')) {
            $specific_prices_index = array_unique($specific_prices_index);
            SpecificPriceElasticIndex::setProductIndex($id_product, $id_shop, $specific_prices_index);
        }

        return $values;
    }

    public function indexAllProducts($delete_old = true)
    {
        try {
            if ($delete_old) {
                $this->deleteShopIndex();
                $this->initIndex(null, true);
            }

            if (!$this->createIndexForCurrentShop())
                return false;

            $id_shop = (int)Context::getContext()->shop->id;
            $shop_products = $this->module_instance->getAllProducts($id_shop);

            if (!$shop_products)
                return true;

            foreach ($shop_products as $product)
            {
                if ($this->documentExists($id_shop, (int)$product['id_product']))
                    continue;

                $result = $this->createDocument(
                    $this->generateSearchBodyByProduct(new Product($product['id_product'], true)),
                    $product['id_product']
                );

                if (!isset($result['created']) || $result['created'] !== true)
                    $this->errors[] = sprintf($this->module_instance->l('Unable to index product #%d'), $product['id_product']);
            }

            //indexing categories if products indexing succeeded
            return $this->errors ? false : $this->indexAllCategories();
        } catch (Exception $e) {
            if (!($message = $e->getMessage()))
                $message = $e->getPrevious()->getMessage();

            self::log('Unable to index all products. Error code: '.$e->getCode().'. Message: '.$message);
            return false;
        }
    }

    public function indexAllCategories()
    {
        $id_shop = (int)Context::getContext()->shop->id;
        $shop_categories = $this->module_instance->getAllCategories($id_shop);

        if (!$shop_categories)
            return true;

        foreach ($shop_categories as $category) {
            if ($this->documentExists($id_shop, (int)$category['id_category'], 'categories')) {
                continue;
            }

            $category_object = new Category((int)$category['id_category']);
            if (!Validate::isLoadedObject($category_object)) {
                continue;
            }

            $result = $this->createDocument(
                $this->generateSearchBodyByCategory($category_object),
                $category['id_category'],
                'categories'
            );

            if (!isset($result['created']) || $result['created'] !== true) {
                $this->errors[] = sprintf($this->module_instance->l('Unable to index category #%d'), $category['id_category']);
            }
        }

        return $this->errors ? false : true;
    }

    public function buildSearchQuery($type, $term = '')
    {
        $type = pSQL($type);

        switch ($type)
        {
            case 'all':
                return array (
                    'match_all' => array()
                );
            default:
                $term = Tools::strtolower($term);
                return array (
                    'match_phrase_prefix' => array(
                        $type => array(
                            'query' => pSQL($term),
                            'slop' => 1000
                        )
                    )
                );
            case 'products':
                $term = Tools::strtolower($term);
                return array (
                    'wildcard' => array(
                        $type => '*'.pSQL($term).'*'
                    )
                );
            case 'strict_search':
                return array(
                    'term' => $term
                );
            case 'range':
                return array(
                    'range' => $term
                );
            case 'bool_must':
                return array(
                    'bool' => array(
                        'must' => $term
                    )
                );
            case 'bool_should':
                return array(
                    'bool' => array(
                        'should' => $term
                    )
                );
            case 'filter_or':
                return array(
                    'or' => $term
                );
        }
    }

    public function deleteDocumentById($id_shop, $id, $type = 'products')
    {
        if (!$this->documentExists($id_shop, $id, $type))
            return true;

        $params = array(
            'index' => $this->index_prefix.$id_shop,
            'type' => $type,
            'id' => $id
        );

        return $this->client->delete($params);
    }

    public function documentExists($id_shop, $id, $type = 'products')
    {
        $params = array(
            'index' => $this->index_prefix.$id_shop,
            'type' => $type,
            'id' => $id
        );

        return (bool)$this->client->exists($params);
    }

    public function search($type, array $query, $pagination = 50, $from = 0, $order_by = null, $order_way = null, $filter = null, $aggregation = false, $partial_fields = null)
    {
        $params = array(
            'index' => $this->index,
            'body' => array()
        );

        if ($aggregation)
            $params['body'] = $query;
        elseif ($query)
            $params['body']['query'] = $query;

        if ($type !== null)
            $params['type'] = $type;

        if ($partial_fields)
            $params['body']['partial_fields'] = $partial_fields;

        if ($filter !== null)
            $params['body']['filter'] = $filter;

        if ($pagination !== null) {
            $params['size'] = $pagination;               // how many results *per shard* you want back
        }

        if ($from !== null)
            $params['from'] = $from;

        try {
            if ($pagination === null && $from === null) {
                $params['search_type'] = 'count';
                return (int)$this->client->search($params)['hits']['total'];
            }


            if (Configuration::get('ELASTICSEARCH_SHOW_INSTOCK_FIRST') && !$aggregation) {
                $params['sort'] = array('in_stock_when_global_oos_deny_orders:desc');
            }
            if ($order_by && $order_way) {
                if (!isset($params['sort'])) {
                    $params['sort'] = array();
                }
                $params['sort'][] = $order_by.':'.$order_way;
            }

            if ($aggregation) {
                $params['search_type'] = 'count';
                return $this->client->search($params);
            }

            return $this->client->search($params)['hits']['hits'];   // Execute the search
        } catch (Exception $e) {
            self::log('Search failed', array('Message' => $e->getMessage(), 'index' => $this->index, 'params' => $params));
            return array();
        }
    }

    public function getAggregationQuery(array $required_fields)
    {
        $aggregation_query = array();

        foreach ($required_fields as $field)
        {
            if (isset($field['filter']))
            {
                $aggregation_query[$field['alias']] = array(
                    'filter' => $field['filter']
                );
            }

            $aggregation_query[$field['alias']]['aggs'][$field['alias']][$field['aggregation_type']] = array(
                'field' => $field['field']
            );
            if ($field['aggregation_type'] == 'terms') {
                $aggregation_query[$field['alias']]['aggs'][$field['alias']][$field['aggregation_type']]['size'] = 0;
            }
        }

        return $aggregation_query;
    }

    public function getDocumentsCount($type, array $query, $filter = null)
    {
        return $this->search($type, $query, null, null, null, null, $filter);
    }

    private function createIndexForCurrentShop()
    {
        if (!$this->createIndex($this->index))
        {
            $this->errors[] = $this->module_instance->l('Unable to create search index', self::FILENAME);
            return false;
        }

        sleep(1);

        return true;
    }

    public function indexExists($index_name)
    {
        $params = array(
            'index' => $index_name
        );

        return $this->client->indices()->exists($params);
    }

    protected function createIndex($index_name)
    {
        if ($this->indexExists($index_name))
            return true;

        if (!$index_name)
            return false;

        $index_params = array();

        $index_params['index'] = $index_name;
        $index_params['body']['settings']['analysis']['filter']['ngram_filter'] = array(
            'type' => 'ngram',
            'min_gram' => 1,
            'max_gram' => 30
        );
        $index_params['body']['settings']['analysis']['analyzer']['index_analyzer'] = array(
            'type' => 'custom',
            'tokenizer' => 'standard',
            'filter' => array(
                'lowercase',
                'ngram_filter'
            )
        );
        $index_params['body']['settings']['analysis']['analyzer']['search_analyzer'] = array(
            'type' => 'custom',
            'tokenizer' => 'standard',
            'filter' => 'lowercase'
        );
        $index_params['body']['settings']['number_of_shards'] = 1;
        $index_params['body']['settings']['number_of_replicas'] = 1;
        $index_params['body']['mappings']['products']['properties']['weight'] = array(
            'type' => 'double'
        );

        foreach (Language::getLanguages(false) as $lang)
        {
            $index_params['body']['mappings']['products']['properties']['search_keywords_'.$lang['id_lang']] = array(
                'type' => 'string',
                'index_analyzer' => 'index_analyzer',
                'search_analyzer' => 'search_analyzer'
            );

            $index_params['body']['mappings']['products']['properties']['name_'.$lang['id_lang']] = array(
                'type' => 'string'
            );
        }

        $index_params['body']['mappings']['categories']['properties']['nleft'] = array(
            'type' => 'long'
        );
        $index_params['body']['mappings']['categories']['properties']['nright'] = array(
            'type' => 'long'
        );

        return $this->client->indices()->create($index_params);
    }

    public function deleteShopIndex()
    {
        $delete_params = array();

        if (Shop::getContext() == Shop::CONTEXT_SHOP)
        {
            $index_name = $this->index;

            if (!$this->indexExists($index_name))
                return true;

            $delete_params['index'] = $index_name;
            $this->client->indices()->delete($delete_params);
            Configuration::deleteFromContext('ELASTICSEARCH_INDEX_PREFIX');
        }
        elseif (Shop::getContext() == Shop::CONTEXT_ALL)
        {
            $index_name = $this->index_prefix.'*';

            if (!$this->indexExists($index_name))
                return true;

            $delete_params['index'] = $index_name;
            $this->client->indices()->delete($delete_params);

            Configuration::deleteByName('ELASTICSEARCH_INDEX_PREFIX');
        }
        elseif (Shop::getContext() == Shop::CONTEXT_GROUP)
        {
            $id_shop_group = Context::getContext()->shop->id_shop_group;
            foreach (Shop::getShops(false, $id_shop_group, true) as $id_shop)
            {
                $index_name = $this->index_prefix.(int)$id_shop;

                if (!$this->indexExists($index_name))
                    return true;

                $delete_params['index'] = $index_name;
                $this->client->indices()->delete($delete_params);
                $id = Configuration::getIdByName('ELASTICSEARCH_INDEX_PREFIX', $id_shop_group, $id_shop);
                $configuration = new Configuration($id);
                $configuration->delete();
            }
        }
    }
}
