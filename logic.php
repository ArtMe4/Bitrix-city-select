<?php

function get_domain()
{
    $subDomainParts = explode('.', $_SERVER['HTTP_HOST']);
    if (count($subDomainParts) > 2) {
        $maxIndex = count($subDomainParts) - 1;
        return $subDomainParts[$maxIndex - 1] . '.' . $subDomainParts[$maxIndex];
    } else
        return $_SERVER['HTTP_HOST'];
}

use Bitrix\Main\Loader,
    Rover\GeoIp\Location;

CModule::IncludeModule("iblock");

const IBLOCK_ID = null; //ID Инфоблока с гродами

global $locations, $selectedLocation;
$locations = array();
$selectedLocation = array();
$mainLocationId = false;

$arSelect = array("ID", "NAME", "CODE", "IBLOCK_ID", "PROPERTY_*");
$arFilter = array("IBLOCK_ID" => IBLOCK_ID, "ACTIVE" => "Y");
$res = CIBlockElement::GetList(array('name' => 'asc'), $arFilter, false, false, $arSelect);

while ($ob = $res->GetNextElement()) { //получаем список городов из Инфоблока

    $arFields = $ob->GetFields();
    $arProps = $ob->GetProperties();

    $locations[$arFields['ID']] = array(
        'id' => $arFields['ID'],
        'name' => $arFields['NAME'],
        'code' => $arFields['CODE'],
        'phone' => $arProps['PHONE']['VALUE'],
        'region_name' => $arProps['REGION_NAME']['VALUE'],
        'main' => ($arProps['MAIN_REGION']['VALUE_XML_ID'] == 'Y' ? true : false),
        'CITY' => $arProps['CITY']['VALUE'],
        'CITY_P' => $arProps['CITY_P']['VALUE'],
    );

    if ($arProps['MAIN_REGION']['VALUE_XML_ID'] == 'Y')
        $mainLocationId = $arFields['ID']; //определяем id главного города

}

if ($_COOKIE['selected_city'] && array_key_exists(intval($_COOKIE['selected_city']), $locations)) { //если город выбран

    $selectedLocation = $locations[intval($_COOKIE['selected_city'])];

    //определеяем корректный поддомен
    $correctSubDomain = get_domain();
    if (!$selectedLocation['main']) {
        $correctSubDomain = $selectedLocation['code'] . '.' . $correctSubDomain;
    }

    //если сейчас некорректный поддомен, то редиректим на корректный
    if ($_SERVER['HTTP_HOST'] != $correctSubDomain) {

        /*удаляем все cookie файлы callibri при смене домена*/
        $cookiesKeys = array_keys($_COOKIE);
        foreach ($cookiesKeys as $cookiesKey) {
            if (stripos($cookiesKey, 'callibri') !== false) {
                unset($_COOKIE[$cookiesKey]);
                setcookie($cookiesKey, '', time() - 3600, '/', '.' . get_domain());
            }
        }

        header("Location: https://" . $correctSubDomain . $_SERVER['REQUEST_URI']);
        exit();

    }

} else { //иначе определяем по IP

    if (Loader::includeModule('rover.geoip')) {

        $locationIP = Location::getInstance();

        if ($locationIP->isSuccess()) {

            $userLocationInfo = $locationIP->getData(); //получаем данные о городе пользователя по IP

            foreach ($locations as $location) {

                if (strtolower($userLocationInfo['city_name']) == strtolower($location['region_name'])
                    || strtolower($userLocationInfo['region_name']) == strtolower($location['region_name'])) { //если нашли определенный город в инфоблоке
                    $selectedLocation = $location;
                    break;
                }

            }

        }

    }

    if (empty($selectedLocation)) { //если город так и не выбран (не нашли, не смогли определить, не подключен модуль)

        $subDomainParts = explode('.', $_SERVER['HTTP_HOST']);

        if (count($subDomainParts) > 2) { //если не основной доме

            foreach ($locations as $location) {

                if ($location['code'] == $subDomainParts[0]) { //если нашли город по поддомену
                    $selectedLocation = $location; //устанавливаем город на основе поддомена
                    break;
                }

            }

        }

        if (empty($selectedLocation)) //если ничего выше не сработало
            $selectedLocation = $locations[$mainLocationId]; // устанавливаем основой город

    }

}
