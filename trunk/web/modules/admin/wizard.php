<div class="content wide">
<?
require_once("lib_wizard.php");
require_once("conf_wizard.php");
require_once("lib_auto_attendant.php");

global $module,$method,$action,$steps, $target_path;

if(!$method)
	$method = $module;

if(substr($method,0,4) == "add_")
	$method = str_replace("add_","edit_",$method);

if($action)
	$call = $method.'_'.$action;
else
	$call = $method;

$call();

function wizard()
{
	global $steps, $logo, $title;

	$wizard = new Wizard($steps, $logo, $title, "wizard_database");
}

function wizard_database()
{
	global $steps;
	$fields = $_SESSION["fields"];

	$functions = array(1=>"change_password", 2=>"define_extensions", 3=>"define_groups", 4=>"define_gateway", 5=>"set_voicemail", 6=>"auto_attendant", 7=>"define_music_on_hold");

	$message = '';
	$errormess = '';

	Database::transaction();
	foreach($steps as $index => $field_settings)
	{
		if(!isset($functions[$index]))
			continue;
		if($functions[$index] == '')
			continue;
		//print "<br/>entering ".$functions[$index]."<br/>";
		$ret = (isset($fields[$index])) ? $functions[$index]($fields[$index]) : $functions[$index]();
		if($ret[0])
			$message .= $ret[1];
		else
			$errormess .= $ret[1];

		if($errormess != '') {
			Database::rollback();
			return array(false, $errormess);
		}
	}

//	Database::rollback();
	Database::commit();
	return array(true, "$message");
//	return array(false, "Ghinion :))");
}

function change_password($fields)
{
	// if change_password was skipped
	if ($fields["new_password"] == '' && $fields["retype_new_password"] == "")
		return array(true, "");

	if($fields["new_password"] != $fields["retype_new_password"])
		return array(false, "The two passwords don't match");

	if(strlen($fields["new_password"]) < 5)
		return array(false, "Password is to short. It must be at least 5 digits long.");

	$user = Model::selection("user", array("username"=>"admin"));
	if(!count($user))
		return array(true, "Didn't change password for default admin. It was already deleted. <br/>");
	$user = $user[0];
	$user->password = $fields["new_password"];
	$res = $user->update();
	if(!$res[0])
		return array(false, $res[1]);
	return array(true, "Password for default admin was changed.");
}

function define_extensions($fields)
{
	// if define extensions was skipped
	if($fields["from"] == "" || $fields["to"] == "")
		return array(true, "");

	$from = $fields["from"];
	$to = $fields["to"];

	if(strlen($from) != strlen($to))
		return array(false, "From and To fields must have the same length.");

	if(Numerify($from) == "NULL" || Numerify($to) == "NULL")
		return array(false, "Fields 'From' as 'To' must be numeric.");

	if($from > $to)
		return array(false, "Field 'From' must be smaller than 'To'.");

	$message = '';
	for($i=Numerify($from); $i<=Numerify($to); $i++)
	{
		$extension = new Extension;
		$extension->extension = Numerify($i);
		while(strlen($extension->extension) < strlen($from))
			$extension->extension = '0'.$extension->extension;
		if($extension->objectExists())
		{
			$message .= 'Skipping extention '.$extension->extension.' because it was previously added.<br/>';
			continue;
		}
		$extension->password = rand(100000,999999);
		$res = $extension->insert();
		if(!$res[0])
			return $res;
	}
	return array(true, $message);
}

function define_groups($fields)
{
	//maximum number of groups that can be defined using the wizard
	$total = count($fields) / 5;
	$error = '';

	for($i=1; $i<=$total;$i++)
	{
		$nr = ($i == 1) ? '' : $i;

		$group = new Group;
		if($fields["group$nr"] == '' || $fields["extension$nr"] == '')
			continue;
		$params = array("group"=>$fields["group$nr"], "extension"=>$fields["extension$nr"]);
		$res = $group->add($params);
		if(!$res[0])
			return array(false, $res[1]);
		$members = $fields["members$nr"];
		$arr_members = explode(",",$members);
		if($members != '') {
			for($j=0; $j<count($arr_members); $j++)
			{
				$extension = new Extension;
				$extension->extension = trim($arr_members[$j]);
				$extension->select('extension');
				if(!$extension->extension_id) {
					$res = $extension->add(array("extension"=>$extension->extension, "password"=>rand(100000,999999)));
					if(!$res[0])
							return array(false, $res[1]);
				}

				$group_member = new Group_Member;
				$params = array("group_id"=>$group->group_id, "extension_id"=>$extension->extension_id);
				$res = $group_member->add($params);
				if(!$res[0])
					return array(false, 'Could not add '.$extension->extension.' to group '.$group->group.' because :'.$res[1]);
			}
		}else{
			$from = $fields["from$nr"];
			$to = $fields["to$nr"];

			if(strlen($from) != strlen($to))
				return array(false, "From and To fields must have the same length.");

			if(Numerify($from) == "NULL" || Numerify($to) == "NULL")
				return array(false, "Fields 'From' as 'To' must be numeric.");

			if($from > $to)
				return array(false, "Field 'From' must be smaller than 'To'.");

			$message = '';
			for($j=Numerify($from); $j<=Numerify($to); $j++)
			{
				$extension = new Extension;
				$extension->extension = Numerify($j);
				$extension->select('extension');
				if(!$extension->extension_id) {
					$res = $extension->add(array("extension"=>$extension->extension, "password"=>rand(100000,999999)));
					if(!$res[0])
						return array(false, $res[1]);
				}
				$group_member = new Group_Member;
				$fields = array("group_id"=>$group->group_id, "extension_id"=>$extension->extension_id);
				$res = $group_member->add($fields);
				if(!$res[0])
					return array(false, 'Could not add '.$extension->extension.' to group '.$group->group.' because :'.$res[1]);
			}
		}
	}
	return array(true, '');
}

