<?php
// get_osm_wp.php
// 
// get 'wikipedia' keys from OSM databases
//
// uses psql OSM planet_point, planet_line and planet_polygon tables
// updates psql OSM_WP_TABLE
//

error_reporting(E_ALL);
ini_set('display_errors', true);
ini_set('html_errors', false);

$time_start = microtime(true);

require('common.php');

//constants
define('MAX_TITLE_LENGTH', 255);

$log = new Logging();  
$log->lwrite('Started');  

register_shutdown_function('shutdown'); 

// select POIs with 'wikipedia' key set from OSM-db-s (planet_point, planet_line, planet_polygon)
//  TODO: also some 'wikipedia:xxx' keys are used
// 
// make temp-db from those POI-s


// open psql connection

function mb_ucasefirst($str) { 
    $str[0] = mb_strtoupper($str[0]); 
    return $str; 
}

$pg_conn = pg_connect('host='. OSM_HOST .' dbname='. OSM_DB);
pg_set_client_encoding("UNICODE");
 
// check for connection error
if($e = pg_last_error()) trigger_error($e, E_USER_ERROR);
 
// clear cross-table
//$del_sql = "DELETE FROM ". OSM_WP_TABLE;
//$del_res = pg_query($del_sql);
//if($e = pg_last_error()) trigger_error($e, E_USER_ERROR);

//insert ignore
$ins_rule = 'CREATE OR REPLACE RULE "osm_wp_on_duplicate_ignore" AS ON INSERT TO "'. OSM_WP_TABLE .'"
    WHERE EXISTS(SELECT 1 FROM '. OSM_WP_TABLE .' 
        WHERE (osm_table, osm_id)=(NEW.osm_table, NEW.osm_id))
    DO INSTEAD NOTHING';
$res = pg_query($ins_rule);
if($e = pg_last_error()) trigger_error($e, E_USER_ERROR);
    

