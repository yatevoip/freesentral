#!/usr/bin/php -q
<?php
require_once("libyate.php");
require_once("lib_queries.php");

$s_fallbacks = array();
$s_params_assistant_outgoing = array();
$s_statusaccounts = array();
$s_moh = array();
$stoperror = array("busy", "noanswer", "looping", "Request Terminated","Routing loop detected");
$reg_init = false;
$next_time = 0;
$time_step = 90;

$moh_time_step = 60*5; // 5 minutes
$moh_next_time = 0;

$pickup_key = "000"; //key marking that a certain call is a pickup
$adb_keys = "**";  //keys marking that the address book should be used to find the real called number

$posib = array("no_groups", "no_pbx");

$max_routes = 3;

for($i=0; $i<count($posib); $i++) {
	if(isset(${$posib[$i]}))
		if(${$posib[$i]} !== true)
			${$posib[$i]} = false;
	
	if(!isset(${$posib[$i]}))
		${$posib[$i]} = false;
}

function debug($mess)
{
	Yate::Debug("FreeSentral : register.php : ".$mess);
}

function format_array($arr)
{
	$str =  str_replace("\n", "", print_r($arr, true));
	$str = str_replace("\t","",$str);
	while(strlen($str) != strlen(str_replace("  "," ",$str)))
		$str = str_replace("  "," ",$str);
	return $str;
}

function set_caller_id()
{
	global $caller_id, $timer_caller_id, $caller_name, $system_prefix;

	$query = "SELECT MAX(CASE WHEN param='callerid' THEN value ELSE NULL END) as callerid, MAX(CASE WHEN param='callername' THEN value ELSE NULL END) as callername, MAX(CASE WHEN param='prefix' THEN value ELSE NULL END) as system_prefix FROM settings";
	$res = query_to_array($query);
	if(count($res)) {
		$caller_id = $res[0]["callerid"];
		$caller_name = $res[0]["callername"];
		$system_prefix = $res[0]["system_prefix"];
	}
	debug("Reseted CalledID to '$caller_id', Callername to '$caller_name', System prefix to '$system_prefix'");
	$timer_caller_id = 0;
}

/**
 * Set the array of music_on_hold by playlist. This array is updated periodically.
 * @param $time, Time when function was called. It's called with this param after engine.timer, If empty i want to update the list, probably because i didn't have the moh for a certain playlist
 */
function set_moh($time = NULL)
{
	global $moh_time_step, $moh_next_time, $s_moh, $last_time, $uploaded_prompts;

	if(!$time)
		$time = $last_time;
	$moh_next_time = $time + $moh_time_step;
	$query = "SELECT playlists.playlist_id, playlists.in_use, music_on_hold.file as music_on_hold FROM playlists, music_on_hold, playlist_items WHERE playlists.playlist_id=playlist_items.playlist_id AND playlist_items.music_on_hold_id=music_on_hold.music_on_hold_id ORDER BY playlists.playlist_id";
	$playlists = query_to_array($query);
	$l_moh = array();
	for($i=0; $i<count($playlists); $i++)
	{
		$playlist_id = $playlists[$i]["playlist_id"];
		if(!isset($l_moh[$playlist_id]))
			$l_moh[$playlist_id] = '';
		$moh = "$uploaded_prompts/moh/".$playlists[$i]["music_on_hold"];
		$l_moh[$playlist_id] .=  ($l_moh[$playlist_id] != '') ? ' '.$moh : $moh;
	}
	$s_moh = $l_moh;
}

/**
 * Build the location to send a call depending on the protocol
 * @param $params array of type "field_name"=>"field_value" 
 * @param $called Number where the call will be sent to
 * @return String representing the resource the call will be made to
 */ 
function build_location($params, $called, &$copy_ev)
{
	if($params["username"] && $params["username"] != '') {
		// this is a gateway with registration
		$copy_ev["line"] = $params["gateway"];
		return "line/$called";
	}else{
		switch($params["protocol"]) {
			case "sip":
				return "sip/sip:$called@".$params["server"].":".$params["port"];
			case "h323":
				return "h323/$called@".$params["server"].":".$params["port"];
			case "pstn":
				$params["link"] = $params["gateway"];
				$copy_ev["link"] = $params["link"];
				return "sig/".$called;
			case "PRI":
			case "BRI":
				$query = "SELECT sig_trunk FROM sig_trunks WHERE sig_trunk_id=".$params["sig_trunk_id"];
				$res = query_to_array($query);
				if(!count($res))
					debug("Can't find sig_trunk for gateway ".$params["gateway"]);
				$params["link"] = (count($res)) ? $res[0]["sig_trunk"] : $params["gateway"];
				$copy_ev["link"] = $params["link"];
				return "sig/".$called;
			case "iax":
				if(!$params["iaxuser"])
					$params["iaxuser"] = "";
				$location = "iax/".$params["iaxuser"]."@".$params["server"].":".$params["port"]."/".$called;
				if($params["iaxcontext"])
					$location .= "@".$params["iaxcontext"];
				return $location;
		}
	}
	return NULL;
}

