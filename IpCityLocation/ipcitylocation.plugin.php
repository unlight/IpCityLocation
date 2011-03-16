<?php if (!defined('APPLICATION')) exit();

/*
CONFIG:
$Configuration['Plugins']['IpCityLocation']['Country'] = array('BY', 'MN', 'KZ', 'UA');
*/

$PluginInfo['IpCityLocation'] = array(
	'Name' => 'IpCityLocation',
	'Description' => 'IP Geolocation. Joint database of ipgeobase.ru and geolite.maxmind.com (for developers).',
	'Version' => '1.7.15',
	'Date' => '16 Mar 2011',
	'Author' => 'John Smith',
	'RequiredPlugins' => array('PluginUtils' => '>=2.4.84')
);

if (!function_exists('GetCityNameByIp')) {
	function GetCityNameByIp($RemoteAddr = False, $ResetCache = False, $Default = False) {
		$Result = IpCityLocationPlugin::Get($RemoteAddr, $ResetCache);
		$CityName = ObjectValue('CityName', $Result, $Default);
		return $CityName;
	}
}

class IpCityLocationPlugin implements Gdn_IPlugin {
	
	public function PluginController_IpCityName_Create($Sender) {
		$Ip = GetValue(0, $Sender->RequestArgs);
		if (!is_numeric($Ip)) $Ip = sprintf('%u', ip2long($Ip));
		$CityName = GetCityNameByIp($Ip, False, 'Москва');
		echo $CityName;
	}
	
	// Cron: every 5-th day of month on 01:30
	public function Tick_Match_30_Minutes_01_Hours_7_Day_Handler() {
		$Prefix = Gdn::SQL()->Database->DatabasePrefix;
		ini_set('memory_limit', '512M');
		$this->GetDataFromIpGeoBase();
		Gdn::SQL()->Query("alter table {$Prefix}IpCityLocation order by IpDifference asc");
		$this->GetDataFromGeoliteMaxmind();
		Gdn::SQL()->Query("alter table {$Prefix}IpCityLocation order by IpDifference asc");
		Gdn::SQL()->Query("optimize table {$Prefix}IpCityLocation");
		//if ($bPolygonField) TODO: ADD/DROP PRIMARY KEY
		Gdn::PluginManager()->FireEvent('IpCityLocationUpdate');
		SaveToConfig('Plugins.IpCityLocation.UpdateDate', Gdn_Format::ToDateTime());
	}
	
	public static function Get($RemoteAddr = False, $ResetCache = False) {
		static $Cache, $bPolygonField;
		if ($bPolygonField === NULL) $bPolygonField = C('Plugins.IpCityLocation.PolygonField');
		if (!$RemoteAddr) $RemoteAddr = GetIpAddress();
		if (!isset($Cache[$RemoteAddr]) || $ResetCache) {
			$SQL = Gdn::SQL();
			if ($bPolygonField) {
				$RemoteAddr = $SQL->NamedParameter('RemoteAddr', False, $RemoteAddr);
				$SQL->Where("mbrcontains(PolygonIpRange, pointfromwkb(point($RemoteAddr, 0)))", Null, False, False);
			} else {
				$SQL
					->Where('IP2 >=', $RemoteAddr, False, False)
					->Where('IP1 <=', $RemoteAddr, False, False)
					->OrderBy('IpDifference', 'asc');
			}
			$Cache[$RemoteAddr] = $SQL
				->Select('*')
				->From('IpCityLocation')
				->Limit(1)
				->Get()
				->FirstRow();
		}
		$Result = $Cache[$RemoteAddr];
		return $Result;
	}

	private static function SaveFile($File) {
		$Filename = dirname(__FILE__) . DS . pathinfo($File, PATHINFO_BASENAME);
		if (!file_exists($Filename)) file_put_contents($Filename, file_get_contents($File));
		$PluginDirectory = dirname(__FILE__);
		if (LoadExtension('zip', False)) {
			$Zip = new ZipArchive();
			$Zip->Open($Filename);
			$Zip->ExtractTo($PluginDirectory);
			$Zip->Close();
		} else {
			exec("unzip {$Filename} -d {$PluginDirectory}", $Result);
		}
	}
	
