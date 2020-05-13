<?php

class ExchangeAPI
{
    protected $token = '';
    protected $app_id = '';
    protected $secret = '';
    protected $address = '';
    protected $api;
    protected $res;
    private $IBLOCK_ID = 12;

    public function __construct()
    {
        CModule::IncludeModule('iblock');
    }

    public function GetListModel($type, $deleted = false)
    {
        $api = new Business_ru($this->app_id, $this->token, $this->address); // создаем экземпляр класса Class365_api_lib
        $api->setSecret($this->secret); // устанавливаем секретный ключ
        $res = $api->repair(); // восстановление токена

        $action = 'get'; // операция
        $model = $type; // модель
        $DATA = array();

        $i = 1;

        do {
            $token = $res['token']; // извлечение токена из ответа сервера
            $api->setToken($token); // установка текущего токена

            if ($deleted == 1 && $model == 'goods') {
                $params = array('deleted' => 1, 'limit' => 10);
            } else {
                $params = array('limit' => 250, 'page' => $i++);  // устанавливаем параметры пагинации
            }

            $res = $api->request($action, $model, $params); // отправляем запрос

            foreach ($res['result'] as $item) { // сканируем полученный массив заказов покупателей
                if ($model == 'salepricelistgoodprices') {
                    $DATA[$item['price_list_good_id']] = $item;
                } else {
                    $DATA[$item['id']] = $item;
                }
            }
        } while (count($res['result']) > 100); // делаем это до тех пор пока не извлечем все заказы

        return $DATA;
    }

    public function SplitDate($date)
    {
        $up = explode(' ', $date);
        $up2 = explode('.', $up[1]);

        return $up[0].' '.$up2[0];
    }

    public function GetSectionByCode($CODE)
    {
        $arFilter = array('IBLOCK_ID' => $this->IBLOCK_ID, 'CODE' => $CODE);
        $rsSections = CIBlockSection::GetList(array('LEFT_MARGIN' => 'ASC'), $arFilter);
        $arSection = $rsSections->Fetch();

        while ($arSection) {
            return $arSection['ID'];
        }
    }

    public function InsertProduct($product)
    {
        $el = new CIBlockElement();

        $section = $this->GetSectionByCode($product['group_id']);
        $name = $product['name'].', '.$product['code'];
        $code = $this->TranslitURL($name);

        $PROP = array(
                        'VOLUME' => $product['volume'],
                        'WEIGHT' => $product['weight'],
                        'COUNTRY' => $product['country']['name'],
                        'COLOR' => $product['props']['Цвет']['name'],
                        'WIDTH' => $product['props']['Длинна, м']['name'],
                        'MATERIAL' => $product['props']['Материал']['name'],
                        'MEASURE' => $product['measure'],
                        'ARTNUMBER' => $product['code'],
                        'STARTSHOP_PRICE_1' => $product['prices']['ОПТ-1'],
                        'STARTSHOP_PRICE_2' => $product['prices']['ОПТ-2'],
                        'STARTSHOP_PRICE_3' => $product['prices']['ОПТ-3'],
                        'STARTSHOP_PRICE_4' => $product['prices']['ОПТ-4'],
                        'STARTSHOP_PRICE_5' => $product['prices']['ОПТ-5'],
                        'MIN_PRICE' => $product['prices']['ОПТ-5'],
                        'STARTSHOP_CURRENCY_1' => 376, // Пока что так
                        'STARTSHOP_CURRENCY_2' => 382,
                        'STARTSHOP_CURRENCY_3' => 380,
                        'STARTSHOP_CURRENCY_4' => 378,
                        'STARTSHOP_CURRENCY_5' => 384,
                        'STARTSHOP_QUANTITY_RATIO' => 1,
                        'STARTSHOP_QUANTITY' => $product['amount'],
                        'ID_BASE' => $product['id'],
                        );

        foreach ($product['images'] as $key => $item) {
            if ($key == 0) {
                continue;
            }

            $PROP['MORE_PHOTO'][] = array(
                                            'VALUE' => CFile::MakeFileArray($item),
                                            'DESCRIPTION' => '',
                                            );
        }

        $arLoadProductArray = array(
            'IBLOCK_SECTION_ID' => $section,
            'IBLOCK_ID' => $this->IBLOCK_ID,
            'NAME' => $product['name'],
            'CODE' => $code,
            'ACTIVE' => 'Y',
            'PROPERTY_VALUES' => $PROP,
            'DETAIL_PICTURE' => CFile::MakeFileArray($product['images'][0]),
            'DETAIL_TEXT' => $product['description'],
            'DETAIL_TEXT_TYPE' => 'html',
        );

        if ($product['archive']) {
            $arLoadProductArray['ACTIVE'] = 'N';
        }

        $ID = $this->IssetProduct($product['id']);

        if ($ID) {
            $this->DeletePhotos($ID);
            $el->Update($ID, $arLoadProductArray);
        } else {
            $el->Add($arLoadProductArray);
        }
    }

