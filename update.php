<?php
require_once dirname(__FILE__).'/../../plugins/UsefulFunctions/bootstrap.console.php';

ini_set('memory_limit', '512M');

// nothing here …

$FH = new Tick();
require_once dirname(__FILE__).DS.'ipcitylocation.plugin.php';
$IpCityLocationPlugin = new IpCityLocationPlugin();

$IpCityLocationPlugin->Tick_Match_15_Minutes_01_Hour_Sunday_Handler();
//$IpCityLocationPlugin->GetDataFromIpGeoBase();
//$IpCityLocationPlugin->GetDataFromGeoliteMaxmind();