foreach ($osm_tables as $o_table) {
    $sql = "SELECT osm_id, tags->'wikipedia' AS wikipedia
        FROM $o_table
    WHERE
        (tags ? 'wikipedia')
    ";
  
    // query the database
    $res = pg_query($sql);
    
    // check for query error
    if($e = pg_last_error()) trigger_error($e, E_USER_ERROR);

    $log->lwrite('Selected '. pg_num_rows($res) . ' rows with Wikipedia tag from OSM: '. $o_table);
    
    $insert_prep_sql = "INSERT INTO ". OSM_WP_TABLE ." (osm_table, osm_id, osm_wikipedia, status, wiki_lang, wiki_art_title)
        VALUES ($1, $2, $3, $4, $5, $6)";    
    if (!pg_connection_busy($pg_conn)) {
        pg_send_prepare($pg_conn, "insert_osm_wp", $insert_prep_sql);
        $res1 = pg_get_result($pg_conn);
    }
  
    while($row = pg_fetch_assoc($res))
    {
        $osm_id = $row['osm_id'];
        $osm_wikipedia = $row['wikipedia'];
        $status = $status_arr['NOT_SET'];
        $wiki = '';
        $page_title = '';

        // check POIs for errors, double entries for same articles etc
        //  how to discard something like "wikipedia"=>"Lake"??

        // formats of osm 'wikipedia' key:
        // http://*.wikipedia.org/wiki/page_title or with urlencoded page title
        // just page title: St Paul's Cathedral
        // lang:page title      
        
        $data_exists = false;
        $update_data = false;
        $is_sql = "SELECT osm_wikipedia FROM ". OSM_WP_TABLE ." 
           WHERE osm_table='". $o_table ."' AND osm_id=". intval( $osm_id );
        $is_res = pg_query($is_sql);
        if($e = pg_last_error()) trigger_error($e, E_USER_ERROR);
        if ( $is_row = pg_fetch_assoc($is_res) ) {
            $data_exists = true;
            $update_data = strcmp($osm_wikipedia, $is_row['osm_wikipedia']) != 0;
        }
        
        if (!$data_exists OR $update_data) {
            //also match https
            if ( preg_match('@^https?://@i', $osm_wikipedia) ) {
                if ( preg_match('@^https?://([\w\-]+?)\.wikipedia\.org/wiki/(.+)@i',
                    $osm_wikipedia, $matches) ) {
                    $wiki = strtolower( $matches[1] );
                    $page_title = urldecode($matches[2]);
                    //FIXME fix urldecoding 
                    //title not utf-8?
                    if ( ! mb_detect_encoding($page_title, "UTF-8", true) ) {
                        $status = $status_arr['URLDECODE_ERROR'];
                        $page_title = '';
                    }
                //https://secure.wikimedia.org/wikipedia/de/wiki/Burgruine_Sulzberg
                } elseif ( preg_match(  '@^https://secure\.wikimedia\.org/wikipedia/([\w\-]+?)/wiki/(.+)@i',
                    $osm_wikipedia, $matches) ) {
                    $wiki = strtolower( $matches[1] );
                    $page_title = urldecode($matches[2]);
                    if ( ! mb_detect_encoding($page_title, "UTF-8", true) ) {
                        $status = $status_arr['URLDECODE_ERROR'];
                        $page_title = '';
                    }
                } else {            //wrong url
                    $status = $status_arr['BAD_URL'];
                }
            } elseif ( preg_match('/^([\w\-]+?):(.+)/',
                       $osm_wikipedia, $matches) ) {      // lang:page title
                    $wiki = strtolower( $matches[1] );
                    $page_title = $matches[2];
            } else {                                         // should be just page title, in en.wikipedia
                $wiki = 'en';
                $page_title = $osm_wikipedia;
            }

            if ( !is_string( $page_title ) ) {
                $msg = 'page_title is not string! ';
                print $msg . "\n";
                print $page_title;
                trigger_error($msg, E_USER_ERROR);
            }
            
            //convert spaces to '_'; http://www.mediawiki.org/wiki/Manual:Page_table#page_title
            if ($status == $status_arr['NOT_SET']) {
                $page_title = str_replace(' ', '_', $page_title);
                //check if title points to section
                if ( preg_match('/\#/', $page_title) ) {
                    $status = $status_arr['SECTION_REF'];
                }
            }

            //links to Commons
            if ( strcmp($wiki, 'commons') == 0 ) {
                $status = $status_arr['COMMONS'];
            }
            
            //titles are ucfirst in wikipedia db
            $page_title = mb_ucasefirst( $page_title );
            if ( strlen($page_title) > MAX_TITLE_LENGTH )
                $page_title = mb_substr($page_title, 0, MAX_TITLE_LENGTH, 'UTF-8');
            
            if ($update_data) {
                $update_sql = "UPDATE ". OSM_WP_TABLE ." SET 
                  osm_wikipedia='" . pg_escape_string($osm_wikipedia) . "', 
                  status=". $status .",
                  wiki_lang= '". pg_escape_string($wiki) ."',
                  wiki_art_title= '". pg_escape_string($page_title) ."',
                  wiki_page_id=0
                  WHERE osm_table='". $o_table ."' AND osm_id=". intval( $osm_id );
                $update_res = pg_query($update_sql);
                if($e = pg_last_error()) trigger_error($e, E_USER_ERROR);
            } else {
                // query the database
                if (!pg_connection_busy($pg_conn)) {
                    pg_send_execute($pg_conn, "insert_osm_wp", array($o_table, $osm_id, $osm_wikipedia, $status, $wiki, $page_title) );
                    $insert_res = pg_get_result($pg_conn);
                    if ($e = pg_last_error()) trigger_error($e, E_USER_ERROR);
                }
            }
        } //if new or update
    } //while

} //foreach

$del_rule =  'DROP RULE "osm_wp_on_duplicate_ignore" ON "'. OSM_WP_TABLE .'"';
$res = pg_query($del_rule);
if($e = pg_last_error()) trigger_error($e, E_USER_ERROR);


$time_end = microtime(true);
$time = $time_end - $time_start;
$log->lwrite('Ended. Runtime: '. $time);
          
?>