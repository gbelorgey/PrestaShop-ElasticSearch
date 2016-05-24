<?php
/**
 * 2015 Invertus, UAB
 *
 * NOTICE OF LICENSE
 *
 * This file is proprietary and can not be copied and/or distributed
 * without the express permission of INVERTUS, UAB
 *
 *  @author    INVERTUS, UAB www.invertus.eu <help@invertus.eu>
 *  @copyright 2015 INVERTUS, UAB
 *  @license   --
 *  International Registered Trademark & Property of INVERTUS, UAB
 */

class AdminElasticMenuSearchFilterController extends ModuleAdminController
{
    public function __construct()
    {
        $this->meta_title = $this->l('Elastic Menu Search filter settings');

        parent::__construct();

        $this->token = Tools::getAdminTokenLite('AdminModules');
        $this->table = 'elasticsearch_menu_template';
        $this->class = 'ElasticMenuSearchTemplate';

        self::$currentIndex = 'index.php?controller=AdminModules&configure='.$this->module->name.'&menu=MenuFilter';
    }

    public function updateOptions()
    {
        $this->table = 'Configuration';
        $this->class = 'Configuration';

        $this->processUpdateOptions();
    }

    public function initOptionFields()
    {
        $this->fields_options = array(
            'main_settings' => array(
                'title' => $this->l('Elastic Search filter settings'),
                'fields' => array(
                    'ELASTICSEARCH_DISPLAY_FILTER' => array(
                        'type' => 'bool',
                        'title' => $this->l('Display products filter in page column')
                    ),
                    'ELASTICSEARCH_HIDE_0_VALUES' => array(
                        'type' => 'bool',
                        'title' => $this->l('Hide filter values when no product is matching')
                    ),
                    'ELASTICSEARCH_SHOW_QTIES' => array(
                        'type' => 'bool',
                        'title' => $this->l('Show the number of matching products')
                    ),
                    'ELASTICSEARCH_FULL_TREE' => array(
                        'type' => 'bool',
                        'title' => $this->l('Show products from subcategories')
                    ),
                    'ELASTICSEARCH_CATEGORY_DEPTH' => array(
                        'title' => $this->l('Category filter depth'),
                        'size' => 3,
                        'cast' => 'intval',
                        'validation' => 'isInt',
                        'type' => 'text',
                        'desc' => $this->l('0 for no limits')
                    ),
                    'ELASTICSEARCH_PRICE_USETAX' => array(
                        'type' => 'bool',
                        'title' => $this->l('Use tax to filter price')
                    )
                ),
                'submit' => array('title' => $this->l('Save'))
            )
        );
    }

    public function initForm()
    {
        $top_shelf = Configuration::get('MENU_TOP_SHELF');
        $top_shelf = explode(';', $top_shelf);
        $top_shelf = Category::getCategoryInformations($top_shelf);
        $this->object = new ElasticMenuSearchTemplate();

        $this->fields_form = array(
            'legend' => array(
                'title' => $this->l('Manage Menu filter template'),
                'icon' => 'icon-cogs'
            ),
            'input' => array(
                array(
                    'type' => 'text',
                    'label' => $this->l('Template Menu name'),
                    'name' => 'elasticsearch_tpl_name',
                    'required' => true,
                    'desc' => $this->l('Only as a reminder')
                ),
                array(
                    'type' => 'checkbox',
                    'name' => 'categoryBox',
                    'class' => 'js-filter-category',
                    'label' => $this->l('Categories used for this template'),
                    'required' => true,
                    'values' => array(
                        'id' => 'id_category',
                        'name' => 'name',
                        'query' => $top_shelf
                    )
                )
            ),
            'submit' => array(
                'title' => $this->l('Save'),
            )
        );

        if (Shop::isFeatureActive()) {
            $this->fields_form['input'][] = array(
                'type' => 'shop',
                'label' => $this->l('Shop association'),
                'name' => 'checkBoxShopAsso',
                'required' => true
            );
        }
        $this->fields_form['input'][] = array(
            'type' => 'free',
            'label' => $this->l('Template settings'),
            'required' => true,
            'name' => 'templateSettingsManagement'
        );

        $categories = $this->getSelectedCategories();
        if (is_array($categories)) {
            foreach ($categories as $id_category) {
                $this->fields_value['categoryBox_'.$id_category] = true;
            }
        }

        $this->fields_value['templateSettingsManagement'] = $this->displayFilterTemplateManagemetList();
    }

    protected function getSelectedCategories()
    {
        $elasticsearch_template = new ElasticMenuSearchTemplate((int)Tools::getValue('id_elasticsearch_menu_template'));

        if (!Validate::isLoadedObject($elasticsearch_template)) {
            $this->fields_value['elasticsearch_tpl_name'] = sprintf($this->l('My template %s'), date('Y-m-d'));
            $this->context->smarty->assign('elasticsearch_selected_shops', '');
            return array();
        }

        $this->object = $elasticsearch_template;
        $this->identifier = 'id_elasticsearch_menu_template';
        $filters = unserialize($elasticsearch_template->filters);
        $this->fields_value['elasticsearch_tpl_name'] = $elasticsearch_template->name;

        $return = array();
        if (isset($filters['categories'])) {
            $return = $filters['categories'];
        }
        $elasticsearch_selected_shops = '';

        foreach ($filters['shop_list'] as $id_shop) {
            $elasticsearch_selected_shops .= $id_shop.', ';
        }

        $elasticsearch_selected_shops = Tools::substr($elasticsearch_selected_shops, 0, -2);
        $this->context->smarty->assign('elasticsearch_selected_shops', $elasticsearch_selected_shops);

        unset($filters['categories']);
        unset($filters['shop_list']);

        $this->context->smarty->assign('filters', Tools::jsonEncode($filters));

        return $return;
    }

    private function displayFilterTemplateManagemetList()
    {
        $attribute_groups = ElasticMenuSearchTemplate::getAttributes();
        $features = ElasticMenuSearchTemplate::getFeatures();
        $module_instance = Module::getInstanceByName('elasticsearch');

        $urlParts = array();
        $params   = [
            'controller',
            'configure',
            'token',
        ];

        foreach ($params as $param) {
            $urlParts[] = $param . '=' . Tools::getValue($param);
        }

        $urlParts[] = 'menu=manage_menu_filter_template_values';

        $this->context->smarty->assign(array(
            'current_url' => $module_instance->module_url,
            'id_elasticsearch_menu_template' => 0,
            'attribute_groups' => $attribute_groups,
            'features' => $features,
            'total_filters' => 6 + count($attribute_groups) + count($features),
            'ajaxUrl' => '?' . implode('&', $urlParts),
        ));

        return $this->context->smarty->fetch(_ELASTICSEARCH_TEMPLATES_DIR_.'admin/templates_management_list_menu.tpl');
    }
}
