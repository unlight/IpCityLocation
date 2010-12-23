<?php
require_once dirname(__FILE__).'/../../plugins/PluginUtils/bootstrap.console.php';

class Tick extends Gdn_Pluggable {
	// nothing here …
}

$FH = new Tick();
require_once dirname(__FILE__).DS.'ipcitylocation.plugin.php';
$IpCityLocationPlugin = new IpCityLocationPlugin();
//$IpCityLocationPlugin->Tick_Match_30_Minutes_01_Hours_7_Day_Handler($FH);
$IpCityLocationPlugin->GetDataFromIpGeoBase($FH);
$IpCityLocationPlugin->GetDataFromGeoliteMaxmind($FH);
//$FH->FireEvent('IpCityLocationUpdate');