/**
 * Get the location where to send a call
 * @param $called Number the call was placed to
 * @return String representing the resource where to place the call
 * Note!! this function is used only when diverting calls. does not check for any kind of forward, and mimics the fallback when diverting using fork to send the call to each destination
 */ 
function get_location($called)
{
	global $voicemail, $system_prefix;

	if($called == "vm") {
		if($voicemail)
			return $voicemail;
		else
			return NULL;
	}

	//  divert to a did
	$query = "SELECT destination FROM dids WHERE number='$called' OR '$system_prefix' || number='$called'";
	$res = query_to_array($query);
	if(count($res)) {
		if(is_numeric($res[0]["destination"]))
			// just translate the called number
			$called = $res[0]["destination"];
		else
			// route to a script
			return $res[0]["destination"];
	}

	// divert to an extension without thinking of it's divert functions
	$query = "SELECT location FROM extensions WHERE extension='$called' OR '$system_prefix' || extension='$called'";
	$res = query_to_array($query);
	if(count($res)) 
		return $res[0]["location"];

	// if we got here there divert is to a group or dial plans must be used
	// it's better to use the lateroute module
	return "lateroute/$called";

/*	$query = "SELECT * FROM dial_plans INNER JOIN gateways ON dial_plans.gateway_id=gateways.gateway_id WHERE prefix IS NULL OR '$called' LIKE prefix||'%' AND (gateways.username IS NULL OR gateways.status='online') ORDER BY length(coalesce(prefix,'')) DESC, priority";
	
	$res = query_to_array($query);
	if(!count($res)) {
		return NULL; 
	}

	$callto = 'fork ';

	for($i=0; $i<count($res); $i++) {
		$newcalled = rewrite_digits($res[$i],$called);
		$location = build_location($res[$i],$newcalled);
		if (!$location)
			continue;
		if ($res[$i]["formats"])
			$location .= ';formats='.$res[$i]["formats"];
		if ($callto == 'fork ')
			$callto .= $location;
		else
			$callto .= ' | '.$location;
	}

	if($callto != 'fork ')
		return $callto;
	
	return NULL;*/
}

/**
 * Get the modified number 
 * @param $route Array of params representing the modificatios resulted usually from an sql query
 * @param $nr Number before rewriting
 * @return Number resulted after the modifications were applied. If resulted number is empty then original number is returned
 * Note!! The order in with the operations are performed is : cut, replace, add. So replacing will be performed on the resulted number after cutting. One must keep this in mind when using multiple transformations.
 */
function rewrite_digits($route, $nr)
{
	$result = $nr;
	if ($route["nr_of_digits_to_cut"] && $route["position_to_start_cutting"])
	{
		$result = substr($nr,0,$route["position_to_start_cutting"] -1 ) . substr($nr, $route["position_to_start_cutting"]-1+$route["nr_of_digits_to_cut"], strlen($nr));
	}
	if ($route["position_to_start_replacing"] && $route["digits_to_replace_with"])
	{
		if (!$route["nr_of_digits_to_replace"])
			return $route["digits_to_replace_with"];
		$result = substr($nr,0,$route["position_to_start_replacing"] -1 ) . $route["digits_to_replace_with"] .substr($nr, $route["position_to_start_replacing"]+$route["nr_of_digits_to_replace"]-1, strlen($nr));
	}
	if ($route["position_to_start_adding"] && $route["digits_to_add"])
	{
		$result = substr($nr,0,$route["position_to_start_adding"]-1) . $route["digits_to_add"] . substr($nr,$route["position_to_start_adding"]-1,strlen($nr));
	}
	if (!$result){
		debug("Wrong: resulted number is empty when nr='$nr' and route=".format_array($route));
		return $nr;
	}
	return $result;
}

/**
 * Route a call to a group. Using this function implies that the queues module is configured.
 * @param $called Number where the call was placed to
 * @return Bool true if call was routed to a group, false otherwise
 */
function routeToGroup($called)
{
	global $uploaded_prompts, $ev, $s_moh, $system_prefix;

//	debug("entered routeToGroup('$called')");
	$path = "$uploaded_prompts/moh/";

	$cnt = 2+strlen($system_prefix);
	if(strlen($called) == 2 || (strlen($called)==$cnt && substr($called,0,strlen($system_prefix))==$system_prefix)) {
		debug("trying routeToGroup('$called')");
		// call to a group
		$query = "SELECT group_id, (CASE WHEN playlist_id IS NULL THEN (SELECT playlist_id FROM playlists WHERE in_use='t') else playlist_id END) as playlist_id FROM groups WHERE extension='$called' OR '$system_prefix' || extension='$called'";
		$res = query_to_array($query);
		if(!count($res))
			return false;
		set_retval("queue/".$res[0]["group_id"]);
		if(!isset($s_moh[$res[0]["playlist_id"]]))
			set_moh();
		$ev->params["mohlist"] = $s_moh[$res[0]["playlist_id"]];
		if ($ev->GetValue("copyparams"))
			$ev->params["copyparams"] .= ",caller,callername,billid";
		else
			$ev->params["copyparams"] = "caller,callername,billid";
		return true;
	}
	return false;
}

