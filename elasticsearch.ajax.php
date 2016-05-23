<?php

/* Fix for multishop */
define('_IGNORE_SHOP_REDIRECT_', true);
/* End antilop fix for multishop */

include_once(dirname(__FILE__).'/../../config/config.inc.php');
include_once(dirname(__FILE__).'/../../init.php');

/* Fix for multishop */
$id_shop = Tools::getValue('id_shop');
if ($id_shop) {
    $shop = new Shop((int)$id_shop);
    if (Validate::isLoadedObject($shop)) {
        Shop::setContext(Shop::CONTEXT_SHOP, $shop->id);
        Context::getContext()->shop = $shop;
    }
} else {
    die();
}
/* End fix for multishop */

if (Tools::getValue('token') != Tools::getToken(false)) {
    exit;
}

if (Tools::isSubmit('submitElasticsearchSearch')) {
    $module_instance = Module::getInstanceByName('elasticsearch');
    $result = $module_instance->submitSearchQuery();

    die(Tools::jsonEncode($result));
}

if (Tools::isSubmit('submitElasticsearchAjaxSearch')) {
    $module_instance = Module::getInstanceByName('elasticsearch');
    $result = $module_instance->processAjaxSearch();

    die($result);
}

if (Tools::isSubmit('submitElasticsearchFilter')) {
    $module_instance = Module::getInstanceByName('elasticsearch');

    require_once(_ELASTICSEARCH_CLASSES_DIR_.'ElasticSearchFilter.php');

    $filter = new ElasticSearchFilter();
    $result = $filter->ajaxCall();

    die(Tools::jsonEncode($result));
}