function define_gateway($fields)
{
	// in case this step was skipped
	if($fields["protocol"] == "")
		return array(true, "");
	$fields["type"] = ($fields["username"] != "") ? "reg" : "noreg";
	$gateway = new Gateway;
	$gateway->gateway = "default_gateway";
	while(true)
	{
		if($gateway->fieldSelect("count(*)", array("gateway"=>$gateway->gateway)))
			$gateway->gateway .= "_";
		else
			break;
	}
	$fields["gateway"] = $gateway->gateway;
	$res = $gateway->add($fields);

	if($res[0] === true && $gateway->gateway_id) {
		$dial_plan = new Dial_Plan;
		$prio = $dial_plan->fieldSelect("max(priority)");
		if($prio)
			$prio += 10;
		else
			$prio = 10;
		$params["gateway_id"] = $gateway->gateway_id;
		$params["priority"] = $prio;
		$params["dial_plan"] = "default for ".$gateway->gateway;
		$res2 = $dial_plan->add($params);
		if(!$res2[0]) 
			return array(true, "Could not add default dial plan: ".$res2[1]);
	}
	if($res[0])
		return array(true, "");
	return array(false, $res[1]);
}

function set_voicemail($fields)
{
	$number = $fields["number"];
	if($fields["number"] == "")
		return array(true, "");

	$did = new Did;
	$did->number = $number;
	if($did->objectExists())
		return array(true, "Skipping setting voicemail. A did with number '$number' is already defined.");
	$did->number = NULL;
	$did->did = "voicemail";
	while(true) {
		if($did->objectExists())
			$did->did .= "_";
		else
			break;
	}
		
	$did->number = $number;
	$did->destination = "external/nodata/voicemaildb.php";
	$res = $did->insert();
	if(!$res[0])
		return $res;
	return array(true, '');
}

