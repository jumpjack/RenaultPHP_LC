<?php
session_cache_limiter('nocache');
require 'config.php';

//Parameter auswerten
if (isset($_GET['cron']) || $argv[1] == 'cron') {
  header('Content-Type: text/plain; charset=utf-8');
  $cmd_cron = TRUE;
} else {
  header('Content-Type: text/html; charset=utf-8');
  $cmd_cron = FALSE;
}
if (isset($_GET['acnow']) || $argv[1] == 'acnow') $cmd_acnow = TRUE;
else $cmd_acnow = FALSE;
if (isset($_GET['chargenow']) || $argv[1] == 'chargenow') $cmd_chargenow = TRUE;
else $cmd_chargenow = FALSE;

$date_today = date_create('now');
$date_today = date_format($date_today, 'md');
$timestamp_now = date_create('now');
$timestamp_now = date_format($timestamp_now, 'YmdHi');

/**Zwischengespeicherte Daten abrufen
 * Session-Array:
 * 0: Datum Abruf Gigya JWT Token (md)
 * 1: Gigya JWT Token
 * 2: Renault Account-ID
 * 3: MD5-Hash des letzten Datenabrufs
 * 4: Zeitstempel des letzten Datenabrufs (YmdHi)
 * 5: Mail versendet (Y/N)
 * 6: Aktiver Ladevorgang (Y/N)
 * 7: Kilometerstand
 * 8: Status-Datum
 * 9: Status-Zeit
 * 10: Ladestatus
 * 11: Kabelstatus
 * 12: Akkustand
 * 13: Akkutemperatur (Ph1) / Akkukapazität (Ph2)
 * 14: Reichweite
 * 15: Ladezeit
 * 16: Ladeeffekt
 * 17: Aussentemperatur (Ph1) / GPS-Latitude (Ph2)
 * 18: GPS-Longitude (Ph2)
 * 19: GPS-Datum (Ph2, d.m.Y)
 * 20: GPS-Zeit (Ph2, H:i)
 * 21: Einstellung Akkustand für Mailversand
 * 22: Aussentemperatur für Ph2 (openweathermap API)
 * 23: Wetter für Ph2 (openweathermap API)
 */
$session = file_get_contents(__DIR__.'/session');
if ($session !== FALSE) $session = explode('|', $session);
else $session = array('0000', '', '', '', '202001010000', 'N', 'N', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '80','','');

//Einstellung Akkustand für Mailversand auslesen
if (is_numeric($_POST['bl']) && $_POST['bl'] >= 1 && $_POST['bl'] <= 99) {
  if ($_POST['bl'] > $session[21]) $session[5] = 'N';
  $session[21] = $_POST['bl'];
}

//Cron-Zeitintervall prüfen
if ($cmd_cron == TRUE) {
  $s = date_create_from_format('YmdHi', $session[4]);
  if ($session[6] == 'Y') date_add($s, date_interval_create_from_date_string($cron_acs.' minutes'));
  else date_add($s, date_interval_create_from_date_string($cron_ncs.' minutes'));
  $s = date_format($s, 'YmdHi');
  if ($timestamp_now < $s) exit('INTERVAL NOT REACHED');
}

//Neues Token anfordern, wenn Datumänderung seit letztem Zugriff
if (empty($session[1]) || $session[0] !== $date_today) {
  //Login Gigya
  $postData = array(
    'ApiKey' => $gigya_api,
    'loginId' => $username,
    'password' => $password,
    'include' => 'data',
	'sessionExpiration' => 60
  );
  $ch = curl_init('https://accounts.eu1.gigya.com/accounts.login');
  curl_setopt($ch, CURLOPT_POST, TRUE);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
  curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
  $response = curl_exec($ch);
  if ($response === FALSE) die(curl_error($ch));  
  $responseData = json_decode($response, TRUE);
  $personId = $responseData['data']['personId'];
  $oauth_token = $responseData['sessionInfo']['cookieValue'];

  //Abfrage Gigya JWT Token
  $postData = array(
    'login_token' => $oauth_token,
    'ApiKey' => $gigya_api,
    'fields' => 'data.personId,data.gigyaDataCenter',
	'expiration' => 87000
  );
  $ch = curl_init('https://accounts.eu1.gigya.com/accounts.getJWT');
  curl_setopt($ch, CURLOPT_POST, TRUE);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
  curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
  $response = curl_exec($ch);
  if ($response === FALSE) die(curl_error($ch));
  $responseData = json_decode($response, TRUE);
  $session[1] = $responseData['id_token'];
  
  $session[0] = $date_today;
}

