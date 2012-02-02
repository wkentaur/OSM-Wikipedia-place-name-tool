<?php
// check_wp_title.php
// 
// check article referenced by OSM from Wikipedia db
//
// uses psql: OSM_WP_TABLE and Wikipedia mysql: page tables from different wikis
// updates psql OSM_WP_TABLE
//

error_reporting(E_ALL);
ini_set('display_errors', true);
ini_set('html_errors', false);
$time_start = microtime(true);

require('common.php');

register_shutdown_function('shutdown'); 

//functions

    //get redirected page title and namespace    
    function get_redir_page_ns_title($in_page_id) {
        $ret_page_ns = '';
        $ret_page_title = '';
        
        if ($in_page_id) {
            $art_sql = sprintf("SELECT rd_namespace, rd_title FROM redirect
                WHERE rd_from = '%s'",
                $in_page_id);

            $result = mysql_query($art_sql);
            if (!$result) {
                die('Invalid query: ' . mysql_error());
            }

            if (mysql_num_rows($result)) {
                while ($art_row = mysql_fetch_assoc($result)) {
                    $ret_page_ns = $art_row['rd_namespace'];
                    $ret_page_title = $art_row['rd_title'];
                }
            }
        }
        
        return array($ret_page_ns, $ret_page_title);
    
    } //func

//get Wikipedia article page_id
function get_wp_page_id($page_title, $follow_redirect = false) {

    $out_status = '';
    $out_wiki_page_id = '';
   
    $art_sql = sprintf("SELECT page_id, page_is_redirect FROM page
              WHERE page_namespace = '%s' AND page_title = '%s'",
              WP_ARTICLE_NS,
              mysql_real_escape_string($page_title));

    $result = mysql_query($art_sql);
    if (!$result) {
        trigger_error('Invalid query: ' . mysql_error(), E_USER_ERROR);
    }

    if (mysql_num_rows($result)) {
        while ($art_row = mysql_fetch_assoc($result)) {
            if ( $art_row['page_is_redirect'] ) {
                if ($follow_redirect) {
                    list($redir_ns, $redir_title) = get_redir_page_ns_title($art_row['page_id']);
                    if ($redir_ns == WP_ARTICLE_NS) {
                        list($out_status, $out_wiki_page_id) = get_wp_page_id($redir_title);
                    } else {
                        $out_status = 'REDIRECT';
                    }
                } else {
                    $out_status = 'REDIRECT';
                }
            } else {
                $out_status = 'OK';
                $out_wiki_page_id = $art_row['page_id'];
            }
        }
    } else {
        $out_status = 'NOT_FOUND';
    }
       
    // Free the resources associated with the result set
    // This is done automatically at the end of the script
    mysql_free_result($result);
    
    return array($out_status, $out_wiki_page_id);
} //func


////main

$log = new Logging();  
$log->lwrite('Started'); 

// open psql connection
$pg = pg_connect('host='. OSM_HOST .' dbname='. OSM_DB);
 
// check for connection error
if($e = pg_last_error()) trigger_error($e, E_USER_ERROR);


//get multiple refs to same wiki article
$sql = "SELECT osm_wikipedia
        FROM ". OSM_WP_TABLE ."
        WHERE status <> ". $status_arr['DOUBLE_REF'] ."
        GROUP BY osm_wikipedia 
        HAVING count(*) > 1
";
 
// query the database
$res = pg_query($sql);
    
// check for query error
if($e = pg_last_error()) trigger_error($e, E_USER_ERROR);

while($row = pg_fetch_assoc($res))
{
    $esc_osm_wikipedia = pg_escape_string($row['osm_wikipedia']);

    $status = $status_arr['DOUBLE_REF'];
    $update_sql = "UPDATE ". OSM_WP_TABLE ." SET status = '$status'
        WHERE osm_wikipedia = '$esc_osm_wikipedia'
    ";
        
    // query the database
    $update_res = pg_query($update_sql);

    // check for query error
    if($e = pg_last_error()) trigger_error($e, E_USER_ERROR);
    
}


// get wiki and title referenced in OSM
$sql = "SELECT osm_table, osm_id, wiki_lang, wiki_art_title FROM ". OSM_WP_TABLE ."
         WHERE status='". $status_arr['NOT_SET'] ."'
         ORDER BY wiki_lang";

$res = pg_query($sql);
if($e = pg_last_error()) trigger_error($e, E_USER_ERROR);

$log->lwrite('Selected '. pg_num_rows($res) . ' rows from OSM_WP_TABLE where status='. $status_arr['NOT_SET']);

$wiki = '';
$prev_wiki = '';
$db_selected = '';
    
while($row = pg_fetch_assoc($res))
{
    $prev_wiki = $wiki;
    $osm_table = $row['osm_table'];
    $osm_id = $row['osm_id'];
    $wiki = $row['wiki_lang'];
    $page_title = $row['wiki_art_title'];
    $out_status = '';
    $out_wiki_page_id = '';
    
    if ( ($wiki != $prev_wiki) OR  ! $db_selected) {    //no need to reconnect to same wiki
        $db_selected = select_wiki_db($wiki);
    }
    
    if ($db_selected) {
        $follow_redir = true;
        $ret_arr = get_wp_page_id($page_title, $follow_redir);
        $ret_status = $ret_arr[0];
        $out_wiki_page_id = $ret_arr[1];

        switch ($ret_status) {
            case "OK":
                $out_status = $status_arr['OK'];
                break;
            case "NOT_FOUND":
                $out_status = $status_arr['NOT_FOUND'];
                break;
            case "REDIRECT":
                $out_status = $status_arr['IS_REDIRECT'];
                break;
        }
    } else {
        $out_status = $status_arr['UNKNOWN_WIKI'];
    }
    
    //update psql OSM_WP_TABLE
    if ($out_wiki_page_id) {
        $update_sql = "UPDATE ". OSM_WP_TABLE . " SET status = '$out_status', wiki_page_id = '$out_wiki_page_id'
            WHERE osm_table = '$osm_table' AND osm_id = '$osm_id'";
    } else {
        $update_sql = "UPDATE ". OSM_WP_TABLE . " SET status = '$out_status'
            WHERE osm_table = '$osm_table' AND osm_id = '$osm_id'";
    }

    $update_res = pg_query($update_sql);
    if($e = pg_last_error()) trigger_error($e, E_USER_ERROR);
    
} //while($row = pg_fetch_assoc($res))
         
$time_end = microtime(true);
$time = $time_end - $time_start;
$log->lwrite('Ended. Runtime: '. $time);
       

?>