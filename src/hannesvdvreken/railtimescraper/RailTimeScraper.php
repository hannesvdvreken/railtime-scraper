<?php
/**
 * Copyright (C) 2012 by iRail vzw/asbl
 *
 * @author Hannes Van De Vreken (hannes aŧ irail.be) 
 * @license AGPLv3
 *
 */

namespace hannesvdvreken\railtimescraper;

/**
 * Custom railtime scraper.
 * - get_stop() returns all stopping vehicles at given stop for given day
 * - get_trip() returns a full schedule for a given trip on a given day
 */
class RailTimeScraper
{
	private $tz = 'CET';
	private $cache;
	private $stop_names = NULL;
	private $inverted_stop_names = NULL;

	public function __construct()
	{
		$this->cache = \tdt\cache\Cache::getInstance(['system'=>'MemCache']);
		list($this->stop_names, $this->inverted_stop_names) = $this->cache->get('stop_names');
		if( !$this->stop_names ){
			list($this->stop_names, $this->inverted_stop_names) = Helper::get_stop_names(); // external helper function
			$this->cache->set('stop_names', [$this->stop_names,$this->inverted_stop_names], FALSE, 60*60*24);
		}
	}

	/**
	 * @param $sid: stop_id defined by railtime 
	 * @param $date: date in YYYYMMDD
	 *
	 * involves curl requests
	 * for every 15 minutes in the given day
	 * times two: departure times and arrival times
	 */
	public function get_stop( $sid, $date )
	{
		/* set timezone */
		$temp_tz = date_default_timezone_get();
		date_default_timezone_set($this->tz);

		/* validate stop_id */
		if (!array_key_exists((string)$sid, $this->stop_names))
		{
			return FALSE;
		}
		/* get stop name (for railtime requests) */
		$sn = $this->stop_names[$sid][0];
		$service_stops = [];
		$directions = ['D','A'];

		/* set time of the day to zero */
		$d = date_parse_from_format ( 'Ymd' , $date );
		$iso_timezone_suffix = substr( date('c'), 19 );
		$date = sprintf( "%04d-%02d-%02dT00:00:00$iso_timezone_suffix", $d['year'], $d['month'], $d['day'] );
		unset($d);

		/* loop all 15 mins intervals
		   from 4:15 till 01:15 */
		$max = date('c', strtotime($date . ' + '. '25' .' hours + '. '15' .' minutes'));

		foreach ($directions as $direction)
		{
			$overwrite_key = $direction=='D'?'departure_time':'arrival_time';

			$curr_date = $date;
			$Hcoloni = '04:15'; # H:i (string format)
			$d = preg_replace('/T00:00/', "T$Hcoloni", $curr_date);

			while ($d < $max)
			{
				$result = $this->get_stop_by_time($sid,$sn,$d,$direction);
				if ($result === FALSE) { return FALSE; }
				if ($result['end_time'] < $Hcoloni ) {
					$curr_date = date('c', strtotime($curr_date . ' + '. '24' .' hours'));
				}

				$Hcoloni = $result['end_time'];
				$d = preg_replace('/T00:00/', "T$Hcoloni", $curr_date);
				$result = &$result['service_stops'];

				foreach (array_keys($result) as $service_stop_id)
				{
					if ( array_key_exists($service_stop_id, $service_stops) ){
						$service_stops[$service_stop_id][$overwrite_key] =
							$result[$service_stop_id][$overwrite_key];
					} else {
						$service_stops[$service_stop_id] = 
							$result[$service_stop_id];
					}
				}
			}
		}

		/* reset timezone */
		date_default_timezone_set($temp_tz);

		return array_values($service_stops);
	}

