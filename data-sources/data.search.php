<?php
	
	require_once(EXTENSIONS . '/search_index/lib/class.search_index.php');
	require_once(TOOLKIT . '/class.datasource.php');
	require_once(TOOLKIT . '/class.fieldmanager.php');
	
	Class datasourcesearch extends Datasource{
		
		public $dsParamROOTELEMENT = 'search';
		public $dsParamLIMIT = '20';
		public $dsParamSTARTPAGE = '1';
		public $log = TRUE;
		
		public function __construct(&$parent, $env=NULL, $process_params=true){
			parent::__construct($parent, $env, $process_params);
		}
		
		public function about(){
			return array(
					'name' => 'Search Index',
					'author' => array(
							'name' => 'Nick Dunn',
							'website' => 'http://nick-dunn.co.uk'
						)
					);	
		}
		
		public function getSource(){
			return NULL;
		}
		
		public function allowEditorToParse(){
			return FALSE;
		}
		
		private function errorXML($message) {
			$result = new XMLElement($this->dsParamROOTELEMENT);
			$result->appendChild(new XMLElement('error', $message));
			return $result;
		}
		
		public function grab(&$param_pool) {
			$result = new XMLElement($this->dsParamROOTELEMENT);
			$param_output = array();
			
			$get = $_GET;
			// look for key in GET array if it's specified
			if (Symphony::Configuration()->get('get-param-prefix', 'search_index') != '') {
				$get = $get[Symphony::Configuration()->get('get-param-prefix', 'search_index')];
			}
			
			$param_keywords = Symphony::Configuration()->get('get-param-keywords', 'search_index');
			$param_per_page = Symphony::Configuration()->get('get-param-per-page', 'search_index');
			$param_sort = Symphony::Configuration()->get('get-param-sort', 'search_index');
			$param_direction = Symphony::Configuration()->get('get-param-direction', 'search_index');
			$param_sections = Symphony::Configuration()->get('get-param-sections', 'search_index');
			$param_page = Symphony::Configuration()->get('get-param-page', 'search_index');
			
			$keywords = $get[$param_keywords];
			$this->dsParamLIMIT = (isset($get[$param_per_page]) && (int)$get[$param_per_page] > 0) ? (int)$get[$param_per_page] : $this->dsParamLIMIT;
			$sort = isset($get[$param_sort]) ? $get[$param_sort] : 'score';			
			$direction = isset($get[$param_direction]) ? strtolower($get[$param_direction]) : 'desc';
			$sections = isset($get[$param_sections]) ? $get[$param_sections] : NULL;
			
			if ($sections == NULL && Symphony::Configuration()->get('default-sections', 'search_index') != '') {
				$sections = Symphony::Configuration()->get('default-sections', 'search_index');
			}
			
			$this->dsParamSTARTPAGE = isset($get[$param_page]) ? (int)$get[$param_page] : $this->dsParamSTARTPAGE;
			
			if (is_null($sections)) {
				
				return $this->errorXML('Invalid search sections');
				
			} else {
				
				$section_handles = explode(',', $sections);
				$sections = array();
				
				foreach($section_handles as $handle) {
					$section = Symphony::Database()->fetchRow(0,
						sprintf(
							"SELECT `id`, `name` FROM `tbl_sections` WHERE handle = '%s' LIMIT 1",
							Symphony::Database()->cleanValue($handle)
						)
					);
					if ($section) $sections[$section['id']] = array('handle' => $handle, 'name' => $section['name']);
				}
				
				if (count($sections) == 0) return $this->errorXML('Invalid search sections');
				
			}
			
			if ($sort == 'date') {
				$order_by = "e.creation_date $direction";
			}			
			else if ($sort == 'id') {
				$order_by = "e.id $direction";
			}			
			else {
				$order_by = "score $direction";
			}
			
			$weighting = '';
			$indexed_sections = SearchIndex::getIndexes();
			//var_dump($indexed_sections);die;
			foreach($indexed_sections as $section_id => $index) {
				$weight = is_null($index['weighting']) ? 2 : $index['weighting'];
				switch ($weight) {
					case 0: $weight = 4; break; // highest
					case 1: $weight = 2; break; // high
					//case 2: $weight = 1; break; // none
					case 3: $weight = 0.5; break; // low
					case 4: $weight = 0.25; break; // lowest
				}
				if ($weight != 1) $weighting .= sprintf("WHEN e.section_id = %d THEN %d \n", $section_id, $weight);
			}
			
			$sql = sprintf(
				"SELECT 
					SQL_CALC_FOUND_ROWS 
					e.id as `entry_id`,
					data,
					e.section_id as `section_id`,
					UNIX_TIMESTAMP(e.creation_date) AS `creation_date`,
					(
						MATCH(index.data) AGAINST ('%1\$s') * 
						CASE
							%2\$s
							ELSE 1
						END
						%3\$s						
					) AS `score`
				FROM
					sym_search_index as `index`
					JOIN sym_entries as `e` ON (index.entry_id = e.id)
				WHERE
					MATCH(index.data) AGAINST ('%4\$s' IN BOOLEAN MODE)
					AND e.section_id IN ('%5\$s')
				ORDER BY
					%6\$s
				LIMIT %7\$d, %8\$d",
				
				// keywords				
				Symphony::Database()->cleanValue($keywords),
				$weighting,
				($sort == 'score-recency') ? '/ SQRT(GREATEST(1, DATEDIFF(NOW(), creation_date)))' : '',
				Symphony::Database()->cleanValue(SearchIndex::manipulateKeywords($keywords)),
				
				// list of section IDs
				implode("','", array_keys($sections)),
				
				// order by
				Symphony::Database()->cleanValue($order_by),
				
				// limit start
				max(0, ($this->dsParamSTARTPAGE - 1) * $this->dsParamLIMIT),
				
				// limit
				(int)$this->dsParamLIMIT
			);
			
			//echo $sql;die;
			
			$result->setAttributeArray(
				array(
					'keywords' => General::sanitize($keywords),
					'sort' => $sort,
					'direction' => $direction,
				)
			);
			
			// get our entries!
			$entries = Symphony::Database()->fetch($sql);
			$total_entries = Symphony::Database()->fetchVar('total', 0, 'SELECT FOUND_ROWS() AS `total`');
			
			$result->appendChild(
				General::buildPaginationElement(
					$total_entries,
					ceil($total_entries * (1 / $this->dsParamLIMIT)),
					$this->dsParamLIMIT,
					$this->dsParamSTARTPAGE
				)
			);
			
			$sections_xml = new XMLElement('sections');
			
			foreach($sections as $id => $section) {
				$sections_xml->appendChild(
					new XMLElement(
						'section',
						General::sanitize($section['name']),
						array(
							'id' => $id,
							'handle' => $section['handle']
						)
					)
				);
			}
			$result->appendChild($sections_xml);
						
			foreach($entries as $entry) {
				
				$param_output[] = $entry['entry_id'];
				
				$result->appendChild(
					new XMLElement(
						'entry',
						General::sanitize(
							self::parseExcerpt($keywords, $entry['data'])
						),
						array(
							'id' => $entry['entry_id'],
							'section' => $sections[$entry['section_id']]['handle']
						)
					)
				);
			}
			
			// send entry IDs as Output Parameterss
			$param_pool['ds-' . $this->dsParamROOTELEMENT] = $param_output;
			
			$log_sql = sprintf(
				"INSERT INTO `tbl_search_index_logs`
				(date, keywords, sections, page, results, session_id)
				VALUES('%s', '%s', '%s', %d, %d, '%s')",
				date('Y-m-d H:i:s', time()),
				Symphony::Database()->cleanValue($keywords),
				Symphony::Database()->cleanValue(implode(',',$section_handles)),
				$this->dsParamSTARTPAGE,
				$total_entries,
				session_id()
			);
			if ($this->log === TRUE) Symphony::Database()->query($log_sql);
		
			return $result;		

		}
	
	private static function parseExcerpt($keywords, $text) {
		
		$text = trim($text);
		$text = preg_replace("/\n/", '', $text);
		
		$string_length = 270;

		// Extract positive keywords and phrases
		preg_match_all('/ ("([^"]+)"|(?!OR)([^" ]+))/', ' '. $keywords, $matches);
		$keywords = array_merge($matches[2], $matches[3]);

		// Prepare text
		$text = ' '. strip_tags(str_replace(array('<', '>'), array(' <', '> '), $text)) .' ';
		array_walk($keywords, '_search_excerpt_replace');
		$workkeys = $keywords;

		// Extract a fragment per keyword for at most 4 keywords.
		// First we collect ranges of text around each keyword, starting/ending
		// at spaces.
		// If the sum of all fragments is too short, we look for second occurrences.
		$ranges = array();
		$included = array();
		$length = 0;
		while ($length < $string_length && count($workkeys)) {
			foreach ($workkeys as $k => $key) {
				if (strlen($key) == 0) {
					unset($workkeys[$k]);
					unset($keywords[$k]);
					continue;
				}
				if ($length >= $string_length) {
					break;
				}
				// Remember occurrence of key so we can skip over it if more occurrences
				// are desired.
				if (!isset($included[$key])) {
					$included[$key] = 0;
				}
				// Locate a keyword (position $p), then locate a space in front (position
				// $q) and behind it (position $s)
				if (preg_match('/'. $boundary . $key . $boundary .'/iu', $text, $match, PREG_OFFSET_CAPTURE, $included[$key])) {
					$p = $match[0][1];
					if (($q = strpos($text, ' ', max(0, $p - 60))) !== FALSE) {
						$end = substr($text, $p, 80);
						if (($s = strrpos($end, ' ')) !== FALSE) {
							$ranges[$q] = $p + $s;
							$length += $p + $s - $q;
							$included[$key] = $p + 1;
						}
						else {
							unset($workkeys[$k]);
						}
					}
					else {
						unset($workkeys[$k]);
					}
				}
				else {
					unset($workkeys[$k]);
				}
			}
		}

		// If we didn't find anything, return the beginning.
		if (count($ranges) == 0) {
			if (strlen($text) > $string_length) {
				return substr($text, 0, $string_length) . '...';
			} else {
				return $text;
			}
		}

		// Sort the text ranges by starting position.
		ksort($ranges);

		// Now we collapse overlapping text ranges into one. The sorting makes it O(n).
		$newranges = array();
		foreach ($ranges as $from2 => $to2) {
			if (!isset($from1)) {
				$from1 = $from2;
				$to1 = $to2;
				continue;
			}
			if ($from2 <= $to1) {
				$to1 = max($to1, $to2);
			}
			else {
				$newranges[$from1] = $to1;
				$from1 = $from2;
				$to1 = $to2;
			}
		}
		$newranges[$from1] = $to1;

		// Fetch text
		$out = array();
		foreach ($newranges as $from => $to) {
			$out[] = substr($text, $from, $to - $from);
		}
		$text = (isset($newranges[0]) ? '' : '...') . implode('...', $out) . '...';

		// Highlight keywords. Must be done at once to prevent conflicts ('strong' and '<strong>').
		$text = preg_replace('/'. $boundary .'('. implode('|', $keywords) .')'. $boundary .'/iu', '<strong>\0</strong>', $text);
		
		$text = trim($text);
		
		return $text;
	}
	
}