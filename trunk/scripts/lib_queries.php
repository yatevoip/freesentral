<?
/**
 * lib_queries.php
 * This file is part of the FreeSentral Project http://freesentral.com
 *
 * FreeSentral - is a Web Graphical User Interface for easy configuration of the Yate PBX software
 * Copyright (C) 2008-2009 Null Team
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA 02110-1301, USA.
 */
?>
<?
require_once("config.php");

function query_to_array($query)
{
	$res = query($query);
	if(!$res) {
		return array();
	}
	$array = array();
	for($i=0; $i<pg_num_rows($res);$i++) {
		$array[$i] = array();
		for($j=0; $j<pg_num_fields($res); $j++) {
			$array[$i][pg_field_name($res,$j)] = pg_fetch_result($res,$i,$j);
		}
	}
	pg_free_result($res);
	return $array;
}

function query($query)
{
	global $conn, $query_on, $max_resets_conn;
	$resets = 0;
	while(true)
	{
		$res = pg_query($conn,$query);
		if(!$res) 
		{
			while(true)
			{
				if($resets >= ($max_resets_conn-1)) 
				{
					Yate::Output("Could not execute: $query");
					return null;
				}
				$resets++;
				if(pg_connection_status($conn) == PGSQL_CONNECTION_BAD)
				{
					if(pg_connection_reset($conn))
						break;
					sleep(1);
				}else
					$resets = $max_resets_conn;
			}
		}else
			break;
	}
	if($query_on)
		Yate::Output("Executed: $query");
	return $res;
}

function query_nores($query)
{
	$res = query($query);
	if ($res)
		pg_free_result($res);
}

function getCustomVoicemailDir($called)
{
    global $vm_base;

    $last = $called[strlen($called)-1];
    $alast = $called[strlen($called)-2];

    $dir = "$vm_base/$last";
    if (!is_dir($dir)) {
        mkdir($dir,0750);
		chown($dir,"apache");
	}
    $dir = "$vm_base/$last/$alast/";
    if (!is_dir($dir)) {
        mkdir($dir,0750);
		chown($dir,"apache");
	}
	$dir = "$vm_base/$last/$alast/$called";
	if (!is_dir($dir)) {
		mkdir($dir,0750);
		chown($dir,"apache");
	}
    return $dir;
}


?>