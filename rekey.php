<?php

define("SERVER", "server-fqdn");
define("APIKEY", "su access api key");
define("KEYLENGTH", "12");
define("CONFHOST", "@conference-bridge");
define("SKIP_SUFFIXES", "m,t");   // comma-separated suffixes to skip, e.g. "m,t"
define("CHANGE_PROV_PASS", false); // set true to also change device-provisioning-password

// if you set CHANGE_PROV_PASS be sure to have a way for the phone to get the config one of these options:
// whitelisting IPs with skipAuth_whitelist
// or allowing registered IP to bypass auth with SAFE_BypassAuthIfRegIpMatch set yes

$token = APIKEY;

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
		'domain' => "$resetDomain",
	);

	$deviceList = __doCurl("https://".SERVER."/ns-api/", CURLOPT_POST, "Authorization: Bearer " . $token, $query, null, $http_response);
	$deviceList = json_decode($deviceList,true);


	foreach ($deviceList as $array) {
		//echo $array[aor].'<br>';
		// reset device password and resync
                $mac = $array[mac];
                $model = $array[model];
		$aor = $array[aor];
		$newPass = substr(md5(uniqid(rand())),0, KEYLENGTH);

                // check if conf bridge

                if(stristr($aor, CONFHOST) === FALSE) {
		// not a bridge

                // check skip suffixes
                $localPart = strstr($aor, '@', true);
                $skipSuffixes = array_filter(array_map('trim', explode(',', SKIP_SUFFIXES)));
                $shouldSkip = false;
                foreach ($skipSuffixes as $suffix) {
                    if ($suffix !== '' && substr($localPart, -strlen($suffix)) === $suffix) {
                        $shouldSkip = true;
                        break;
                    }
                }

                if ($shouldSkip) {
                    echo "<br>skipping $aor (suffix match)";
                } else {
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

		sleep(1);

		// change password
		$query = array(
                	'object' => 'device',
                	'action' => 'update',
                	'device' => "$aor",
			'authentication_key' => "$newPass",
			'domain' => "$resetDomain",
        	);

	        __doCurl("https://".SERVER."/ns-api/", CURLOPT_POST, "Authorization: Bearer " . $token, $query, null, $http_response);

		// change provisioning password 
		if (CHANGE_PROV_PASS and $mac != "") {
			$provPass = substr(str_shuffle(str_repeat('0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ', 3)), 0, KEYLENGTH);

			$query= array (
                        	'object' => 'mac',
                        	'action' => 'update',
                        	'mac' => "$mac",
				'model' => "$model",
                        	'auth_pass' => "$provPass",
                	);

	                __doCurl("https://".SERVER."/ns-api/", CURLOPT_POST, "Authorization: Bearer " . $token, $query, null, $http_response);

			echo "<br>". $mac. " provisioning password reset";

		}

		echo "<br>reset and resync $aor";
                } // end suffix check
		}
		else
		{
                        echo "<br>skipping bridge $aor";
		}

    		ob_flush();
		flush();

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