//Account ID abrufen, falls nicht zwischengespeichert
if (empty($session[2])) {
  //Abfrage Kamereon Account ID
  $postData = array(
    'apikey: '.$kamereon_api,
    'x-gigya-id_token: '.$session[1],
  );
  $ch = curl_init('https://api-wired-prod-1-euw1.wrd-aws.com/commerce/v1/persons/'.$personId.'?country='.$country);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
  curl_setopt($ch, CURLOPT_HTTPHEADER, $postData);
  $response = curl_exec($ch);
  if ($response === FALSE) die(curl_error($ch));
  $responseData = json_decode($response, TRUE);
  $session[2] = $responseData['accounts'][0]['accountId'];
}

//Klimaanlage starten bei Parameter "acnow"
if ($cmd_acnow === TRUE) {
  $postData = array(
    'Content-type: application/vnd.api+json',
    'apikey: '.$kamereon_api,
    'x-gigya-id_token: '.$session[1]
  );
  $jsonData = '{"data":{"type":"HvacStart","attributes":{"action":"start","targetTemperature":"21"}}}';
  $ch = curl_init('https://api-wired-prod-1-euw1.wrd-aws.com/commerce/v1/accounts/'.$session[2].'/kamereon/kca/car-adapter/v1/cars/'.$vin.'/actions/hvac-start?country='.$country);
  curl_setopt($ch, CURLOPT_POST, TRUE);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
  curl_setopt($ch, CURLOPT_HTTPHEADER, $postData);
  curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
  $response = curl_exec($ch);
  if ($response === FALSE) die(curl_error($ch));
}

//Sofortiges Laden starten bei Parameter "chargenow"
if ($cmd_chargenow === TRUE) {
  $postData = array(
    'Content-type: application/vnd.api+json',
    'apikey: '.$kamereon_api,
    'x-gigya-id_token: '.$session[1]
  );
  $jsonData = '{"data":{"type":"ChargingStart","attributes":{"action":"start"}}}';
  $ch = curl_init('https://api-wired-prod-1-euw1.wrd-aws.com/commerce/v1/accounts/'.$session[2].'/kamereon/kca/car-adapter/v1/cars/'.$vin.'/actions/charging-start?country='.$country);
  curl_setopt($ch, CURLOPT_POST, TRUE);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
  curl_setopt($ch, CURLOPT_HTTPHEADER, $postData);
  curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
  $response = curl_exec($ch);
  if ($response === FALSE) die(curl_error($ch));
}

//Abfrage Akku-und Ladestatus von Renault
$postData = array(
  'apikey: '.$kamereon_api,
  'x-gigya-id_token: '.$session[1]
);
$ch = curl_init('https://api-wired-prod-1-euw1.wrd-aws.com/commerce/v1/accounts/'.$session[2].'/kamereon/kca/car-adapter/v2/cars/'.$vin.'/battery-status?country='.$country);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
curl_setopt($ch, CURLOPT_HTTPHEADER, $postData);
$response = curl_exec($ch);
if ($response === FALSE) die(curl_error($ch));
$md5 = md5($response);
$responseData = json_decode($response, TRUE);
$s = date_create_from_format(DATE_ISO8601, $responseData['data']['attributes']['timestamp'], timezone_open('UTC'));
if (empty($s)) $update_sucess = FALSE;
else {
  $update_sucess = TRUE;
  $weather_api_dt = date_format($s, 'U');
  $s = date_timezone_set($s, timezone_open('Europe/Berlin'));
  $session[8] = date_format($s, 'd.m.Y');
  $session[9] = date_format($s, 'H:i');
  $session[10] = $responseData['data']['attributes']['chargingStatus'];
  $session[11] = $responseData['data']['attributes']['plugStatus'];
  $session[12] = $responseData['data']['attributes']['batteryLevel'];
  if (($zoeph == 1)) $session[13] = $responseData['data']['attributes']['batteryTemperature'];
  else $session[13] = $responseData['data']['attributes']['batteryAvailableEnergy'];
  $session[14] = $responseData['data']['attributes']['batteryAutonomy'];
  $session[15] = $responseData['data']['attributes']['chargingRemainingTime'];
  $s = $responseData['data']['attributes']['chargingInstantaneousPower'];
  if ($zoeph == 1) $session[16] = $s/1000;
  else $session[16] = $s;
}