	public function GetDataFromGeoliteMaxmind() {

		$Database = Gdn::Database();
		$Database->CloseConnection();
		
		Console::Message('Getting database from maxmind');
		
		// Get last version in CSV format
		$Base = 'http://geolite.maxmind.com/download/geoip/database/GeoLiteCity_CSV/';
		$Doc = PqDocument($Base.'?C=M;O=D');

		$File = $Base . Pq('a[href^="GeoLiteCity"]')->Attr('href');
		self::SaveFile($File);
		$Directory = pathinfo($File, PATHINFO_FILENAME);
		
		
		$CSVFile = dirname(__FILE__).DS.$Directory.DS.'GeoLiteCity-Location.csv';
		$OnlyCountry = C('Plugins.IpCityLocation.Country', array('BY', 'MN', 'KZ', 'UA'));
		$Resource = fopen($CSVFile, 'r+');
		fgetcsv($Resource, 1024); // skip first line
		$Columns = fgetcsv($Resource, 1024);
		Console::Message('Get location IDs');
		$Count = 1;
		$Locations = array();
		
		while (True) {
			if ((++$Count % 100000) == 0) Console::Message('%dK...', (int)($Count/1000));
			$CsvFields = fgetcsv($Resource, 1024);
			if ($CsvFields === False) break;
			$CountryCode = $CsvFields[1];
			if (!in_array($CountryCode, $OnlyCountry)) continue;
			// locId,country,region,city,postalCode,latitude,longitude,metroCode,areaCode
			$Region = $CsvFields[2];
			$LocationID = $CsvFields[0];
			// translate to local language
			//$CityName = self::GetLocalCityName($City);
			$CityName = $CsvFields[3];
			$Fields = compact('CityName', 'CountryCode', 'Region');
			$Locations[$LocationID] = $Fields;
		}
		fclose($Resource);

		$CSVFile = dirname(__FILE__).DS.$Directory.DS.'GeoLiteCity-Blocks.csv';
		$Resource = fopen($CSVFile, 'r+');
		fgetcsv($Resource, 1024); // skip first line
		$Columns = fgetcsv($Resource, 1024);
		Console::Message('Replace ip range for locations');
		$SQL = $Database->SQL();
		$SQL->WhereIn('CountryCode', $OnlyCountry)->Delete('IpCityLocation');
		$Count = 1;
		while (True) {
			if ((++$Count % 100000) == 0) Console::Message('%dK...', (int)($Count/1000));
			// startIpNum,endIpNum,locId
			$CsvFields = fgetcsv($Resource);
			if ($CsvFields === False) break;
			if (!array_key_exists($CsvFields[2], $Locations)) continue;
			$Fields = GetValue($CsvFields[2], $Locations);
			$Fields = array_merge(array_filter($Fields), array('IP1' => $CsvFields[0], 'IP2' => $CsvFields[1]));
			self::StaticSave($Fields);
		}
		fclose($Resource);
		Console::Message('Cleanup...');
		@RecursiveRemoveDirectory(dirname(__FILE__).DS.$Directory);
		$this->RemoveGarbage();
	}
	
	protected static function StaticSave($Fields) {
		$SQL = Gdn::SQL();
		$Prefix = $SQL->Database->DatabasePrefix;
		$IP2 = $Fields['IP2'];
		$IP1 = $Fields['IP1'];
		$Fields['IpDifference'] = $IP2 - $IP1;
		
		$Columns = $Values = $InputParameters = array();
		foreach($Fields as $Field => $Value) {
			$Columns[] = $Field;
			$Values[] = ":$Field";
			$InputParameters[":$Field"] = $Value;
		}
		$Columns[] = 'PolygonIpRange';
		$Values[] = "geomfromwkb(polygon(linestring(point($IP1, -1), point($IP2, -1), point($IP2, 1), point($IP1, 1), point($IP1, -1))))";
		$Columns = implode(', ', $Columns);
		$Values = implode(', ', $Values);
		$Sql = "insert {$Prefix}IpCityLocation ($Columns) values($Values)";

		try {
			$SQL->Database->Query($Sql, $InputParameters);
		} catch (Exception $Ex) {
			Console::Message($Ex->GetMessage());
		}
	}
	
