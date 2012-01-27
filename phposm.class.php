<?php
error_reporting(E_ALL);
ini_set('display_errors', true);

/* This PHP class was written originally by Stefan de Konink, November 2009.
 * Permission hereby granted to use this class in any code modified or unmodified. 
 */

define('URL', 'http://api.openstreetmap.org/api/0.6/');
define('USERAGENT', 'PHPOSM 0.1');

class osm {
	private static $errno = 0;

	private function curl($url, $data) {
		$s = curl_init(); 
		curl_setopt($s,CURLOPT_URL,URL.$url);
		curl_setopt($s,CURLOPT_USERAGENT,USERAGENT);
		curl_setopt($s,CURLOPT_RETURNTRANSFER,true );
		$result = curl_exec($s);
		self::$errno = curl_getinfo($s,CURLINFO_HTTP_CODE);
		curl_close($s);

		return $result;
	}

	public function getObject($type, $id, $extra = null) {
		if ($id < 1) {
			self::$errno = 500;
			return;
		}

		$url = $type.'/'.$id.($extra==null?'':'/'+$extra);

		return self::curl($url, null);
	}

	public function getNode($id) {
		return self::getObject('node', $id);
	}

	public function getWay($id, $full = false) {
		return self::getObject('way', $id, ($full?'full':''));
	}

	public function getRelation($id, $full = false) {
		return self::getObject('relation', $id, ($full?'full':''));
	}
}

class changeset {
	private static $changesetid = -1;
	private static $changeset;
	private static $document;
	private static $atomic = true;
	private static $atomic_state = '';
	private static $errno;
	private static $counter = -1;
	private static $user;
	private static $password;

	public function changeset($user, $password, $atomic = true) {
		self::$user = $user;
		self::$password = $password;
		self::$atomic = $atomic;
	}

	private function curl($url, $data, $putpost) {
		$tmpfname = tempnam(sys_get_temp_dir(), 'phposm');
		$handle = fopen($tmpfname, 'w');
		$count = fwrite($handle, $data);
		fclose($handle);
		$handle = fopen($tmpfname, 'r');

		$s = curl_init();
		//		curl_setopt($s,CURLOPT_HTTPAUTH,CURLAUTH_ANY);
		curl_setopt($s,CURLOPT_HTTPAUTH,CURLAUTH_BASIC );
		curl_setopt($s,CURLOPT_USERPWD,self::$user.':'.self::$password);
		curl_setopt($s,CURLOPT_URL,URL.$url);
		curl_setopt($s,CURLOPT_RETURNTRANSFER,true );
		if ($putpost === true) {
			curl_setopt($s,CURLOPT_PUT,true);
			curl_setopt($s,CURLOPT_INFILE,$handle);
			curl_setopt($s,CURLOPT_INFILESIZE,$count);
		} else if ($putpost === false) {
			curl_setopt($s,CURLOPT_POST,true);
			curl_setopt($s,CURLOPT_POSTFIELDS,$data);
		} else if ($putpost === null) {
			curl_setopt($s,CURLOPT_CUSTOMREQUEST,'DELETE');
			curl_setopt($s,CURLOPT_POST,true);
			curl_setopt($s,CURLOPT_POSTFIELDS,$data);
		}
		curl_setopt($s,CURLOPT_BINARYTRANSFER,true); 
		curl_setopt($s,CURLOPT_USERAGENT,USERAGENT);
		$result = curl_exec($s);
		self::$errno = curl_getinfo($s,CURLINFO_HTTP_CODE);

		curl_close($s);

		fclose($handle);
		unlink($tmpfname);

		return $result;
	}

	private function tags($tags) {
		$output = '';
		if (is_array($tags)) {
			foreach($tags as $tag) {
				$output .= '<tag k="'.$tag['k'].'" v="'.$tag['v'].'" />';
			}
		}
		return $output;
	}

	public function addTag(&$array, $k, $v) {
		$array[] = array('k'=>$k, 'v'=>$v);
	}


	private function nodes($nodes) {
		$output = '';
		if (is_array($nodes)) {
			foreach($nodes as $node) {
				$output .= '<nd ref="'.$node.'" />';
			}
		}
		return $output;
	}

	private function members($members) {
		$output = '';
		if (is_array($members)) {
			foreach($members as $member) {
				$output .= '<member type="'.$member['type'].'" ref="'.$member['ref'].'" role="'.$member['role'].'" />';
			}
		}
		return $output;

	}

	public function getErrno() {
		return self::$errno;
	}

	public function addMember(&$array, $type, $ref) {
		$array[] = array('type'=>$type, 'ref'=>$ref);
	}

	public function goon($changesetid) {
		self::$changesetid = $changesetid;
		self::$atomic = false;
	}

