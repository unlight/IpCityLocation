<?php
require_once dirname(__FILE__).'/../../plugins/PluginUtils/bootstrap.console.php';

ini_set('memory_limit', '512M');

class Tick extends Gdn_Pluggable {
	// nothing here …
}

$FH = new Tick();
require_once dirname(__FILE__).DS.'ipcitylocation.plugin.php';
$IpCityLocationPlugin = new IpCityLocationPlugin();

$IpCityLocationPlugin->Tick_Match_30_Minutes_01_Hours_7_Day_Handler();
//$IpCityLocationPlugin->GetDataFromIpGeoBase();
//$IpCityLocationPlugin->GetDataFromGeoliteMaxmind();


/*$Prefix = Gdn::SQL()->Database->DatabasePrefix;
Gdn::SQL()->Query("alter table {$Prefix}IpCityLocation order by IpDifference asc");
Gdn::SQL()->Query("optimize table {$Prefix}IpCityLocation");
Gdn::PluginManager()->FireEvent('IpCityLocationUpdate');*/

//SaveToConfig('Plugins.IpCityLocation.UpdateDate', Gdn_Format::ToDateTime());*/