	/**
	 * @param $tid: vehicle_id defined by railtime 
	 * @param $date: date in yyyymmdd
	 * 
	 * involves 2 curl requests:
	 * one for departure times, one for arrival times
	 */
	public function get_trip( $tid, $date )
	{
		/* get the departure and arrival times */
		$direction = 'D';
		$service_stops = $this->get_trip_in_direction($tid, $date, $direction);
		$direction = 'A';
		$service_stops_arr = $this->get_trip_in_direction($tid, $date, $direction);

		if ($service_stops === FALSE || $service_stops_arr === FALSE){
			return FALSE;
		}

		/* combine the two output arrays*/
		foreach ( array_keys($service_stops_arr) as $service_stop_id )
		{
			if( $service_stops_arr[$service_stop_id]['arrival_time'] ){
				$service_stops[$service_stop_id]['arrival_time'] = 
					$service_stops_arr[$service_stop_id]['arrival_time'];
				$service_stops[$service_stop_id]['arrival_delay'] = 
					$service_stops_arr[$service_stop_id]['arrival_delay'];
			}
		}

		return array_values($service_stops);
	}

	/**
	 * @param $tid: trip_id defined by railtime
	 * @param $date: time in ISO 8601
	 * @param $da: enum('D','A') (Departure / Arrival)
	 *
	 * involves one single curl request
	 * usually invoked by get_trip($tid,$date)
	 */
	private function get_trip_in_direction( $tid, $date, $da )
	{
		$url  = 'http://www.railtime.be/mobile/HTML/TrainDetail.aspx';
		
		/* add parameters */
		$params = [];
		$params['l']   = 'NL' ;
		$params['dt']  = date('d/m/Y', strtotime($date));
		$params['tid'] = $tid ;
		$params['da']  = $da  ; // enum('D','A') departure or arrival
		
		/* build url & get data */
		$url .= '?' . http_build_query($params) ;
		$curl = new \Curl();
		$result = $curl->simple_get($url);
		if (!is_null($curl->error_code)) {
			$curl->setDefaults();
			return FALSE;
		}

		if (preg_match('/Deze\ gegevens\ zijn\ niet\ langer\ beschikbaar\./', $result))
		{
			/* out of reach for RailTime */
			return [];
		}
		if (preg_match('/Deze\ trein\ bestaat\ niet\ voor\ de\ gevraagde\ datum\./', $result))
		{
			/* no train data for this id on this date */
			return [];
		}
		
		$matches = [];
		preg_match_all('/\[([A-Z,\ ]*[A-Z,]*)?\ *\d+\]/si', $result, $matches ); 
		/* optional type: if cancelled, railtime drops train type */
		
		if( $matches[1][0] != ""){
			$type = $matches[1][0] ;
		}
		/* parse to array of stops */
		$matches = [];
		preg_match_all('/\<tr\ class.+?\>(.+?)\<\/tr\>/si', $result, $matches );
		
		$stops = [];
		/* process array of stops */
		foreach( $matches[1] as &$stop ){
			$regex = '/\<label.*?\>(.*?)\<\/label\>/' ;
			$matches2 = [];
			preg_match_all($regex, $stop, $matches2 );
			
			/* get station name and if possible station id */
			if( count($matches2[1]) == 3){
				$sid = FALSE ;
				$stop_name = html_entity_decode( array_shift( $matches2[1] ), NULL, "UTF-8"); // making code compatible for php version < 5.4.0
				/* TODO handle this: get station country and company by name */
			}else{
				$matches3 = [];
				$regex = '/\<a.*?&amp;sid=(\d+?)&.*?\>(.*?)\<\/a\>/' ;
				preg_match_all( $regex, $stop, $matches3 );
				$stop_name = reset($matches3[2]);
				$sid = reset($matches3[1]) ;
			}
			
			/* get some more info about the timing */
			$cancelled = FALSE ;
			if( preg_match('/TrainDeleted/', $stop)){ // <label class="CenterTrainDeleted">*stop_name*</label>
				$cancelled = TRUE ;
			}else{
				list( $hour, $minutes ) = explode(':',$matches2[1][0]);
				$hour	= intval($hour);
				$minutes = intval($minutes);
				
				$time = mktime( $hour, $minutes, 0, date('m',strtotime($date)),date('d',strtotime($date)),date('Y',strtotime($date)) );
			}
			
			/* fill in the result array */
			$s = [];
			
			if ( $cancelled ){
				/* in the case a trip isn't reaching a stop, no time nor delay has been given. */
				$s['cancelled'] = 1 ;
			} else {
				if ( $da == 'A'){
					$s['arrival_delay'] = (intval($matches2[1][1]) * 60) ;
					$s['arrival_time'] = date('c',$time);
				} else {
					$s['departure_delay'] = (intval($matches2[1][1]) * 60) ;
					$s['departure_time'] = date('c',$time);
				}
			}
			
			$s['sequence'] = (count($stops) + 1);
			$s['stop'] = $stop_name;
			if ($sid == FALSE) {
				if (!array_key_exists($stop_name, $this->inverted_stop_names) ){
					return FALSE;
				}else{
					$sid = $this->inverted_stop_names[$stop_name];
				}
			}
			$s['sid'] = $sid;
			$s['tid'] = $tid;
			$s['date'] = date('Ymd', strtotime($date));
			$s['type'] = trim($type);
			$s['agency'] = 'NMBS-SNCB';

			$stops[$sid] = $s;
			
			if ($stop == end($matches[1]) ){
				$headsign = $s['stop'];
				unset($s);

				foreach ( $stops as &$s ) {
					$s['headsign'] = $headsign;
				}
			}
		}
		
		return $stops ;
	}