/**
 * Detect whether a call is a pickup or not. Route the call to the appropriate resource if so
 * @param $called Number where the call was placed to
 * @param $caller Who innitiated the call
 * @return Bool true if call is a pickup. False otherwise 
 */
function makePickUp($called,$caller)
{
	global $pickup_key, $system_prefix;

	debug("entered makePickUp(called='$called',caller='$caller')");

	$keyforgroup = strlen($pickup_key) + 2;
	if(strlen($called) == $keyforgroup && substr($called,0,strlen($pickup_key)) == $pickup_key) {
		// someone is trying to pickup a call that was made to a group, (make sure caller is in that group)
		$extension = substr($called,strlen($pickup_key),strlen($called));
		$query = "SELECT group_id FROM groups WHERE (extension='$extension' OR '$system_prefix' || extension='$extension') AND group_id IN (SELECT group_id FROM group_members, extensions WHERE group_members.extension_id=extensions.extension_id AND extensions.extension='$caller')";
		$res = query_to_array($query);
		if(!count($res))
			set_retval("tone/congestion");
		else
			set_retval("pickup/".$res[0]["group_id"]);
		return true;
	}

	if(substr($called,0,strlen($pickup_key)) == $pickup_key) {
		// try to improvize a pick up -> pick up the current call of a extension that is in the same group as the caller
		$extension = substr($called,strlen($pickup_key),strlen($called));
		$query = "SELECT chan FROM call_logs, extensions, group_members WHERE direction='outgoing' AND ended IS NOT TRUE AND (extensions.extension=call_logs.called OR '$system_prefix' || extensions.extension=call_logs.called) AND (extensions.extension='$extension' OR '$system_prefix' || extensions.extension='$extension') AND extensions.extension_id=group_members.extension_id AND group_members.group_id IN (SELECT group_id FROM group_members NATURAL JOIN extensions WHERE extensions.extension='$caller')";
		$res = query_to_array($query);
		if(count($res))
			set_retval("pickup/".$res[0]["chan"]);  //make the pickup
		else
			set_retval("tone/congestion");   //no call for this extension
		return true;
	}
	return false;
}

/**
 * Route a call to an extension. Set the params for all the types of divert.
 * @param $called Number the call was placed to
 * @return Bool true if number was routed, false otherwise
 */
function routeToExtension($called)
{
	global $no_pbx, $ev, $voicemail, $system_prefix, $query_on, $debug_on;

//	debug("entered routeToExtension('$called')");
	if (strlen($called) < 3)
		return false;

	$pref_ext = $system_prefix.$called;
	debug("trying routeToExtension(called='$called' or called='$pref_ext')");

	$query = "SELECT location,extension_id FROM extensions WHERE extension='$called' OR '$system_prefix' || extension='$called'";
	$res = query_to_array($query);
	if(!count($res))
		return false;

	$destination = $res[0]["location"];
	$extension_id = $res[0]["extension_id"];
	if(!$no_pbx) {
		// select voicemail location
		$query = "SELECT value FROM settings WHERE param='vm'";
		$res = query_to_array($query);
		if (!$res || !count($res)) {
			debug("Voicemail is not set!!!");
			$voicemail = NULL;
		} else 
			$voicemail = $res[0]["value"];

		// select pbx settings for the called number 
		$query = "SELECT MAX(CASE WHEN param='forward' THEN value END) as div,MAX(CASE WHEN param='forward_busy' THEN value END) as div_busy,MAX(CASE WHEN param='forward_noanswer' THEN value END) as div_noanswer, MAX(CASE WHEN param='noanswer_timeout' THEN value END) as noans_timeout FROM pbx_settings WHERE extension_id='$extension_id'";
		$res = query_to_array($query);
		$div = $res[0]["div"];
		$div_busy = $res[0]["div_busy"];
		$div_noanswer = $res[0]["div_noanswer"];
		$noans_timeout = $res[0]["noans_timeout"];
 
		if ($div==$called || !$div)  {
			// set the additional divert params
			if($div_busy && $div_busy != '')
				$ev->params["divert_busy"] = get_location($div_busy);
			if ($div_noanswer != '' && $div_noanswer) {
				$ev->params["divert_noanswer"] = get_location($div_noanswer);
				$ev->params["maxcall"] = $noans_timeout * 1000;	
			}
		}else{
			// all calls should be diverted to $div
			$destination = get_location($div);
			if($destination && $div != "vm")
				$ev->params["called"] = $div;
		}
	}

	// if no destination found, try sending call to voicemail(it might be set or not)
	if(!$destination)
		$destination = $voicemail;
	$ev->params["query_on"] = ($query_on) ? "yes" : "no";
	$ev->params["debug_on"] = ($debug_on) ? "yes" : "no";
	set_retval($destination, "offline");
	return true;
}