//Abfrage weiterer Daten von Renault, wenn eine Änderung seit des letzten Abrufs zu erwarten ist
if ($md5 != $session[3] && $update_sucess === TRUE) {
  //Abfrage Kilometerstand
  $postData = array(
    'apikey: '.$kamereon_api,
    'x-gigya-id_token: '.$session[1]
  );
  $ch = curl_init('https://api-wired-prod-1-euw1.wrd-aws.com/commerce/v1/accounts/'.$session[2].'/kamereon/kca/car-adapter/v1/cars/'.$vin.'/cockpit?country='.$country);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
  curl_setopt($ch, CURLOPT_HTTPHEADER, $postData);
  $response = curl_exec($ch);
  if ($response === FALSE) die(curl_error($ch));
  $responseData = json_decode($response, TRUE);
  $s = $responseData['data']['attributes']['totalMileage'];
  if (empty($s)) $update_sucess = FALSE;
  else $session[7] = $s;
  
  //Abfrage Aussentemperatur (nur Ph1)
  if ($zoeph == 1) {
    $postData = array(
      'apikey: '.$kamereon_api,
      'x-gigya-id_token: '.$session[1]
    );
    $ch = curl_init('https://api-wired-prod-1-euw1.wrd-aws.com/commerce/v1/accounts/'.$session[2].'/kamereon/kca/car-adapter/v1/cars/'.$vin.'/hvac-status?country='.$country);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $postData);
    $response = curl_exec($ch);
    if ($response === FALSE) die(curl_error($ch));
    $responseData = json_decode($response, TRUE);
    $s = $responseData['data']['attributes']['externalTemperature'];
	if (empty($s)) $update_sucess = FALSE;
	else $session[17] = $s;
  }

  //Abfrage Position (nur Ph2)
  if ($zoeph == 2) {
    $postData = array(
      'apikey: '.$kamereon_api,
      'x-gigya-id_token: '.$session[1]
    );
    $ch = curl_init('https://api-wired-prod-1-euw1.wrd-aws.com/commerce/v1/accounts/'.$session[2].'/kamereon/kca/car-adapter/v1/cars/'.$vin.'/location?country='.$country);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $postData);
    $response = curl_exec($ch);
    if ($response === FALSE) die(curl_error($ch));
    $responseData = json_decode($response, TRUE);
    $s = date_create_from_format(DATE_ISO8601, $responseData['data']['attributes']['lastUpdateTime'], timezone_open('UTC'));
	if (empty($s)) $update_sucess = FALSE;
	else {
      $s = date_timezone_set($s, timezone_open('Europe/Berlin'));
	  $session[17] = $responseData['data']['attributes']['gpsLatitude'];
	  $session[18] = $responseData['data']['attributes']['gpsLongitude'];
      $session[19] = date_format($s, 'd.m.Y');
	  $session[20] = date_format($s, 'H:i');
	}
  }
  
  //Abfrage Wetterdaten openweathermap API (nur Ph2)
  if ($zoeph == 2 && $weather_api_key != '') {
	$ch = curl_init('https://api.openweathermap.org/data/2.5/onecall/timemachine?lat='.$session[17].'&lon='.$session[18].'&dt='.$weather_api_dt.'&units=metric&lang=de&appid='.$weather_api_key);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
	curl_setopt($ch, CURLOPT_HTTPHEADER, $postData);
	$response = curl_exec($ch);
	if ($response === FALSE) die(curl_error($ch));
	$responseData = json_decode($response, TRUE);	
	$session[22] = $responseData['current']['temp'];
	$session[23] = $responseData['current']['weather']['0']['description'];
  }

  //Mailversand, falls das konfiguriert ist
  if ($mail_bl === 'Y') {
    if ($session[12] >= $session[21] && $session[10] == 1 && $session[5] != 'Y') {
	  if ($session[15] != '') $s = $session[15];
	  else $s = 'wenige';
	  mail($username, $zoename, 'Vorgegebener Akkustand erreicht.'."\n".'Akkustand: '.$session[12].' %'."\n".'Verbleibende Ladezeit: '.$s.' Minuten'."\n".'Reichweite: '.$session[14].' km'."\n".'Statusupdate: '.$session[8].' '.$session[9]);
	  $session[5] = 'Y';
    } else if ($session[5] == 'Y' && $session[10] != 1) $session[5] = 'N';
  }
  if ($mail_csf === 'Y') {
    if ($session[6] == 'Y' && $session[10] != 1) mail($username, $zoename, 'Ladevorgang beendet.'."\n".'Akkustand: '.$session[12].' %'."\n".'Reichweite: '.$session[14].' km'."\n".'Statusupdate: '.$session[8].' '.$session[9]);
    if ($session[10] == 1) $session[6] = 'Y';
    else $session[6] = 'N';
  }

  //Daten in Datenbank schreiben, falls das konfiguriert ist
  if ($update_sucess === TRUE && $save_in_db === 'Y') {
    if (!file_exists(__DIR__.'/database.csv')) {
	  if ($zoeph == 1) file_put_contents(__DIR__.'/database.csv', 'Datum;Zeit;Kilometerstand;Aussentemperatur;Akkutemperatur;Akkustand;Reichweite;Kabelstatus;Ladestatus;Ladeeffekt;Ladezeit'."\n");
      else file_put_contents(__DIR__.'/database.csv', 'Datum;Zeit;Kilometerstand;Akkustand;Akkukapazitaet;Reichweite;Kabelstatus;Ladestatus;Ladeeffekt;Ladezeit;Latitude;Longitude;PositionDatum;PositionZeit;Aussentemperatur;Wetter'."\n");
    }
    if ($zoeph == 1) file_put_contents(__DIR__.'/database.csv', $session[8].';'.$session[9].';'.$session[7].';'.$session[17].';'.$session[13].';'.$session[12].';'.$session[14].';'.$session[11].';'.$session[10].';'.$session[16].';'.$session[15]."\n", FILE_APPEND);
	else file_put_contents(__DIR__.'/database.csv', $session[8].';'.$session[9].';'.$session[7].';'.$session[12].';'.$session[13].';'.$session[14].';'.$session[11].';'.$session[10].';'.$session[16].';'.$session[15].';'.$session[17].';'.$session[18].';'.$session[19].';'.$session[20].';'.$session[22].';'.$session[23]."\n", FILE_APPEND);
  }
}
curl_close($ch);

