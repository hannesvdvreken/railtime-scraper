<?php
/**
 * Copyright (C) 2012 by iRail vzw/asbl
 *
 * @author Hannes Van De Vreken (hannes aŧ irail.be) 
 * @license AGPLv3
 *
 */

namespace hannesvdvreken\railtime-scraper;

class Helper {

	static function get_stop_names($lang = 'NL')
	{
		$url  = "http://www.railtime.be/mobile/HTML/SearchStationByName.aspx?all=True&l=$lang";
		$curl = new Curl();

		$result = $curl->simple_get($url);

		$regex  = "/sid=(\d*)&amp;l=$lang&amp;s=1\\\"\>(.*?)\<\/a\>/si" ;
		preg_match_all($regex, $result->data, $matches);

		$result = array();
		for ($i = 0; $i < count( reset($matches)); $i++) {
			if (!isset($result[$matches[1][$i]]))
			{	// multiple names possible
				$result[$matches[1][$i]] = array();
			}
			$result[$matches[1][$i]][] = html_entity_decode($matches[2][$i], ENT_HTML5, 'UTF-8'); 
			// 'UTF-8 for php version < 5.4'
		}

		$inverted = array();
		foreach (array_keys($result) as $stop_id) {
			$array_stop_names = $result[$stop_id];
			foreach ($array_stop_names as $stop_name) {
				$inverted[$stop_name] = $stop_id;
			}
		}
		
		return array($result, $inverted);
	}
}
	