/**
 * Verify whether $called is a defined did
 * @param $called Number that the call was sent to.
 * @return Bool value, true if destination is a script, false  
 */

function routeToDid(&$called)
{
	global $system_prefix;
	global $query_on;
	global $debug_on;
	global $ev;

	debug("entered routeToDid('$called')");
	// default route is a did 
	$query = "SELECT destination FROM dids WHERE number='$called' OR '$system_prefix' || number='$called'";
	$res = query_to_array($query);
	if(count($res)) {
		if(is_numeric($res[0]["destination"]))
			// just translate the called number
			$called = $res[0]["destination"];
		else{
			$ev->params["query_on"] = ($query_on) ? "yes" : "no";
			$ev->params["debug_on"] = ($debug_on) ? "yes" : "no";
			// route to a script
			set_retval($res[0]["destination"]);
			return true;
		}
	}
	return false;
}

/**
 * Generate all the possible names that could match a certain number
 * @param $number The number that was received
 * @return String containing all the names separated by "', '"
 */
function get_possible_options($number)
{
	$posib = array();

	$alph = array(
				2 => array("a", "b", "c"),
				3 => array("d", "e", "f"),
				4 => array("g", "h", "i"),
				5 => array("j", "k", "l"),
				6 => array("m", "n", "o"),
				7 => array("p", "q", "r", "s"),
				8 => array("t", "u", "v"),
				9 => array("w", "x", "y", "z")
			);

	for($i=0; $i<strlen($number); $i++)
	{
		$digit = $number[$i];
		$letters = $alph[$digit];
		if(!count($posib)) {
			$posib = $letters;
			continue;
		}
		$s_posib = $posib;
		for($k=0; $k<count($letters); $k++)
		{
			if($k==0)
				for($j=0; $j<count($posib); $j++)
					$posib[$j] .= $letters[$k];
			else
				for($j=0; $j<count($s_posib); $j++)
					array_push($posib, $s_posib[$j].$letters[$k]);
		}
	}
	$options = implode("', '",$posib);
	return "'$options'";
}

/**
 * See if this call uses the address book. If so then find the real number the call should be sent to and modify $called param
 * @param $called Number the call was placed to
 */
function routeToAddressBook(&$called, $username)
{
	global $adb_keys;

//	debug("entered routeToAddressBook(called='$called', username='$username')");

	if(substr($called,0,strlen($adb_keys)) != $adb_keys)
		return;

	debug("trying routeToAddressBook(called='$called', username='$username')");

	$number = substr($called, strlen($adb_keys), strlen($called));
	$possible_names = get_possible_options($number);
	$query = "SELECT short_names.number, 1 as option_nr FROM short_names, extensions WHERE extensions.extension='$username' AND extensions.extension_id=short_names.extension_id AND short_name IN ($possible_names) UNION SELECT number, 2 as option_nr FROM short_names WHERE extension_id IS NULL AND short_name IN ($possible_names) ORDER BY option_nr";
	$res = query_to_array($query);
	if(count($res)) {
		if(count($res) > 1)
			Yate::Output("!!!!!!! Problem with finding real number from address book. Multiple mathces. Picking first one");
		$called = $res[0]["number"];
	}else
		debug("Called number '$called' seems to be using the address book. No match found. Left routing to continue.");
	return;
}

/**
 * Handle the call.route message.
 */