//Ausgabe
if ($cmd_cron === TRUE) {
  if ($cmd_acnow === TRUE) echo 'AC NOW'."\n";
  if ($cmd_chargenow === TRUE) echo 'CHARGE NOW'."\n";
  if ($update_sucess === TRUE) echo 'OK';
  else echo 'NO DATA';
} else {
  $requesturi = strtok($_SERVER['REQUEST_URI'], '?');
  echo '<HTML>'."\n".'<HEAD>'."\n".'<LINK REL="stylesheet" HREF="stylesheet.css">'."\n".'<META NAME="viewport" CONTENT="width=device-width, initial-scale=1.0">'."\n".'<TITLE>'.$zoename.'</TITLE>'."\n".'</HEAD>'."\n".'<BODY>'."\n".'<DIV ID="container">'."\n".'<MAIN>'."\n";
  if ($mail_bl === 'Y') echo '<FORM ACTION="'.$requesturi.'" METHOD="post" AUTOCOMPLETE="off">'."\n";
  echo '<ARTICLE>'."\n".'<TABLE>'."\n".'<TR ALIGN="left"><TH>'.$zoename.'</TH><TD><SMALL><A HREF="'.$requesturi.'">Aktualisieren</A></SMALL></TD></TR>'."\n";
  if ($cmd_acnow === TRUE) echo '<TR><TD COLSPAN="2">Vorklimatisierung wurde angefordert.</TD><TD>'."\n";
  if ($cmd_chargenow === TRUE) echo '<TR><TD COLSPAN="2">Sofortiges Laden wurde angefordert.</TD><TD>'."\n";
  if ($update_sucess === FALSE) echo '<TR><TD COLSPAN="2">Es konnten keine neuen Daten abgerufen werden.</TD><TD>'."\n";
    echo '<TR><TD>Kilometerstand:</TD><TD>'.$session[7].' km</TD></TR>'."\n".'<TR><TD>Angeschlossen:</TD><TD>';
    if ($session[11] == 0){
      echo 'Nein';
    } else {
      echo 'Ja';
    }
    echo '</TD></TR>'."\n".'<TR><TD>Wird geladen:</TD><TD>';
    if ($session[10] == 1){
	  if ($session[15] != ''){
        $s = date_create_from_format('d.m.YH:i', $session[8].$session[9]);
        date_add($s, date_interval_create_from_date_string($session[15].' minutes'));
        $s = date_format($s, 'H:i');
      } else $s = 'Zeitnah';
      echo 'Ja</TD></TR>'."\n".'<TR><TD>Fertig:</TD><TD>'.$s;
	  if ($zoeph == 1) echo '</TD></TR>'."\n".'<TR><TD>Effekt:</TD><TD>'.$session[16].' kW';
    } else {
      echo 'Nein';
    }
    echo '</TD></TR>'."\n".'<TR><TD>Akkustand:</TD><TD>'.$session[12].' %</TD></TR>'."\n";
	if ($mail_bl === 'Y') echo '<TR><TD>Mail bei Akkustand:</TD><TD><INPUT TYPE="number" NAME="bl" VALUE="'.$session[21].'" MIN="1" MAX="99"><INPUT TYPE="submit" VALUE="%"></TD></TR>'."\n";
    if ($zoeph == 2) {
      echo '<TR><TD>Verf&uuml;gbare Energie:</TD><TD>'.$session[13].' kWh</TD></TR>'."\n";
    }
    echo '<TR><TD>Reichweite:</TD><TD>'.$session[14].' km</TD></TR>'."\n";
    if ($zoeph == 1) {
      echo '<TR><TD>Akkutemperatur:</TD><TD>'.$session[13].' &deg;C</TD></TR>'."\n".'<TR><TD>Au&szlig;entemperatur:</TD><TD>'.$session[17].' &deg;C</TD></TR>'."\n";
    } else {
	  if ($weather_api_key != '') echo '<TR><TD>Au&szlig;entemperatur:</TD><TD>'.$session[22].' &deg;C ('.htmlentities($session[23]).')</TD></TR>'."\n";
	}
    echo '<TR><TD>Statusupdate:</TD><TD>'.$session[8].' '.$session[9].'</TD></TR>'."\n";
    if ($zoeph == 2) {
      echo '<TR><TD>Fahrzeugposition:</TD><TD><A HREF="https://www.google.com/maps/place/'.$session[17].','.$session[18].'" TARGET="_blank">Google Maps</A></TD></TR>'."\n".'<TR><TD>Positionsupdate:</TD><TD>'.$session[19].' '.$session[20].'</TD></TR>'."\n";
    }
  echo '<TR><TD COLSPAN="2"><A HREF="'.$requesturi.'?acnow">Vorklimatisierung starten</A></TD></TR>'."\n".'<TR><TD COLSPAN="2"><A HREF="'.$requesturi.'?chargenow">Laden starten</A></TD></TR>'."\n".'</TABLE>'."\n".'</ARTICLE>'."\n";
  if ($mail_bl === 'Y') echo '</FORM>'."\n";
  echo '</MAIN>'."\n".'</DIV>'."\n".'</BODY>'."\n".'</HTML>';
}

//Daten zwischenspeichern
if (($md5 != $session[3] && $update_sucess === TRUE) || $cmd_cron === TRUE || is_numeric($_POST['bl'])) {
  $session[3] = $md5;
  $session[4] = $timestamp_now;
  $session = implode('|', $session);
  file_put_contents(__DIR__.'/session', $session);
}
?>