	/**
	 * @param $sid: stop_id defined by railtime
	 * @param $sn: according to $sid
	 * @param $time: time in ISO 8601
	 * @param $da: enum('D','A') (Departure / Arrival)
	 *
	 * involves one single curl request
	 * usually invoked by get_stop($sid,$date)
	 */
	private function get_stop_by_time( $sid, $sn, $time, $da )
	{
		if ( $da != 'D' && $da != 'A' )
		{
			return FALSE;
		}

		$url  = 'http://www.railtime.be/mobile/HTML/StationDetail.aspx';

		$dt = Date('d/m/Y',strtotime($time));
		$ti = Date('H:i',strtotime($time));

		/* add parameters */
		$params = [];
		$params['l']   = 'NL' ;
		$params['sid'] = $sid ; // station id
		$params['sn']  = $sn  ; // station name
		$params['dt']  = $dt  ; // date
		$params['ti']  = $ti  ; // time
		$params['da']  = $da  ; // enum('D','A') departure or arrival
		
		/* build url & get data */
		$url .= '?' . http_build_query($params) ;
		$curl = new \Curl();
		$result = $curl->simple_get($url);
		if (!is_null($curl->error_code)) {
			$curl->setDefaults();
			return FALSE;
		}
		
		$time_match = [];
		preg_match_all('/\(\d\d:\d\d\ -\ (\d\d:\d\d)\)/si', $result, $time_match );
		$end_time = $time_match[1][0];

		/* parse data */
		$matches1 = [];
		preg_match_all('/\<tr\ class.+?\>(.+?)\<\/tr\>/si', $result, $matches1 );
		
		$service_stops = [];
		
		foreach (@end($matches1) as $vehicle){
			$v = [];
			/* do some parsing */
			$matches2 = [];
			preg_match_all('/\<label.*?\>(.*?)\<\/label\>/si', $vehicle, $matches2 );
			$matches3 = [];
			preg_match_all('/&amp;tid=(\d+)&amp;/si', $vehicle, $matches3 );
			
			/* fill in the variables */
			$vars = @$matches2[1] ;
			$tid = reset(@end($matches3));

			list( $hour, $minutes ) = explode(':',$vars[0]);
			$planned = Date('c', mktime(intval($hour), intval($minutes), 0,  
										date('m',strtotime($time)),
										date('d',strtotime($time)),
										date('Y',strtotime($time))));
			
			$v['type'] = substr( $vars[3], 1, -1 );
			$v['headsign'] = html_entity_decode($vars[2], ENT_HTML5, "UTF-8");
			if (!preg_match('/\*\*\*/', $vars[5])) {
				$v['platform'] = trim($vars[5]);
			}
			
			/* Don't drop this data because it is used for displaying 
			   an entry at the right spot even if the vehicle is cancelled */
			if ($da == 'D'){
				$v['departure_time'] = date('c', strtotime($planned)) ;
			} else {
				$v['arrival_time'] = date('c', strtotime($planned)) ;
			}
			$v['tid'] = $tid ;
			$v['sid'] = $sid ;
			$v['agency'] = 'NMBS-SNCB';
			$service_stops[$tid] = $v ;
		}

		return ['end_time' => $end_time, 'service_stops' => $service_stops];
	}
}