function return_route($called,$caller,$no_forward=false)
{
	global $ev, $pickup_key, $max_routes, $s_fallbacks, $no_groups, $no_pbx, $caller_id, $caller_name, $system_prefix;

	$rtp_f = $ev->GetValue("rtp_forward");
	// keep the initial called number
	$initial_called_number = $called;

	$username = $ev->GetValue("username");	
	$address = $ev->GetValue("address");
	$address = explode(":", $address);
	$address = $address[0];
	$reason = $ev->GetValue("reason");
	$isdn_address = $ev->GetValue("address");
	$isdn_address = explode("/",$isdn_address);
	$sig_trunk = $isdn_address[0];

	$already_auth = $ev->GetValue("already-auth");
	$trusted_auth = $ev->GetValue("trusted-auth");
	$call_type = $ev->GetValue("call_type");
	debug("entered return_route(called='$called',caller='$caller',username='$username',address='$address',already-auth='$already_auth',reason='$reason', trusted='$trusted_auth', call_type='$call_type')");

	$params_to_copy = "maxcall,call_type,already-auth,trusted-auth";
	// make sure that if we forward any calls and for calls from pbxassist are accepted
	$ev->params["copyparams"] = $params_to_copy;
	$ev->params["pbxparams"] = "$params_to_copy,copyparams";

	if($already_auth != "yes" && $reason!="divert_busy" && $reason != "divert_noanswer") {
		// check to see if user is allowed to make this call
		$query = "SELECT value FROM settings WHERE param='annonymous_calls'";
		$res = query_to_array($query);
		$anonim = $res[0]["value"];
		if(strtolower($anonim) != "yes" || $username)
			// if annonymous calls are not allowed the call has to be from a known extension or from a known ip
			$query = "SELECT extension_id,true as trusted,'from inside' as call_type FROM extensions WHERE extension='$username' UNION SELECT incoming_gateway_id, trusted, 'from outside' as call_type FROM incoming_gateways,gateways WHERE gateways.gateway_id=incoming_gateways.gateway_id AND incoming_gateways.ip='$address' UNION SELECT gateway_id, trusted, 'from outside' as call_type FROM gateways LEFT OUTER JOIN sig_trunks ON gateways.sig_trunk_id=sig_trunks.sig_trunk_id WHERE server='$address' OR server LIKE '$address:%' OR sig_trunk='$sig_trunk'";
		else {
			// if $called is the same as one of our extensions try and autentify it -> in order to have pbx rights
			if (!$username) {
			    $query = "SELECT * FROM extensions WHERE extension='$caller'";
			    $res = query_to_array($query);
			    if (count($res)) {
				    debug("could not auth call but '$caller' seems to be in extensions");
				    set_retval(NULL, "noauth");
				    return;
			    }
			}  

			// if annonymous calls are allowed call to be for a inner group or extension  or from a known ip
			$query = "SELECT incoming_gateway_id, trusted, 'from outside' as call_type FROM incoming_gateways, gateways WHERE incoming_gateways.gateway_id=gateways.gateway_id AND incoming_gateways.ip='$address' UNION SELECT gateway_id, trusted, 'from outside' as call_type FROM gateways LEFT OUTER JOIN sig_trunks ON gateways.sig_trunk_id=sig_trunks.sig_trunk_id WHERE server='$address' OR server LIKE '$address:%' OR sig_trunk='$sig_trunk' UNION SELECT extension_id,true as trusted,'to inside' as call_type FROM extensions WHERE extension='$called' OR '$system_prefix' || extension='$called' OR extension='$username' UNION SELECT group_id, 't' as trusted,'to inside' as call_type  FROM groups WHERE extension='$called' OR '$system_prefix' || extension='$called' UNION SELECT did_id, 't' as trusted,'to inside' as call_type  FROM dids WHERE number='$called' OR '$system_prefix' || number='$called'";
		}
		$res = query_to_array($query);
		if (!count($res)) {
			debug("could not auth call");
			set_retval(NULL, "noauth");
			return;
		}
		$trusted_auth = ($res[0]["trusted"] == "t") ? "yes" : "no";
		$call_type = $res[0]["call_type"];//($username) ? "from inside" : "from outside";  // from inside/outside of freesentral
	}

	debug("classified call as being '$call_type'");
	// mark call as already autentified
	$ev->params["already-auth"] = "yes";
	$ev->params["trusted-auth"] = $trusted_auth;
	$ev->params["call_type"] = $call_type;

	if ($call_type != "from inside")
		$ev->params["pbxguest"] = true;

	routeToAddressBook($called, $username);

	if(routeToDid($called))
		return;

	if(!$no_groups) {
		if(routeToGroup($called))
			return;
		if(makePickUp($called,$caller))
			return;
	}

	if(routeToExtension($called))
		return;

	if($call_type == "from outside" && $initial_called_number == $called && $trusted_auth != "yes") {
		// if this is a call from outside our system and would be routed outside(from first step) and the number that was initially called was not modified with passing thought any of the above steps  => don't send it
		debug("forbidding call to '$initial_called_number' because call is 'from outside'");
		set_retval(null, "forbidden");
		return;
	}

	$query = "SELECT * FROM dial_plans INNER JOIN gateways ON dial_plans.gateway_id=gateways.gateway_id WHERE (prefix IS NULL OR '$called' LIKE prefix||'%') AND (gateways.username IS NULL OR gateways.status='online') ORDER BY length(coalesce(prefix,'')) DESC, priority LIMIT $max_routes";
	$res = query_to_array($query);

	if(!count($res)) {
		debug("Could not find a matching dial plan=> rejecting with error: noroute");
		set_retval(NULL,"noroute");
		return;
	}
	$id = ($ev->GetValue("true_party")) ? $ev->GetValue("true_party") : $ev->GetValue("id");
	$start = count($res) - 1;
	$j = 0;
	$fallback = array();
	for($i=$start; $i>=0; $i--) {
		$fallback[$j] = $ev->params;
		$custom_caller_id = ($res[$i]["callerid"]) ? $res[$i]["callerid"] : $caller_id;
		$custom_caller_name = ($res[$i]["callername"]) ? $res[$i]["callername"] : $caller_name;
		if($res[$i]["send_extension"] == "f") {
			$fallback[$j]["caller"] = $custom_caller_id;
			$fallback[$j]["callername"] = $custom_caller_name;
		}elseif($system_prefix && $call_type == "from inside")
			$fallback[$j]["caller"] = $system_prefix.$fallback[$j]["caller"];
		$fallback[$j]["called"] = rewrite_digits($res[$i],$called);
		$fallback[$j]["formats"] = ($res[$i]["formats"]) ? $res[$i]["formats"] : $ev->GetValue("formats");
		$fallback[$j]["rtp_forward"] = ($rtp_f == "possible" && $res[$i]["rtp_forward"] == 't') ? "yes" : "no";
		$location = build_location($res[$i],rewrite_digits($res[$i],$called),$fallback[$j]);
		if (!$location)
			continue;
		$fallback[$j]["location"] = $location;
		$j++;
	}
	if(!count($fallback)) {
		set_retval(NULL,"noroute");
		return;
	}
	$best_option = count($fallback) - 1;
	set_retval($fallback[$best_option]["location"]);
	debug("Sending $id to ".$fallback[$best_option]["location"]);
	unset($fallback[$best_option]["location"]);
	$ev->params = $fallback[$best_option];
	unset($fallback[$best_option]);
	if(count($fallback))
		$s_fallbacks[$id] = $fallback;
//	debug("There are ".count($s_fallbacks)." in fallback : ".format_array($s_fallbacks));
	debug("There are ".count($s_fallbacks)." in fallback");

	return;
}