    public function IssetProduct($CODE)
    {
        $arSelect = array(
           'ID',
           'NAME',
        );

        $arFilter = array('PROPERTY_ID_BASE' => $CODE);

        $res = CIBlockElement::GetList(array(), $arFilter, false, false, $arSelect);
        while ($rs = $res->Fetch()) {
            return $rs['ID'];
        }
    }

    public function AddCategories()
    {
        // Исключим верхнюю категорию
        $new_categories = array();
        $cats = $this->GetListModel('groupsofgoods');

        foreach ($cats as $key => $item) {
            if ($item['id'] == 1) {
                unset($item);
            } else {
                if ($item['parent_id'] == 1) {
                    $item['parent_id'] = '';
                }

                $new_categories[$item['id']] = $item;
            }
        }

        $arResult['CATEGORIES'] = $new_categories;

        $CATS = $this->GetAllCats();

        // Удалим лишние
        foreach ($CATS as $key => $cat) {
            if (!$arResult['CATEGORIES'][$key]) {
                CIBlockSection::Delete($cat);
            }
        }

        foreach ($arResult['CATEGORIES'] as $key => $item) {
            $params = array(
                'ID' => $item['id'],
                'NAME' => $item['name'],
                'CODE' => $item['id'],
                'PARENT_ID' => $item['parent_id'],
                'DELETED' => $item['deleted'],
            );

            $this->AddCategory($params);
        }

    }

    public function GetAllCats()
    {
        $items = array();
        $arFilter = array('IBLOCK_ID' => $this->IBLOCK_ID);
        $rsSections = CIBlockSection::GetList(array('LEFT_MARGIN' => 'ASC'), $arFilter);
        while ($arSection = $rsSections->Fetch()) {
            $items[$arSection['CODE']] = $arSection['ID'];
        }
        return $items;
    }

    public function AddCategory($params)
    {
        $bs = new CIBlockSection();
        $arFields = array(
            //"ACTIVE" => "Y",
            //"IBLOCK_SECTION_ID" => $IBLOCK_SECTION_ID,
            'IBLOCK_ID' => $this->IBLOCK_ID,
            'NAME' => $params['NAME'],
            'CODE' => $params['CODE'],
        );

        if ($params['NAME'] == 'НЕСОРТИРОВАННЫЕ') {
            $arFields['ACTIVE'] = 'N';
        }

        if ($params['PARENT_ID'] != '') {
            $arFields['IBLOCK_SECTION_ID'] = $this->GetSectionByCode($params['PARENT_ID']);
        }

        // Существует или нет
        $ID = $this->GetSectionByCode($params['ID']);

        if (!$ID) {
            if ($params['DELETED'] == 1) {
                CIBlockSection::Delete($ID);
            } else {
                $ID = $bs->Add($arFields);
            }
        } else {
            $bs->Update($ID, $arFields);
        }

        return $ID;
    }

    public function UploadImage($params)
    {
        $path = $_SERVER['DOCUMENT_ROOT'].'/upload/api_export/'.$params['name'];

        copy($params['url'], $path);

        return $path;
    }

    public function DeletePhotos($ELEMENT_ID)
    {
        $IB = $this->IBLOCK_ID;
        $ID = $ELEMENT_ID;
        $CODE = 'MORE_PHOTO';
        $arProp = CIBlockElement::GetProperty($IB, $ID, 'ID', 'DESC', array('CODE' => $CODE))->fetch();
        $db_props = CIBlockElement::GetProperty($IB, $ID, 'sort', 'asc', array('CODE' => 'MORE_PHOTO'));

        if ($arProp) {
            $XXX = $arProp['PROPERTY_VALUE_ID'];
            CIBlockElement::SetPropertyValueCode($ID, $CODE, array($XXX => array('del' => 'Y')));
        }

        while ($ar_props = $db_props->Fetch()) {
            if ($ar_props['VALUE']) {
                $ids[] = $ar_props['PROPERTY_VALUE_ID'];

                CFile::Delete($ar_props['VALUE']);
                //@unlink($new_make_file);
            }
        }

        if (count($ids) > 0) {
            global $DB;
            $strSql = 'DELETE FROM b_iblock_element_property WHERE `id` IN ('.implode(',', $ids).') ';

            $DB->Query($strSql, false);
        }
    }

