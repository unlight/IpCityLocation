<?php
require_once dirname(__FILE__).'/../../plugins/UsefulFunctions/bootstrap.console.php';

ini_set('memory_limit', '512M');

class Tick extends Gdn_Pluggable {
	// nothing here …
}

$FH = new Tick();
require_once dirname(__FILE__).DS.'ipcitylocation.plugin.php';
$IpCityLocationPlugin = new IpCityLocationPlugin();

$IpCityLocationPlugin->Match_15_Minutes_01_Hours_Sunday();
//$IpCityLocationPlugin->GetDataFromIpGeoBase();
/*$IpCityLocationPlugin->GetDataFromGeoliteMaxmind();


$Prefix = Gdn::SQL()->Database->DatabasePrefix;
Gdn::SQL()->Query("alter table {$Prefix}IpCityLocation order by IpDifference asc");
Gdn::SQL()->Query("optimize table {$Prefix}IpCityLocation");
Gdn::PluginManager()->FireEvent('IpCityLocationUpdate');*/

//SaveToConfig('Plugins.IpCityLocation.UpdateDate', Gdn_Format::ToDateTime());*/