/**
 * Set the params needed for routing a call
 * @param $callto Resource were to place the call
 * @param $error If callto param is not set one can set an error. Ex: offline
 * @return Bool true if the event was handled, false otherwise
 */
function set_retval($callto, $error = NULL)
{
	global $ev, $s_params_assistant_outgoing;

	if($callto) {
		$id = $ev->GetValue("id");
		$s_params_assistant_outgoing[$id] = array();
		$s_params_assistant_outgoing[$id]["pbxparams"] = $ev->GetValue("pbxparams");
		$s_params_assistant_outgoing[$id]["copyparams"] = $ev->GetValue("copyparams");
		if ($ev->GetValue("line")) {
			// call is for outside
			$s_params_assistant_outgoing[$id]["pbxguest"] = true; 
			$s_params_assistant_outgoing[$id]["already-auth"] = $ev->GetValue("already-auth");
			$s_params_assistant_outgoing[$id]["call_type"] = "from outside";
		} else {
			// call is for inside -> we can say the call_type for this party will be from inside
			$s_params_assistant_outgoing[$id]["already-auth"] = $ev->GetValue("already-auth");
			$s_params_assistant_outgoing[$id]["call_type"] = "from inside";
		}
		$ev->retval = $callto;
		$ev->handled = true;
		return true;
	}
	if($error) {
		$ev->params["error"] = $error;
	//	$ev->handled = true;
	}
	return false;
}

// Always the first action to do 
Yate::Init();
//Yate::Debug(true);
//Yate::Output(true);

if(Yate::Arg()) {
	Yate::Output("Executing startup time CDR cleanup");
	$query = "UPDATE call_logs SET ended='t' where ended IS NOT TRUE or ended IS NULL;
			  UPDATE extensions SET inuse_count=0";
	query_nores($query);

	// Spawn another, restartable instance 
	$cmd = new Yate("engine.command");
	$cmd->id = "";
	$cmd->SetParam("line","external register.php");
	$cmd->Dispatch();
	sleep(1);
	exit();
}

// Install handler for the wave end notify messages 
Yate::Install("engine.timer");
Yate::Install("user.register");
Yate::Install("user.unregister");
Yate::Install("user.auth");
Yate::Install("call.route");
Yate::Install("call.cdr");

Yate::Install("call.answered",50);
Yate::Install("chan.disconnected",50);
Yate::Install("chan.hangup");

Yate::Install("user.notify");
Yate::Install("engine.status");
Yate::Install("engine.command");
Yate::Install("engine.debug");

// Ask to be restarted if dying unexpectedly 
Yate::SetLocal("restart","true");

$query = "SELECT enabled, protocol, username, description, interval, formats, authname, password, server, domain, outbound , localaddress, modified, gateway as account, gateway_id, status, TRUE AS gw FROM gateways WHERE enabled IS TRUE AND gateway IS NOT NULL AND username IS NOT NULL ORDER BY gateway";
$res = query_to_array($query);

for($i=0; $i<count($res); $i++) {
	$m = new Yate("user.login");
	$m->params = $res[$i];
	$m->Dispatch();
}

set_caller_id();

set_moh();