    public function GetItemByName($NAME)
    {
        global $DB;
        $results = $DB->Query("SELECT * FROM `b_iblock_element` WHERE `NAME`='$NAME' ");

        while ($row = $results->Fetch()) {
            return $row['ID'];
        }
    }

    public function ParsePrices()
    {
        $path = $_SERVER['DOCUMENT_ROOT'].'/upload/prices.csv';

        $count = 0;

        if (($handle = fopen($path, 'r')) !== false) {
            while (($data = fgetcsv($handle, 1000, ',')) !== false) {
                // Заголовки
                if ($count == 0) {
                    $header = array();
                    foreach ($data as $key => $d) {
                        $header[$d] = $key;
                    }
                }

                $name = trim($data[1]);
                $price1 = $data[$header['ОПТ-1']];
                $price2 = $data[$header['ОПТ-2']];
                $price3 = $data[$header['ОПТ-3']];
                $price4 = $data[$header['ОПТ-4']];
                $price5 = $data[$header['ОПТ-5']];

                $element_id = $this->GetItemByName($name);

                if ($element_id) {
                    $props = array(
                                    'STARTSHOP_PRICE_1' => round($price1),
                                    'STARTSHOP_PRICE_2' => round($price2),
                                    'STARTSHOP_PRICE_3' => round($price3),
                                    'STARTSHOP_PRICE_4' => round($price4),
                                    'STARTSHOP_PRICE_5' => round($price5),
                                    'STARTSHOP_CURRENCY_1' => 376, // Пока что так
                                    'STARTSHOP_CURRENCY_2' => 382,
                                    'STARTSHOP_CURRENCY_3' => 380,
                                    'STARTSHOP_CURRENCY_4' => 378,
                                    'STARTSHOP_CURRENCY_5' => 384,
                                    );

                    CIBlockElement::SetPropertyValuesEx($element_id, false, $props);
                }

                ++$count;
            }
            fclose($handle);
        }
    }

    public function TranslitURL($text, $translit = 'ru_en')
    {
        $RU['ru'] = array(
            'Ё', 'Ж', 'Ц', 'Ч', 'Щ', 'Ш', 'Ы',
            'Э', 'Ю', 'Я', 'ё', 'ж', 'ц', 'ч',
            'ш', 'щ', 'ы', 'э', 'ю', 'я', 'А',
            'Б', 'В', 'Г', 'Д', 'Е', 'З', 'И',
            'Й', 'К', 'Л', 'М', 'Н', 'О', 'П',
            'Р', 'С', 'Т', 'У', 'Ф', 'Х', 'Ъ',
            'Ь', 'а', 'б', 'в', 'г', 'д', 'е',
            'з', 'и', 'й', 'к', 'л', 'м', 'н',
            'о', 'п', 'р', 'с', 'т', 'у', 'ф',
            'х', 'ъ', 'ь', '/',
            );

        $EN['en'] = array(
            'Yo', 'Zh',  'Cz', 'Ch', 'Shh', 'Sh', "Y'",
            "E'", 'Yu',  'Ya', 'yo', 'zh', 'cz', 'ch',
            'sh', 'shh', "y'", "e'", 'yu', 'ya', 'A',
            'B', 'V',  'G',  'D',  'E',  'Z',  'I',
            'J',  'K',   'L',  'M',  'N',  'O',  'P',
            'R',  'S',   'T',  'U',  'F',  'Kh',  "''",
            "'",  'a',   'b',  'v',  'g',  'd',  'e',
            'z',  'i',   'j',  'k',  'l',  'm',  'n',
            'o',  'p',   'r',  's',  't',  'u',  'f',
            'h',  "''",  "'",  '-',
            );

        if ($translit == 'en_ru') {
            $t = str_replace($EN['en'], $RU['ru'], $text);
            $t = preg_replace('/(?<=[а-яё])Ь/u', 'ь', $t);
            $t = preg_replace('/(?<=[а-яё])Ъ/u', 'ъ', $t);
        } else {
            $t = str_replace($RU['ru'], $EN['en'], $text);
            $t = preg_replace("/[\s]+/u", '_', $t);
            $t = preg_replace("/[^a-z0-9_\-]/iu", '', $t);
            $t = strtolower($t);
        }

        return $t;
    }
}
