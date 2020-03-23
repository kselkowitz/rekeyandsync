<?php

define("SERVER", "serverfqdn");
define("SUPERUSER", "username");
define("PASSWORD", "password");
define("CLIENTID", "clientid");
define("CLIENTSECRET", "clientsecret");
define("KEYLENGTH", "10");
define("CONFHOST", "@conference-bridge");

/* First Step is to get a new Access token to given server.*/
$query = array(
		'grant_type'	=> 'password',
		'username'		=> SUPERUSER,
		'password'		=> PASSWORD,
		'client_id'		=> CLIENTID,
		'client_secret'		=> CLIENTSECRET,
);

$postFields = http_build_query($query);
$http_response = "";

$curl_result = __doCurl("https://".SERVER."/ns-api/oauth2/token", CURLOPT_POST, NULL, NULL, $postFields, $http_response);

if (!$curl_result){
	header( 'Location: rekey.php?error=server' ) ;
	exit;

}

$token = json_decode($curl_result, /*assoc*/true);

if (!isset($token['access_token'])) {
	header( 'Location: rekey.php?error=server' ) ;
	exit;

}

$token = $token['access_token'];

/* Get domain List */
$query = array(
		'object'	=> 'domain',
		'action'		=> "read",
		'format'    =>  'json',
);
$domainList = __doCurl("https://".SERVER."/ns-api/", CURLOPT_POST, "Authorization: Bearer " . $token, $query, null, $http_response);
$domainList = json_decode($domainList,true);

// print domain selection menu
echo "<form><select name=\"domain\">";
foreach ($domainList as $array) {
  echo "<option value=\"$array[domain]\">". $array[domain] . "</option>";
}
echo "</select><input type=\"submit\" value=\"Submit\"></form>";


// error on oauth
if (isset($_REQUEST['error'])){
	if ($_REQUEST['error'] == "server")
		echo 'check the define statements in the file for correct data!';
}

// form was submitted with domain
if (isset($_REQUEST['domain'])){
	$resetDomain = $_REQUEST['domain'];

	echo 'Processing key reset and resync for domain ' . $resetDomain;

	/* get devices for domain */
	$query = array(
		'object' => 'device',
		'action' => 'read',
		'format' => 'json',
		'noNDP' => 'true',
		'domain' => "$resetDomain",
	);

	$deviceList = __doCurl("https://".SERVER."/ns-api/", CURLOPT_POST, "Authorization: Bearer " . $token, $query, null, $http_response);
	$deviceList = json_decode($deviceList,true);


	foreach ($deviceList as $array) {
		//echo $array[aor].'<br>';
		// reset device password and resync

		$aor = $array[aor];
		$newPass = substr(md5(uniqid(rand())),0, KEYLENGTH);

                // check if conf bridge

                if(stristr($aor, CONFHOST) === FALSE) {
		// not a bridge

                // resync device

                    if(stristr($aor, "Yealink") === FALSE) 
                    {

                        $query = array(
                            'object' => 'device',
                            'action' => 'update',
                            'device' => "$aor",
                            'check-sync' => "yes",
                        );
                    }
                    else
                    {
                        $query = array(
                            'object' => 'device',
                            'action' => 'update',
                            'device' => "$aor",
                            'check-sync' => "yes",
                            'evtCheckSync' => "check-sync;reboot=true",
                        );
                    }

                __doCurl("https://".SERVER."/ns-api/", CURLOPT_POST, "Authorization: Bearer " . $token, $query, null, $http_response);

		sleep(2);

		// change password
		$query = array(
                	'object' => 'device',
                	'action' => 'update',
                	'device' => "$aor",
			'authentication_key' => "$newPass",
			'domain' => "$resetDomain",
        	);

	        __doCurl("https://".SERVER."/ns-api/", CURLOPT_POST, "Authorization: Bearer " . $token, $query, null, $http_response);


		echo "<br>reset and resync $aor";
		}
		else
		{
                        echo "<br>skipping bridge $aor";
		}

	}
}




function __doCurl($url, $method, $authorization, $query, $postFields, &$http_response)
{
	$start= microtime(true);
	$curl_options = array(
			CURLOPT_URL => $url . ($query ? '?' . http_build_query($query) : ''),
			CURLOPT_SSL_VERIFYHOST => false,
			CURLOPT_SSL_VERIFYPEER => false,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FORBID_REUSE => true,
			CURLOPT_TIMEOUT => 60
	);

	$headers = array();
	if ($authorization != NULL)
	{
		if ("bus:bus" == $authorization)
			$curl_options[CURLOPT_USERPWD]=$authorization;
		else
			$headers[$authorization]=$authorization;
	}


	$curl_options[$method] = true;
	if ($postFields != NULL )
	{
		$curl_options[CURLOPT_POSTFIELDS] = $postFields;
	}

	if (sizeof($headers)>0)
		$curl_options[CURLOPT_HTTPHEADER] = $headers;

	$curl_handle = curl_init();
	curl_setopt_array($curl_handle, $curl_options);
	$curl_result = curl_exec($curl_handle);
	$http_response = curl_getinfo($curl_handle, CURLINFO_HTTP_CODE);
	//print_r($http_response);
	curl_close($curl_handle);
	$end = microtime(true);
	if (!$curl_result)
		return NULL;
	else if ($http_response >= 400)
		return NULL;
	else
		return $curl_result;
}

?>
