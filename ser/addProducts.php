<?php

require $_SERVER['DOCUMENT_ROOT'].'/bitrix/header.php';
$APPLICATION->SetTitle('Business API');

$EX = new ExchangeAPI();
$EX->AddCategories();

// Параметры
if ($_GET['count']) {
    if ($_GET['count'] == 'all') {
        $_GET['count'] = 1000000000;
    }

    $min_update = strtotime('-'.$_GET['count'].' hours', time());
} else {
    $min_update = strtotime('-1 hours', time());
}

$products = $EX->GetListModel('goods', $min_update);
$productsDeleted = $EX->GetListModel('goods', 1);
$storeGoods = $EX->GetListModel('storegoods');
$currentPrices = $EX->GetListModel('currentprices');
$priceTypes = $EX->GetListModel('salepricetypes');
$countries = $EX->GetListModel('countries');
$measuresTypes = $EX->GetListModel('measures');
$measuresGoods = $EX->GetListModel('goodsmeasures');
$attributes = $EX->GetListModel('attributesforgoods');
$attributesValues = $EX->GetListModel('attributesforgoodsvalues');
$attributesGoods = $EX->GetListModel('goodsattributes');

// Единица измерения товаров
foreach ($measuresGoods as $key_measure => $item_measure) {
    // Ссылка на ед. измерения
    $measure = $measuresTypes[$item_measure['measure_id']]['short_name'];

    // Присвоение ед. измерения продукту
    $products[$item_measure['good_id']]['measure'] = $measure;
}

// Свойства товаров
foreach ($attributesGoods as $key_attributesGood => $item_attributesGood) {
    $products[$item_attributesGood['good_id']]['props'][] = $item_attributesGood;
}

foreach ($products as $key_product => $item_product) {
    //Cоздание пустого массива
        //для новых свойст товара
    $props_new = array();
        //для новых цен товара
    $new_prices = array();

    // Присвоение страны продукту
    $linkToCountryFromProduct = $countries[$item_product['country_id']];
    $products[$key_product]['country'] = $linkToCountryFromProduct;

    // Свойства товаров
    foreach ($item_product['props'] as $key_item_product => $item_item_product) {
        $attr = $attributesValues[$item_item_product['value_id']];
        $attr['attribute_name'] = $attributes[$item_item_product['attribute_id']]['name'];

        $props_new[$attr['attribute_name']] = $attr;

        $products[$key_product]['props'][$key_item_product] = $attr;
    }

    if (count($props_new) > 0) {
        $products[$key_product]['props'] = $props_new;
    }

    // Цены товаров
    foreach ($currentPrices as $key_currentPrice => $item_currentPrice) {
        if ($item_currentPrice['good_id'] == $item_product['id']) {
            $price_type = $priceTypes[$item_currentPrice['price_type_id']]['name'];

            if ($price_type != '') {
                $new_prices[$price_type] = $item_currentPrice['price'];
            }
        }
    }

    $products[$key_product]['prices'] = $new_prices;

    // Остатки
    foreach ($storeGoods as $key_storeGood => $item_storeGood) {
        if ($item_storeGood['good_id'] == $item_product['id']) {
            $products[$key_product]['amount'] = $item_storeGood['amount'];
        }
    }
}

foreach ($products as $key_product => $item_product) {
    // Вырежем странные числа сзади
    $time1 = $EX->SplitDate($item_product['updated']);
    $time2 = $EX->SplitDate($item_product['updated_remains_prices']);

    if (strtotime($time1) > strtotime($time2)) {
        $last_update = $time1;
    } else {
        $last_update = $time2;
    }

    $last_update = strtotime($last_update);

    //Проверка на
    //Если товар был изменён/обновлён
    //и
    //У товара есть остатки
    if ($last_update > $min_update && $item_product['amount']) {
        foreach ($item_product['images'] as $key_image => $item_image) {
            $item_product['images'][$key_image] = $EX->UploadImage($item_image);
        }

        $EX->InsertProduct($item_product);

        echo '<pre>';
        print_r($item_product);
        echo '</pre>';

    } else {
        // Не загружать товары с 0-ым остатком
        unset($key_product);
    }
}

//Добавить цены
$EX->ParsePrices();

die();