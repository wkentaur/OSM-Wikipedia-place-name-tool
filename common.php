<?php
// common.php
// 

//classes
require_once('logging.class.php');

// constants

define('OSM_HOST', 'sql-mapnik');
define('OSM_DB', 'osm_mapnik');
define('OSM_WP_TABLE', 'kentaur_osm_wp_links');
define('WP_LANG_TABLE', 'kentaur_wp_lang');
define('BLACKLIST_TABLE', 'kentaur_lang_blacklist');
define('DOWN_STATS_TABLE', 'kentaur_download_stats');
define('DOWNLOAD_OSC_FILE', 'get_osc_file.php');
//wikipedia article namespace
define('WP_ARTICLE_NS', 0);

# status in OSM_WP_TABLE
$status_arr = array(
    'NOT_SET' => -1, 
    'OK' => 0, 
    'BAD_URL' => 1, 
    'SECTION_REF' => 2, 
    'DOUBLE_REF' => 3, 
    'NOT_FOUND' => 4,
    'IS_REDIRECT' => 5,
    'UNKNOWN_WIKI' => 6,
    'URLDECODE_ERROR' => 7,
    'COMMONS' => 8
);

# status in WP_LANG_TABLE
$st_lang = array(
    'IS_IN_OSM' => 0, 
    'SAME_AS_OSM_NAME' => 1, 
    'TO_CHECK' => 2,
    //'TO_UPDATE' => 3,
    'UPDATED' => 4,
    'ALREADY_SET' => 5,
    'PERSONNAME' => 6
);

$osm_tables = array('planet_point', 'planet_line', 'planet_polygon');

// target languages to be added to OSM, array of language codes
$target_langs = array('af', 'am', 'an', 'ar', 'ast', 'az', 'ba', 'bat-smg', 'be', 'be-x-old', 'bg', 'bn', 'bpy', 'br', 'bs', 'bug', 'ca', 'ceb', 'cs', 'cv', 'cy', 'da', 'de', 'diq', 'el', 'en', 'eo', 'es', 'et', 'eu', 'fa', 'fi', 'fr', 'fy', 'ga', 'gl', 'gsw', 'gu', 'he', 'hi', 'hr', 'ht', 'hu', 'hy', 'ia', 'id', 'io', 'is', 'it', 'ja', 'jv', 'ka', 'kk', 'kn', 'ko', 'ku', 'la', 'lb', 'lmo', 'lt', 'lv', 'map-bms', 'mg', 'mk', 'ml', 'mr', 'ms', 'my', 'nap', 'nds', 'ne', 'new', 'nl', 'nn', 'no', 'oc', 'pl', 'pms', 'pnb', 'pt', 'qu', 'ro', 'roa-rup', 'ru', 'ru-sip', 'scn', 'sh', 'sk', 'sl', 'sq', 'sr', 'su', 'sv', 'sw', 'ta', 'te', 'th', 'tl', 'tr', 'tt', 'uk', 'ur', 'vi', 'vo', 'wa', 'war', 'yo', 'zh', 'zh-yue');

//functions

//
function shutdown() { 
    $log = new Logging(); 
    $error_arr = error_get_last(); 
    if($error_arr==null) {  
        //$log->lwrite( 'Normal shutdown.' );
    } else { 
        $log->lwrite( 'Error: '. $error_arr['message'] );
        print_r($error_arr); 
    }
    
}

//select Wikipedia db
function select_wiki_db($wiki) {
    //connect to Wikipedia db in MySQL
    $toolserver_mycnf = parse_ini_file("/home/".get_current_user()."/.my.cnf");
    $w_db_host = $wiki. 'wiki-p.db.toolserver.org';
    $w_db_name = $wiki. 'wiki_p';
    $db = mysql_connect($w_db_host, $toolserver_mycnf['user'], $toolserver_mycnf['password']); 
    if (!$db) {
        die('Could not connect: ' . mysql_error());
    }
    $db_selected = mysql_select_db($w_db_name, $db);
    unset($toolserver_mycnf);
    
    return $db_selected;
} //func

/*
function strip_wp_name ($def) {

    $name = '';
    if (preg_match("/^'''(.+?)'''/", $def, $matches) ) {
        $name = $matches[1];
    }
    
    return $name;
} //func
*/

function strip_wp_title ($title, $lang) {
    $name = '';
    $title = str_replace('_', ' ', $title);
    //disambiguation part in titles ()
    $title = preg_replace('/\s\(.+\)/', '', $title);
    //in en.wp after comma
    if (strcmp($lang, 'en') == 0) {
        $title = preg_replace('/\,\s.+/', '', $title);
    }
    
    return $title;
} //func

?>