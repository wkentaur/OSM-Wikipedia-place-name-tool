<?php
// sep_wp_lang_st_4.php
// 
// uses psql WP_LANG_TABLE
// updates psql WP_LANG_TABLE
//

error_reporting(E_ALL);
ini_set('display_errors', true);
ini_set('html_errors', false);

$time_start = microtime(true);
   
require('common.php');

register_shutdown_function('shutdown'); 


//main

$log = new Logging();  
$log->lwrite('Started');  

// open psql connection
$pg = pg_connect('host='. OSM_HOST .' dbname='. OSM_DB);
 
// check for connection error
if($e = pg_last_error()) trigger_error($e, E_USER_ERROR);

// 
$sql = "SELECT osm_table, osm_id, ll_lang, ll_title, ll_from_lang, ll_from 
   FROM ". OSM_WP_TABLE .", ". WP_LANG_TABLE ." 
   WHERE (". OSM_WP_TABLE .".wiki_lang = ". WP_LANG_TABLE .".ll_from_lang AND 
         ". OSM_WP_TABLE .".wiki_page_id = ". WP_LANG_TABLE .".ll_from)
         ";

$res = pg_query($sql);
if($e = pg_last_error()) trigger_error($e, E_USER_ERROR);

$log->lwrite('Selected '. pg_num_rows($res) . ' rows from WP_LANG_TABLE.');

$name_fields = '';
foreach ($target_langs as $check_lang) {
    if ($name_fields) {
        $name_fields = $name_fields . ", tags->'name:" . $check_lang . "' AS ". $check_lang;
    } else {
        $name_fields = "tags->'name:" . $check_lang . "' AS ". $check_lang;
    }
}

while($row = pg_fetch_assoc($res))
{
    $osm_table = $row['osm_table'];
    $osm_id = $row['osm_id'];
    $lang = $row['ll_lang'];
    $title = $row['ll_title'];
    $ll_from_lang = $row['ll_from_lang'];
    $ll_from = $row['ll_from'];

    $osm_sql = "SELECT  name, tags->'route_name' AS route_name, $name_fields
        FROM $osm_table 
        WHERE (osm_id = '$osm_id')
        LIMIT 1";
    //osm_id not really unique
    $osm_res = pg_query($osm_sql);
    if($e = pg_last_error()) trigger_error($e, E_USER_ERROR);
    while($osm_row = pg_fetch_assoc($osm_res))
    {
        if ($osm_row['name']) {
            $osm_name = $osm_row['name'];
        } else {
            $osm_name = $osm_row['route_name'];
        }
        //foreach ($target_langs as $check_lang) {
        //}
        $name_in_wp = strip_wp_title($title, $lang);
        if ($osm_row["$lang"]) { //name:xxx already set in OSM
            $lang_status = $st_lang['IS_IN_OSM'];
        } elseif (strcmp($name_in_wp, $osm_name) == 0) {
            $lang_status = $st_lang['SAME_AS_OSM_NAME'];
        } else {
            $lang_status = $st_lang['TO_CHECK'];
        }
        $update_sql = "UPDATE ". WP_LANG_TABLE . " SET status = '$lang_status'
            WHERE ll_from_lang = '$ll_from_lang' AND ll_from = '$ll_from' AND ll_lang = '$lang'";
        $update_res = pg_query($update_sql);
        if($e = pg_last_error()) trigger_error($e, E_USER_ERROR);

    }
    
} //while

$time_end = microtime(true);
$time = $time_end - $time_start;
$log->lwrite('Ended. Runtime: '. $time);  

?>