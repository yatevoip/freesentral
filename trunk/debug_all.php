<html>
<head>
	<title> Debug Page </title>
	<link type="text/css" rel="stylesheet" href="main.css" />
</head>
<body>
<?
require_once "set_debug.php";
require_once "lib.php";

//session_start();
/*
if(isset($_SESSION["debug_all"])){
	unset($_SESSION["debug_all"]);
	print 'Debug OFF';
}else{
	$_SESSION["debug_all"] = "on";
	print 'Debug ON';
}*/


	$array = array(
				1 => "E_ERROR",
				2 => "E_WARNING",
				4 => "E_PARSE",
				8 => "E_NOTICE",
				16 => "E_CORE_ERROR",
				32 => "E_CORE_WARNING",
				64 => "E_COMPILE_ERROR",
				128 =>	"E_COMPILE_WARNING",
				256 =>  "E_USER_ERROR",
				512 =>	"E_USER_WARNING",
				1024 =>	"E_USER_NOTICE",
				2048 =>	"E_STRICT",
				4096 =>	"E_RECOVERABLE_ERROR",
				8191 => "E_ALL",
				6183 => "E_ALL ^ E_NOTICE",
				10239 => "E_ALL | E_STRICT",
				0 => "disable"
			);

$action = getparam("action");
if($action) 
	$call = "debug_".$action;
else
	$call = "debug";

$call();

function debug()
{
	global $array;

	$error = ini_get("error_reporting");
	$errors = $array;
	if(isset($array[$error]))
		$errors["selected"] = $array[$error];
	else{
		$errors[$error] = $error;
		$errors["selected"] = $errors[$error];
	}
		
	$display_errors = (ini_get("display_errors")) ? 't' : 'f';

	$debug_queries = (isset($_SESSION["debug_all"])) ? "t" : "f";
	$log_errors = (ini_get("log_errors")) ? "t" : "f";
	$error_log = ($a = ini_get("error_log")) ? $a : '';
	$arr = array(
					"debug_queries"=>array("value"=>$debug_queries, "display"=>"checkbox"),
					"error_reporting"=>array($errors, "display"=>"select"), 
					"display_errors"=>array($display_errors, "display"=>"checkbox"),
					"log_errors"=>array("value"=>$log_errors, "display"=>"checkbox"),
					"error_log"=>array("value"=>$error_log, "display"=>"checkbox")
				);

?>	<br/><br/>
	<form action="debug_all.php" method="post"><?
	addHidden("database");
	editObject(NULL,$arr, "Setting debug levels", "Save");
?></form><?
}


function debug_database()
{
	global $array;

	if(getparam("debug_queries") == "on")
		$_SESSION["debug_all"] = "on";
	else
		unset($_SESSION["debug_all"]);

	$err = getparam("error_reporting");
	$error_reporting = NULL;
	foreach($array as $key=>$value) {
	//	print $key. ' '. $value.'<br/>';
		if($value == $err) {
			$error_reporting = $key;
			break;
		}
	}
	if(!$error_reporting)
		$error_reporting = $err;
	ini_set("error_reporting",$error_reporting);
	$_SESSION["error_reporting"] = $error_reporting;
//	ini_set('error_reporting', E_ALL);

	$display_errors = (getparam("display_errors") == "on") ? true : false;

	ini_set("display_errors",$display_errors);
	$_SESSION["display_errors"] = $display_errors;

	$log_errors = (getparam("log_errors") == "on") ? true : false;
	ini_set("log_errors",$log_errors);
	$_SESSION["log_errors"] = $log_errors;

	$error_log = getparam("error_log");
	ini_set("error_log", $error_log);
	$_SESSION["error_log"] = $error_log;

	print 'Settings were saved';
	debug();
}

?>
</body>
</html>