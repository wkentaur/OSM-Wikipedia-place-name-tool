<?php
// get_wp_lang.php
// 
// check article referenced by OSM from Wikipedia db
//
// uses psql OSM_WP_TABLE and Wikipedia mysql: langlinks, page, and __________ tables from different wikis
//      http://www.mediawiki.org/wiki/Manual:Langlinks_table
// updates psql WP_LANG_TABLE
//

error_reporting(E_ALL);
ini_set('display_errors', true);
ini_set('html_errors', false);
$time_start = microtime(true);

require('common.php');

register_shutdown_function('shutdown'); 

// get corresponding article in xxx Wikipedia and info about 
// article type (city, river etc) from Wikipedia-db, and add it to temp-db
//-------- should also check if Wikipedia article is about a person, but how?
//-------- the article name should also be read from the head of article 
//   wikitext '''article name is in bold''', there can also be multiple article names
// make some sanity checks by comparing POI type from OSM and article type from Wikipedia
// FIXME: make some indexes for psql tables

$log = new Logging();  
$log->lwrite('Started'); 

// open psql connection
$pg_conn = pg_connect('host='. OSM_HOST .' dbname='. OSM_DB);
 
// check for connection error
if($e = pg_last_error()) trigger_error($e, E_USER_ERROR);

// clear WP_LANG_TABLE
//$del_sql = "DELETE FROM ". WP_LANG_TABLE;
//$del_res = pg_query($del_sql);
//if($e = pg_last_error()) trigger_error($e, E_USER_ERROR);


$del_rule = 'DROP RULE "wp_lang_on_duplicate_ignore" ON "'. WP_LANG_TABLE .'"';
$res = pg_query($del_rule);
if($e = pg_last_error()) trigger_error($e, E_USER_ERROR);


//FIXME change to replace
//insert ignore
$ins_rule = 'CREATE RULE "wp_lang_on_duplicate_ignore" AS ON INSERT TO "'. WP_LANG_TABLE .'"
    WHERE EXISTS(SELECT 1 FROM '. WP_LANG_TABLE .' 
        WHERE (ll_from_lang, ll_from, ll_lang)=(NEW.ll_from_lang, NEW.ll_from, NEW.ll_lang))
    DO INSTEAD NOTHING';
$res = pg_query($ins_rule);
if($e = pg_last_error()) trigger_error($e, E_USER_ERROR);   


// get wiki and article_id
$sql = "SELECT wiki_lang, wiki_art_title, wiki_page_id FROM ". OSM_WP_TABLE ."
         WHERE status='". $status_arr['OK'] ."'
         ORDER BY wiki_lang";

$res = pg_query($sql);
if($e = pg_last_error()) trigger_error($e, E_USER_ERROR);

$log->lwrite('Selected '. pg_num_rows($res) . ' rows from OSM_WP_TABLE where status='. $status_arr['OK']);

$wiki = '';
$prev_wiki = '';
$db_selected = '';

$insert_prep_sql = "INSERT INTO ". WP_LANG_TABLE ." (ll_from_lang, ll_from, ll_lang, ll_title)
        VALUES ($1, $2, $3, $4)";    
if (!pg_connection_busy($pg_conn)) {
    pg_send_prepare($pg_conn, "insert_wp_lang", $insert_prep_sql);
    $res1 = pg_get_result($pg_conn);
}
  
while($row = pg_fetch_assoc($res))
{
    $prev_wiki = $wiki;
    $wiki = $row['wiki_lang'];
    $wiki_page_id = $row['wiki_page_id'];
    $wiki_art_title = $row['wiki_art_title'];

    if ( ($wiki != $prev_wiki) OR  ! $db_selected) {    //no need to reconnect to same wiki
        $db_selected = select_wiki_db($wiki);
    }

    //get Wikipedia article links for target languages
    foreach ($target_langs as $check_lang) {
    
        if ($check_lang == $wiki) {  
            $ll_title = $wiki_art_title;
            
            //insert into psql WP_LANG_TABLE
            if (!pg_connection_busy($pg_conn)) {
                pg_send_execute($pg_conn, "insert_wp_lang", array($wiki, $wiki_page_id, $check_lang, $ll_title) );
                $insert_res = pg_get_result($pg_conn);
                if ($e = pg_last_error()) trigger_error($e, E_USER_ERROR);
            }        

        } else {
        
            $lang_sql = "SELECT ll_title FROM langlinks 
                  WHERE ll_from = '$wiki_page_id' AND ll_lang = '$check_lang'";
            $result = mysql_query($lang_sql);
            if (!$result) {
                trigger_error('Invalid query: ' . mysql_error(), E_USER_ERROR);
            }

            while ($lang_row = mysql_fetch_assoc($result)) {
                $ll_title = $lang_row['ll_title'];
    
                //insert into psql WP_LANG_TABLE
                if (!pg_connection_busy($pg_conn)) {
                    pg_send_execute($pg_conn, "insert_wp_lang", array($wiki, $wiki_page_id, $check_lang, $ll_title) );
                    $insert_res = pg_get_result($pg_conn);
                    if ($e = pg_last_error()) trigger_error($e, E_USER_ERROR);
                }
            
            } //while
        
        } //if-else
    } //foreach
    
} //while

$del_rule = 'DROP RULE "wp_lang_on_duplicate_ignore" ON "'. WP_LANG_TABLE .'"';
$res = pg_query($del_rule);
if($e = pg_last_error()) trigger_error($e, E_USER_ERROR);

$time_end = microtime(true);
$time = $time_end - $time_start;
$log->lwrite('Ended. Runtime: '. $time);

?>