// this step should be at the end (since files are uploaded for the prompts)
function auto_attendant($fields)
{
	global $target_path;

	// if this is empty then i suppose the user pressed Skip
	if($fields["number"] == "" || $fields["extension"] == "")
		return array(true, "");

	$extension = new Extension;
	$extension_id = $extension->fieldSelect("extension_id", array("extension"=>$fields["extension"]));
	if(!$extension_id) {
		$res = $extension->add(array("extension"=>$fields["extension"], "password"=>rand(100000,999999)));
		if(!$res[0])
			return array(false, $res[1]);
		$extension_id = $extension->extension_id;
	}
	if(!is_numeric($extension_id))
		return array(false, "Don't have default extension for auto attendant setted.");

	$did = new Did;
	$did->did = "auto attendant";
	while(true) {
		if($did->fieldSelect("count(*)", array("did"=>$did->did)))
			$did->did .= "_";
		else
			break;
	}
	$params = array("number"=>$fields["number"], "did"=>$did->did, "destination"=>"external/nodata/auto_attendant.php", "default_destination"=>"extension", "extension"=>$extension_id);
	$did->did = NULL;
	$res = $did->add($params);
	if(!$res[0])
		return array(false, "Could not create did for the auto attendant :".$res[1]);

	// upload and insert/update the prompts
	//$online = basename($_FILES['online_prompt']['name']);
	//$offline = basename($_FILES['offline_prompt']['name']);
	$online = $fields['online_prompt']['orig_name'];
	$offline = $fields['offline_prompt']['orig_name'];

	if(strtolower(substr($online,-4)) != ".mp3" || strtolower(substr($offline,-4)) != ".mp3")
		return array(false, "File format must be .mp3");

	$prompts = array("online"=>$online, "offline"=>$offline);
	$path = "$target_path/auto_attendant";

	if(!is_dir($path))
		mkdir($path);

	$time = date("Y-m-d_H:i:s");
	$online_file = "$path/online_".$time.".mp3";
	$offline_file = "$path/offline_".$time.".mp3";

	//if (!move_uploaded_file($_FILES["online_prompt"]['tmp_name'],$online_file))
	if(!copy($fields['online_prompt']['path'], $online_file))
		return array(false, "Could not upload file for online mode");
	//if (!move_uploaded_file($_FILES["offline_prompt"]['tmp_name'],$offline_file))
	if(!copy($fields['offline_prompt']['path'], $offline_file))
		return array(false, "Could not upload file for offline mode");

	$slin_online = str_ireplace(".mp3",".slin",$online_file);
	$slin_offline = str_ireplace(".mp3",".slin",$offline_file);
	passthru("madplay -q --no-tty-control -m -R 8000 -o raw:\"$slin_online\" \"".$online_file."\"");
	passthru("madplay -q --no-tty-control -m -R 8000 -o raw:\"$slin_offline\" \"".$offline_file."\"");

	if(!is_file($slin_online) || !is_file($slin_offline))
		return array(false, "Could not convert prompts for auto attendant in .au format.");

	foreach($prompts as $status=>$prompt_name)
	{
		$prompts = Model::selection("prompt", array("status"=>$status));
		if(!count($prompts))
			$prompt = new Prompt;
		else {
			$prompt = $prompts[0];
			if(is_file("$path/".$prompt->file))
				unlink("$path/".$prompt->file);
			$slin = str_replace(".mp3",".slin","$path/".$prompt->file);
			if(is_file($slin))
				unlink($slin);
		}
		$prompt->prompt = $prompt_name;
		$prompt->status = $status;
		$prompt->file = $status."_".$time.".mp3";
		$res = (!$prompt->prompt_id) ? $prompt->insert() : $prompt->update();
		if(!$res[0]) 
			return array(false,"Could not upload the prompts for Auto Attendant. Please try again");
		if($prompt->status == "online")
			$onlineprompt = $prompt;
		else
			$offlineprompt = $prompt;
	}

	//schedule the online auto attendant
	$_SESSION["wiz_config"] = "on";
	scheduling_database();
	unset($_SESSION["wiz_config"]);

	//define the keys for each of the two states 
	$total = 5;
	for($i=1; $i<=$total; $i++)
	{
		$type = $fields["type$i"];
		$key = $fields["type$i"];
		$number = $fields["number$i"];
		$group = $fields["group$i"];
		if($type == '' || $key == '' || ($number == "" && $group == ""))
			continue;
		$fields = array("key"=>$key);
		$fields["prompt_id"] = ${$type."prompt"}->prompt_id;
		if($group != '') {
			$groups = Model::selection("group", array("group"=>$group));
			if(!count($group)) 
				return array(false, "Group ".$group." does not exist.");
			$fields["destination"] = $groups[0]->extension;
		}elseif($number != "")
			$fields["destination"] = $number;
		
		$aut_key = new Key;
		$res = $aut_key->add($fields);
		if(!$res[0])
			return $res;
	}

	return array(true, "");
}

function define_music_on_hold($fields=NULL)
{
	global $target_path;

	$total = 5;
	$files = array();

	$path = "$target_path/moh";
	if(!is_dir($path))
		mkdir($path);

	$playlist = Model::selection("playlist", array("in_use"=>"t"));
	if(!count($playlist)) 
		return array(false, "You don't have any playlist setted.");
	$playlist_id = $playlist[0]->playlist_id;
	
	for($i=1; $i<=$total; $i++)
	{
		if(!isset($fields["file$i"]))
			continue;
		if(!isset($fields["file$i"]["path"]))
			continue;
		$music_on_hold = new Music_on_hold;
		$gen_name = date('Y-m-d_H:i:s_u_').rand(100,1000). ".mp3";
		$new_file = "$path/".$gen_name;
		if(!copy($fields["file$i"]["path"], $new_file))
			return array(false, "Couldn't move file ".$fields["file$i"]["orig_name"]);

	//	$au = str_replace(".mp3",".au",$new_file);
	//	passthru("sox \"$new_file\"  -r 8000 -c 1 -A \"$au\"");

		$res = $music_on_hold->add(array("music_on_hold"=>$fields["file$i"]["orig_name"], "file"=>$gen_name));
		if(!$res[0])
			return $res;
		$playlist_item = new Playlist_Item;
		$res = $playlist_item->add(array("playlist_id"=>$playlist_id, "music_on_hold_id"=>$music_on_hold->music_on_hold_id));
		if(!$res[0])
			return $res;
	}
	return array(true, "");
}
?>
</div>