	public function create($document = null) {
		if (self::$changesetid < 0) {
			self::$changeset = $document;
			$url = 'changeset/create';

			return self::$changesetid = self::curl($url, $document, true);
		}
		return null;
	}	

	public function simpleCreate($tags) {
		return self::create('<osm><changeset>'.self::tags($tags).'</changeset></osm>');
	}



	public function diffUpload($document) {
		if (self::$changesetid > 0) {
			$url = 'changeset/'.self::$changesetid.'/upload';
			return self::curl($url, $document, false);
		}
	}

	public function close() {
		if (self::$changesetid > 0) {
			if ((self::$atomic === true) && (self::$atomic_state != '')) {
				self::$document = '<osmChange version="0.3" generator="'.USERAGENT.'">'.self::$document.'</'.self::$atomic_state.'></osmChange>';
				self::diffUpload(self::$document);
			}
			$url = 'changeset/'.self::$changesetid.'/close';
			self::$changesetid = self::curl($url, self::$changeset, true);
			return self::$changesetid;
		}
		return null;
	}

	private function createObject($type, $document) {
		if (self::$changesetid > 0 && self::$atomic === false) {
			$url = $type.'/create';
			return self::curl($url, '<osm>'.$document.'</osm>', true);
		} else if (self::$atomic === true) {
			if (self::$atomic_state != '') {
				if (self::$atomic_state != 'create') {
					self::$document .= '</'.self::$atomic_state.'><create version="0.3" generator="'.USERAGENT.'">';
				}
			} else {
				self::$document .= '<create version="0.3" generator="'.USERAGENT.'">';
			}
			self::$atomic_state = 'create';
			self::$document .= $document;
			return self::$counter--;
		}

		return null;
	}


	public function createNode($document) {
		return self::createObject('node', $document);
	}

	public function createRelation($document) {
		return self::createObject('relation', $document);
	}

	public function createWay($document) {
		return self::createObject('way', $document);
	}

	public function simpleNodeCreate($lat, $lon, $tags) {
		if (!is_double($lat) || !is_double($lon) || $lat > 90.0 || $lon > 180.0 || $lat < -90.0 || $lon < -180.0) return false; 
		return self::createNode('<node changeset="'.self::$changesetid.'" id="'.self::$counter.'" lat="'.$lat.'" lon="'.$lon.'">'.self::tags($tags).'</node>');
	}

	public function simpleWayCreate($nodes, $tags) {
		return self::createWay('<way changeset="'.self::$changesetid.'" id="'.self::$counter.'">'.self::nodes($nodes).self::tags($tags).'</way>');
	}

	public function simpleRelationCreate($members, $tags) {
		return self::createRelation('<relation changeset="'.self::$changesetid.'" id="'.self::$counter.'">'.self::members($members).self::tags($tags).'</relation>');
	}
	public function modifyObject($type, $id, $document) {
		if (self::$changesetid > 0 && self::$atomic === false) {
			$url = $type.'/'.$id;
			return self::curl($url, '<osm>'.$document.'</osm>', true);
		} else 	if (self::$atomic === true) {
			if (self::$atomic_state != '') {
				if (self::$atomic_state != 'modify') {
					self::$document .= '</'.self::$atomic_state.'><modify version="0.3" generator="'.USERAGENT.'">';
				}
			} else {
				self::$document .= '<modify version="0.3" generator="'.USERAGENT.'">';
			}
			self::$atomic_state = 'modify';
			self::$document .= $document;
		}

		return null;
	}

	public function modifyNode($id, $document) {
		return self::modifyObject('node', $id, $document);
	}

	public function modifyRelation($id, $document) {
		return self::modifyObject('relation', $id, $document);
	}

	public function modifyWay($id, $document) {
		return self::modifyObject('way', $id, $document);
	}


	private function deleteObject($type, $id, $document) {
		if (self::$changesetid > 0 && self::$atomic === false) {
			$url = $type.'/'.$id;
			return self::curl($url, '<osm>'.$document.'</osm>', null);
		} else if (self::$atomic === true) {
			if (self::$atomic_state != '') {
				if (self::$atomic_state != 'delete') {
					self::$document .= '</'.self::$atomic_state.'><delete version="0.3" generator="'.USERAGENT.'">';
				}
			} else {
				self::$document .= '<delete version="0.3" generator="'.USERAGENT.'">';
			}
			self::$atomic_state = 'delete';
			self::$document .= $document;
		}

		return null;
	}

	public function deleteNode($id, $document) {
		return self::deleteObject('node', $id, $document);
	}

	public function deleteRelation($id, $document) {
		return self::deleteObject('relation', $id, $document);
	}

	public function deleteWay($id, $document) {
		return self::deleteObject('way', $id, $document);
	}
}
?>