// The main loop. We pick events and handle them 
for (;;) {
	$ev=Yate::GetEvent();
	// If Yate disconnected us then exit cleanly 
	if ($ev === false)
	    break;
	// No need to handle empty events in this application 
	if ($ev === true)
	    continue;
	// If we reached here we should have a valid object 
	switch ($ev->type) {
	case "incoming":
		switch ($ev->name) {
			case "engine.debug":
				$module = $ev->GetValue("module");
				if($module != "freesentral")
					break;
				$line = $ev->GetValue("line");
				if ($line == "on") {
					$debug_on = true;
					Yate::Output(true);
					Yate::Debug(true);
					Yate::Output("Enabling debug on FreeSentral routing");
				} elseif($line == "off") {
					$debug_on = false;
					Yate::Output("Disabling debug on FreeSentral routing");
					Yate::Output(false);
					Yate::Debug(false);
				} else
					break;
				$ev->handled = true;
				break;
			case "engine.command":
				debug("Got engine.command : line=".$ev->GetValue("line"));
				$line = $ev->GetValue("line");
				if($line == "query on") 
					$query_on = true;
				elseif($line == "query off")
					$query_on = false;
				else
					break;
				$ev->handled = true;
				break;
			case "engine.status":
				$module = $ev->GetValue("module");
				if($module && $module!="register.php" && $module!="misc")
					break;
				$query = "SELECT gateway,(CASE WHEN status IS NULL THEN 'offline' else status END) as status FROM gateways WHERE enabled IS TRUE AND username IS NOT NULL";
				$res = query_to_array($query);
				$str = $ev->retval;
				$str .= 'name=register.php;users='.count($res);
				for($i=0; $i<count($res);$i++) {
					$str .= ($i) ? "," : ";";
					$str .= $res[$i]["gateway"].'='.$res[$i]["status"];
				}
				$str .= "\r\n";
				$ev->retval = $str;
				$ev->handled = false;
				break;
			case "engine.timer":
				$time = $ev->GetValue("time");
				$timer_caller_id++;
				if($timer_caller_id > 600)
					// update caller_id every 10 minutes
					set_caller_id();
				if ($moh_next_time < $time)
					set_moh($time);
				if ($time < $next_time)
					break;
				$next_time = $time + $time_step;
				$query = "SELECT enabled, protocol, username, description, interval, formats, authname, password, server, domain, outbound , localaddress, modified, gateway as account, gateway_id, status, TRUE AS gw FROM gateways WHERE enabled IS TRUE AND modified IS TRUE AND username is NOT NULL";
				$res = query_to_array($query);
				for($i=0; $i<count($res); $i++) {
					$m = new Yate("user.login");
					$m->params = $res[$i];
					$m->Dispatch();
				}
				$query = "UPDATE extensions SET location=NULL,expires=NULL WHERE expires IS NOT NULL AND expires<=CURRENT_TIMESTAMP; UPDATE gateways SET modified='f' WHERE modified='t' AND username IS NOT NULL";
				$res = query_nores($query);
				break;
			case "user.notify":
				$gateway = $ev->GetValue("account") . '(' . $ev->GetValue("protocol") . ')';
				$status = ($ev->GetValue("registered") != 'false') ? "online" : "offline";
				$s_statusaccounts[$gateway] = $status;
				$query = "UPDATE gateways SET status='$status' WHERE gateway='".$ev->GetValue("account")."'";
				$res = query_nores($query);
				break;
			case "user.auth":
				if(!$ev->GetValue("username"))
					break;
				$query = "SELECT password FROM extensions WHERE extension='".$ev->GetValue("username")."'";
				$res = query($query);
				if (pg_num_rows($res)) {
					$ev->retval = pg_fetch_result($res,0,0);
					$ev->handled = true;
				}
				break;
			case "user.register":
				$query = "UPDATE extensions SET location='".$ev->GetValue("data")."',expires=CURRENT_TIMESTAMP + INTERVAL '".$ev->GetValue("expires")." s' WHERE extension='".$ev->GetValue("username")."'";
				$res = query_nores($query);
				$ev->handled = true;
				break;
			case "user.unregister":
				$query = "UPDATE extensions SET location=NULL,expires=NULL WHERE expires IS NOT NULL AND extension='".$ev->GetValue("username")."'";
				$res = query_nores($query);
				$ev->handled = true;
				break;
			case "call.route":
				$caller = $ev->getValue("caller");
				$called = $ev->getValue("called");
				return_route($called,$caller);
				break;
			case "call.answered":
				$id = $ev->GetValue("targetid");
			//	debug("Got call.answered for '$id'. Removing fallback if setted in fallback array :".format_array($s_fallbacks));
				if (isset($s_params_assistant_outgoing[$id])) {
					$params = $s_params_assistant_outgoing[$id];
					$m = new Yate("chan.operation");
					$m->params["id"] = $ev->GetValue("id");
					$m->params["operation"] = "setstate";
					$m->params["state"] = "";
					foreach ($params as $key=>$value)
						$m->params[$key] = $value;
					$m->Dispatch();
				}
				if (isset($s_fallbacks[$id])) {
					debug("Removing fallback for '$id'");
					unset($s_fallbacks[$id]);
				}
				debug("There are ".count($s_fallbacks)." in fallback : ".format_array($s_fallbacks));
				break;
			case "chan.hangup":
				$id = $ev->GetValue("id");
				$reason = $ev->GetValue("reason");
			//	debug("Got '".$ev->name."' for '$id' with reason '$reason'. Fallback :".format_array($s_fallbacks));
				if (isset($params_assistant_outgoing[$id])) {
					debug("Dropping pbxassist params for $id's party");
					unset($s_params_assistant_outgoing[$id]);
				}
				if (isset($s_fallbacks[$id])) {
					debug("Dropping all fallback for '$id'");
					unset($s_fallbacks[$id]);
				}
				break;
			case "chan.disconnected":
				$id = $ev->GetValue("id");
				$reason = $ev->GetValue("reason");
			//	debug("Got '".$ev->name."' for '$id' with reason '$reason'. Fallback :".format_array($s_fallbacks));
				if (!isset($s_fallbacks[$id]))
					break;
				if (in_array($reason, $stoperror)) {
					debug("Dropping all fallback for '$id'");
					unset($s_fallbacks[$id]);
					break;
				}
				$msg = new Yate("call.execute");
				$msg->id = $ev->id;
				$nr = count($s_fallbacks[$id]) - 1;

				$callto = $s_fallbacks[$id][$nr]["location"];
				debug("Doing fallback for '$id' to '$callto'");
				unset($s_fallbacks[$id][$nr]["location"]);
				$msg->params = $s_fallbacks[$id][$nr];
				$msg->params["callto"] = $callto;
				$msg->Dispatch();
				if ($nr != 0)
					unset($s_fallbacks[$id][$nr]);
				else
					unset($s_fallbacks[$id]);
				debug("There are ".count($s_fallbacks)." in fallback :".format_array($s_fallbacks));
				break;
			case "call.cdr":
				$operation = $ev->GetValue("operation");
				$reason = $ev->GetValue("reason");
				switch($operation) {
					case "initialize":
						$query = "INSERT INTO call_logs(time, chan, address, direction, billid, caller, called, duration, billtime, ringtime, status, reason, ended) VALUES(TIMESTAMP 'EPOCH' + INTERVAL '".$ev->GetValue("time")." s', '".$ev->GetValue("chan")."', '".$ev->GetValue("address")."', '".$ev->GetValue("direction")."', '".$ev->GetValue("billid")."', '".$ev->GetValue("caller")."', '".$ev->GetValue("called")."', INTERVAL '".$ev->GetValue("duration")." s', INTERVAL '".$ev->GetValue("billtime")." s', INTERVAL '".$ev->GetValue("ringtime")." s', '".$ev->GetValue("status")."', '$reason', false);UPDATE extensions SET inuse_count=(CASE WHEN inuse_count IS NOT NULL THEN inuse_count+1 ELSE 1 END) WHERE extension='".$ev->GetValue("external")."'";
						$res = query_nores($query);
						break;
					case "update":
						$query = "UPDATE call_logs SET address='".$ev->GetValue("address")."', direction='".$ev->GetValue("direction")."', billid='".$ev->GetValue("billid")."', caller='".$ev->GetValue("caller")."', called='".$ev->GetValue("called")."', duration=INTERVAL '".$ev->GetValue("duration")." s', billtime=INTERVAL '".$ev->GetValue("billtime")." s', ringtime=INTERVAL '".$ev->GetValue("ringtime")." s', status='".$ev->GetValue("status")."', reason='$reason' WHERE chan='".$ev->GetValue("chan")."' AND time=TIMESTAMP 'EPOCH' + INTERVAL '".$ev->GetValue("time")." s'";
						$res = query_nores($query);
						break;
					case "finalize":
						$query = "UPDATE call_logs SET address='".$ev->GetValue("address")."', direction='".$ev->GetValue("direction")."', billid='".$ev->GetValue("billid")."', caller='".$ev->GetValue("caller")."', called='".$ev->GetValue("called")."', duration=INTERVAL '".$ev->GetValue("duration")." s', billtime=INTERVAL '".$ev->GetValue("billtime")." s', ringtime=INTERVAL '".$ev->GetValue("ringtime")." s', status='".$ev->GetValue("status")."', reason='$reason', ended='t' WHERE chan='".$ev->GetValue("chan")."' AND time=TIMESTAMP 'EPOCH' + INTERVAL '".$ev->GetValue("time")." s';UPDATE extensions SET inuse_count=(CASE WHEN inuse_count>0 THEN inuse_count-1 ELSE 0 END), inuse_last=now() WHERE extension='".$ev->GetValue("external")."'";
						$res = query_nores($query);
						break;
				}
				break;
		}
		// This is extremely important.
		//	We MUST let messages return, handled or not 
		if ($ev)
			$ev->Acknowledge();
		break;
	case "answer":
		// Yate::Debug("PHP Answered: " . $ev->name . " id: " . $ev->id);
		break;
	case "installed":
		// Yate::Debug("PHP Installed: " . $ev->name);
		break;
	case "uninstalled":
		// Yate::Debug("PHP Uninstalled: " . $ev->name);
		break;
	default:
		// Yate::Output("PHP Event: " . $ev->type);
	}
}

/* vi: set ts=8 sw=4 sts=4 noet: */
?>