	public function GetDataFromIpGeoBase() {
		Console::Message('Getting database from ipgeobase');
		$File = 'http://ipgeobase.ru/files/db/Main/db_files.zip';
		self::SaveFile($File);
		$GeoFile = file(dirname(__FILE__).DS.'cidr_ru_block.txt');
		Gdn::SQL()->Delete('IpCityLocation', array('CountryCode' => 'RU'));
		$Columns = array_keys(Gdn::SQL()->FetchTableSchema('IpCityLocation'));
		$TxtColumns = array('IP1', 'IP2', 'IpRange', 'CountryCode', 'CityName', 'Region', 'District');
		
		for ($Count = count($GeoFile), $i = 0; $i < $Count; $i++) {
			if (($i % 10000) == 0) Console::Message('%dK and inserting...', (int)($i/10000));
			$GeoFile[$i] = mb_convert_encoding($GeoFile[$i], 'utf-8', 'windows-1251');
			$RowArray = array_slice(explode("\t", $GeoFile[$i]), 0, 7);
			$Fields = array_combine($TxtColumns, $RowArray);
			self::StaticSave($Fields);
		}
		Console::Message('Removing temp files...');
		$this->RemoveGarbage();
	}
	
	private function RemoveGarbage() {
		$Garbage = SafeGlob(dirname(__FILE__).DS.'*.*', array('zip', 'db', 'txt'));
		foreach($Garbage as $F) unlink($F);
	}

	// plugin/reenableipcitylocation
	public function PluginController_ReEnableIpCityLocation_Create($Sender) {
		$Sender->Permission('Garden.Admin.Only');
		$Session = Gdn::Session();
		$TransientKey = $Session->TransientKey();
		RemoveFromConfig('EnabledPlugins.IpCityLocation');
		Redirect('settings/plugins/all/IpCityLocation/'.$TransientKey);
	}
	
	public function Structure() {
		
		$Construct = Gdn::Structure();
		$SQL = Gdn::SQL();
		$Prefix = $SQL->Database->DatabasePrefix;
		
		$Construct
			->Table('IpCityLocation');
		
		$Construct
			->Table('IpCityLocation')
			->Column('IP1', 'uint', False, 'primary')
			->Column('IP2', 'uint', False, 'primary')
			->Column('IpRange', 'char(35)')
			->Column('IpDifference', 'int', 0)
			->Column('CountryCode', 'char(2)')
			->Column('CityName', 'char(50)') // local city name
			->Column('Region', 'char(70)')
			->Column('District', 'char(35)')
			->Set();
		
		try {
			$Construct->Query("alter table {$Prefix}IpCityLocation add column `PolygonIpRange` polygon not null after `IP2`");
		} catch (Exception $Ex) {}
		
		try {
			$Construct->Query("alter table {$Prefix}IpCityLocation add spatial index `PolygonIpRange` (`PolygonIpRange`)");
		} catch (Exception $Ex) {}
			
		// http://jcole.us/blog/archives/2007/11/24/on-efficiently-geo-referencing-ips-with-maxmind-geoip-and-mysql-gis/
		$Construct->Query("update {$Prefix}IpCityLocation set PolygonIpRange = geomfromwkb(polygon(linestring(
			-- clockwise, 4 points and back to 0
				point(IP1, -1), --  0, top left 
				point(IP2,   -1), -- 1, top right
				point(IP2,    1), -- 2, bottom right 
				point(IP1,  1), -- 3, bottom left 
				point(IP1, -1)  -- 0, back to start
			)))");
		SaveToConfig('Plugins.IpCityLocation.PolygonField', True);
	}
	
	public function Setup() {
		if (!function_exists('mb_convert_encoding')) throw new Exception('mbstring extension (Multibyte String Functions) is required.');
		$this->Structure();
	}
	
	// OBSOLETE
/*	private static function GetLocalCityName($City) {
		if (!$City) return;
		$CityName = Null;
		try {
			$CityName = LingvoTranslate($City, array('From' => 'en'));
		} catch (Exception $Ex) {
			Console::Message('Fail. %s', $Ex->GetMessage());
			sleep(10);
		}
		if ($CityName) {
			if (mb_convert_case($CityName, 2, 'utf-8') != $CityName) $CityName = Null;
			Console::Message('LingvoTranslate: %s -> %s', $City, $CityName);
		}
		return $CityName;
	}*/

}

