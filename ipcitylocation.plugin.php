<?php if (!defined('APPLICATION')) exit();

/*
TODO: Description. Add column by application

CONFIG:
$Configuration['Plugins']['IpCityLocation']['Country'] = array('BY', 'MN', 'KZ', 'UA');
*/

$PluginInfo['IpCityLocation'] = array(
	'Name' => 'IpCityLocation',
	'Description' => 'IP Geolocation. Joint database of ipgeobase.ru and geolite.maxmind.com (for developers).',
	'Version' => '1.3.2',
	'Date' => '20 Dec 2010',
	'Author' => 'John Smith',
	'RequiredPlugins' => array('PluginUtils' => '>=2.0.30')
);

function GetCityNameByIp($RemoteAddr = False, $ResetCache = False, $Default = False) {
	$Result = IpCityLocationPlugin::Get($RemoteAddr, $ResetCache);
	$CityName = ObjectValue('CityName', $Result, $Default);
	return $CityName;
}

class IpCityLocationPlugin implements Gdn_IPlugin {
	
	// Ñron: every 5-th day of month on 01:30
	public function Tick_Match_30_Minutes_01_Hours_7_Day_Handler($Sender) {
		ini_set('memory_limit', '512M');
		$this->GetDataFromIpGeoBase();
		$this->GetDataFromGeoliteMaxmind();
		$Prefix = Gdn::SQL()->Database->DatabasePrefix;
		Gdn::SQL()->Query("optimize table {$Prefix}IpCityLocation");
		$Sender->FireEvent('IpCityLocationUpdate');
		SaveToConfig('Plugins.IpCityLocation.UpdateDate', Gdn_Format::ToDateTime());
	}
	
	public static function Get($RemoteAddr = False, $ResetCache = False) {
		static $Cache;
		if (!$RemoteAddr) $RemoteAddr = RemoteIP();
		if (!is_numeric($RemoteAddr)) $RemoteAddr = ip2long($RemoteAddr);
		if ($RemoteAddr == -1) return False;
		if ($RemoteAddr < 0) $RemoteAddr = sprintf('%u', $RemoteAddr);
		if (!isset($Cache[$RemoteAddr]) || $ResetCache) {
			$Cache[$RemoteAddr] = Gdn::SQL()
				->Select('*')
				->Select('IP2 - IP1', '', 'DifferenceA')
				->From('IpCityLocation')
				->Where('IP2 >=', $RemoteAddr, False, False)
				->Where('IP1 <=', $RemoteAddr, False, False)
				->OrderBy('DifferenceA', 'asc')
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
			//$City = $CsvFields[3];
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
			$CsvFields = fgetcsv($Resource);
			if ($CsvFields === False) break;
			// startIpNum,endIpNum,locId
			$Fields = GetValue($CsvFields[2], $Locations);
			if ($Fields === False) continue;
			$Where = array('IP1' => $CsvFields[0], 'IP2' => $CsvFields[1]);
			if ($SQL->GetCount('IpCityLocation', $Where) > 0) continue; // dont replace
			$Fields = array_merge(array_filter($Fields), $Where);
			$SQL->Insert('IpCityLocation', $Fields);
		}
		fclose($Resource);
		Console::Message('Cleanup...');
		@RecursiveRemoveDirectory(dirname(__FILE__).DS.$Directory);
		$this->RemoveGarbage();
	}
	
	public function GetDataFromIpGeoBase() {
		Console::Message('Getting database from ipgeobase');
		$File = 'http://ipgeobase.ru/files/db/Main/db_files.zip';
		self::SaveFile($File);
		$GeoFile = file(dirname(__FILE__).DS.'cidr_ru_block.txt');
		$SQL = Gdn::SQL();
		$SQL->Delete('IpCityLocation', array('CountryCode' => 'RU'));
		$Columns = array_keys($SQL->FetchTableSchema('IpCityLocation'));
		for ($Count = count($GeoFile), $i = 0; $i < $Count; $i++) {
			if (($i % 10000) == 0) Console::Message('%dK and inserting...', (int)($i/10000));
			$GeoFile[$i] = mb_convert_encoding($GeoFile[$i], 'utf-8', 'windows-1251');
			$RowArray = explode("\t", $GeoFile[$i]);
			$Fields = array_combine(array('IP1', 'IP2', 'IpRange', 'CountryCode'), array_slice($RowArray, 0, 4));
			$Where = array('IP1' => $Fields['IP1'], 'IP2' => $Fields['IP2']);
			$Fields['CityName'] = $RowArray[4];
			$Fields['Region'] = $RowArray[5];
			$Fields['District'] = $RowArray[6];
			// TODO: Insert / Update in try
			$SQL->Replace('IpCityLocation', $Fields, $Where);
		}
		Console::Message('Removing temp files...');
		$this->RemoveGarbage();
	}
	
	private function RemoveGarbage() {
		$Garbage = SafeGlob(dirname(__FILE__).DS.'*.*', array('zip', 'db', 'txt'));
		foreach($Garbage as $F) unlink($F);
	}
	
	public function Structure() {
		Gdn::Structure()
			->Table('IpCityLocation')
			->Column('IP1', 'uint', False, 'primary')
			->Column('IP2', 'uint', False, 'primary')
			->Column('IpRange', 'char(35)')
			->Column('CountryCode', 'char(2)')
			->Column('CityName', 'char(50)') // local city name
			->Column('Region', 'char(70)')
			->Column('District', 'char(35)')
			->Set();
	}
	
	public function Setup() {
		if (!function_exists('mb_convert_encoding')) throw new Exception('mbstring extension (Multibyte String Functions) is required.');
		$this->Structure();
	}
	
	// OBSOLETE
	private static function GetLocalCityName($City) {
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
	}

}

