<?
/**
 * structure.php
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

$struct = array();

$struct["admin_routes"] = array("manage","new_route");
$struct["admin_internal_routes"] = array("manage", "add_internal_route");
$struct["admin_registrations"] = array("manage", "add_registration");
$struct["admin_admins"] = array("manage", "add_admin");
$struct["admin_extensions"] = array("manage","groups", "add_extension", "add_range","add_group", "search", "import", "export");
$struct["admin_outbound"] = array("gateways", "dial_plan", "add_gateway", "add_dial_plan", "System_CallerID");
$struct["admin_auto_attendant"] = array("prompts", "keys", "scheduling", "wizard");
$struct["admin_settings"] = array("general"/*, "equipments"*/, "network", "address_book", "admins");
$struct["admin_HOME"] = array("manage", "logs", "active_calls", "call_logs");
$struct["admin_music_on_hold"] = array("music_on_hold", "playlists", "add_playlist");
$struct["admin_dids"] = array("manage", "conferences", "add_did", "add_conference");
$struct["admin_PBX_features"] = array("digits", "call_transfer", "call_hold", "conference", "call_hunt", "call_pick_up", "flush_digits", "passthrought", "retake");
$struct["extension_PBX_features"] = array("digits", "call_transfer", "call_hold", "conference", "call_hunt", "call_pick_up", "flush_digits", "passthrought", "retake");

// options to be disabled
//$block["admin_settings"] = array("network"); 

?>