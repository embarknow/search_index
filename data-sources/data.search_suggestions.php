<?php
	
	require_once(EXTENSIONS . '/search_index/lib/class.search_index.php');
	require_once(TOOLKIT . '/class.datasource.php');
	require_once(TOOLKIT . '/class.fieldmanager.php');
	require_once(TOOLKIT . '/class.entrymanager.php');
	
	Class datasourcesearch_suggestions extends Datasource{
		
		public $dsParamROOTELEMENT = 'search-suggestions';
		public $dsParamLIMIT = '1';
		public $dsParamSTARTPAGE = '1';
		
		public function __construct(&$parent, $env=NULL, $process_params=true){
			parent::__construct($parent, $env, $process_params);
		}
		
		public static function sortFrequencyDesc($a, $b) {
			return $a <= $b;
		}
		
		public function about(){
			return array(
					'name' => 'Search Index Suggestions',
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
		
		public function grab(&$param_pool) {
			
			$result = new XMLElement($this->dsParamROOTELEMENT);
			
		// Set up keywords
		/*-----------------------------------------------------------------------*/	
			
			$keywords = (string)$_GET['keywords'];
			if(strlen($keywords) <= 2) return $result;
			
			// parse keywords into words and phrases
			// sanitises and removes duplicates
			$keywords_raw = $keywords;
			$keywords = array();
			foreach(SearchIndex::parseKeywordString($keywords_raw, FALSE)->phrases as $phrase) {
				$keywords[] = $phrase->{'phrase'};
			}
			$keywords = implode(' ', $keywords);
			
			$sort = (string)$_GET['sort'];
			if($sort == '' || $sort == 'alphabetical') {
				$sort = '`keyword` ASC';
			} elseif($sort == 'frequency') {
				$sort = '`frequency` DESC';
			}
			
			
		// Set up sections
		/*-----------------------------------------------------------------------*/	
		
			if(isset($_GET['sections'])) {
				$param_sections = $_GET['sections'];
				// allow sections to be sent as an array if the user wishes (multi-select or checkboxes)
				if(is_array($param_sections)) implode(',', $param_sections);
			} else {
				$param_sections = '';
			}
			
			$sections = array();
			foreach(array_map('trim', explode(',', $param_sections)) as $handle) {
				$section = Symphony::Database()->fetchRow(0,
					sprintf(
						"SELECT `id`, `name` FROM `tbl_sections` WHERE handle = '%s' LIMIT 1",
						Symphony::Database()->cleanValue($handle)
					)
				);
				if ($section) $sections[$section['id']] = array('handle' => $handle, 'name' => $section['name']);
			}
		
		// Build SQL
		/*-----------------------------------------------------------------------*/	
			
			// individual keywords
			$sql_indexed_words = sprintf(
				"SELECT
					`keywords`.`keyword`,
					SUM(`entry_keywords`.`frequency`) AS `frequency`
				FROM
					`tbl_search_index_keywords` AS `keywords`
					INNER JOIN `tbl_search_index_entry_keywords` AS `entry_keywords` ON (`keywords`.`id` = `entry_keywords`.`keyword_id`)
					INNER JOIN `sym_entries` AS `entry` ON (`entry_keywords`.`entry_id` = `entry`.`id`)
				WHERE
					`keywords`.`keyword` LIKE '%s'
					%s
				GROUP BY `keywords`.`keyword`
				ORDER BY %s
				LIMIT 0, 25",
				Symphony::Database()->cleanValue($keywords) . '%',
				(count($sections) > 0) ? sprintf('AND `entry`.section_id IN (%s)', implode(',', array_keys($sections))) : NULL,
				$sort
			);
			
			$sql_indexed_phrases = sprintf(
				"SELECT
					SUBSTRING_INDEX(
						SUBSTRING(CONVERT(LOWER(`data`) USING utf8), LOCATE('%1\$s', CONVERT(LOWER(`data`) USING utf8))),
						' ',
						%2\$d
					) as `keyword`,
					COUNT(id) as `frequency`
				FROM
					tbl_search_index_data
				WHERE
					LOWER(`data`) LIKE '%3\$s'
					OR LOWER(`data_normalised`) LIKE '%3\$s'
					%4\$s
				GROUP BY
					`keyword`
				ORDER BY
					`frequency` DESC,
					`keyword` ASC
				LIMIT
					0, 25",
				' ' . Symphony::Database()->cleanValue($keywords),
				substr_count(trim($keywords).' ', ' ') + 1,
				'%' . Symphony::Database()->cleanValue($keywords) . '%',
				(count($sections) > 0) ? sprintf('AND `section_id` IN (%s)', implode(',', array_keys($sections))) : NULL
			);
			//echo $sql_indexed_phrases;die;
			
			$sql_indexed_phrases_longer = sprintf(
				"SELECT
					SUBSTRING_INDEX(
						SUBSTRING(CONVERT(LOWER(`data`) USING utf8), LOCATE('%1\$s', CONVERT(LOWER(`data`) USING utf8))),
						' ',
						%2\$d
					) as `keyword`,
					COUNT(id) as `frequency`
				FROM
					tbl_search_index_data
				WHERE
					LOWER(`data`) LIKE '%3\$s'
					OR LOWER(`data_normalised`) LIKE '%3\$s'
					%4\$s
				GROUP BY
					`keyword`
				ORDER BY
					`frequency` DESC,
					`keyword` ASC
				LIMIT
					0, 25",
				' ' . Symphony::Database()->cleanValue($keywords),
				substr_count(trim($keywords).' ', ' ') + 2,
				'%' . Symphony::Database()->cleanValue($keywords) . '%',
				(count($sections) > 0) ? sprintf('AND `section_id` IN (%s)', implode(',', array_keys($sections))) : NULL
			);
			//echo $sql_indexed_phrases_longer;die;
			
			$section_handles = array_map('reset', array_values($sections));
			natsort($section_handles);
			
			$sql_logged_phrases = sprintf(
				"SELECT
					SUBSTRING_INDEX(
						SUBSTRING(CONVERT(LOWER(`keywords`) USING utf8), LOCATE('%1\$s', CONVERT(LOWER(`keywords`) USING utf8))),
						' ',
						%2\$d
					) as `keyword`,
					COUNT(id) as `frequency`
				FROM
					tbl_search_index_logs
				WHERE
					LOWER(`keywords`) LIKE '%3\$s'
					%4\$s
					AND `results` > 0
				GROUP BY
					`keyword`
				ORDER BY
					`frequency` DESC,
					`keyword` ASC
				LIMIT
					0, 25",
				Symphony::Database()->cleanValue($keywords),
				((substr_count($keywords, ' ')) >= 4) ? 5 : substr_count($keywords, ' ') + 1,
				Symphony::Database()->cleanValue($keywords) . '%',
				(count($sections) > 0) ? sprintf("AND CONCAT(',', `sections`, ',') LIKE '%s'", '%,' . implode(',',$section_handles) . ',%') : NULL
			);
			
			//echo $sql_logged_phrases;die;

		
		// Run!
		/*-----------------------------------------------------------------------*/
			
			$indexed_words = Symphony::Database()->fetch($sql_indexed_words);
			$indexed_phrases = Symphony::Database()->fetch($sql_indexed_phrases);
			$indexed_phrases_longer = Symphony::Database()->fetch($sql_indexed_phrases_longer);
			$logged_phrases = Symphony::Database()->fetch($sql_logged_phrases);
			
			$terms = array();
			foreach($indexed_words as $term) {
				$keyword = strtolower(SearchIndex::stripPunctuation($term['keyword']));
				$keyword = trim($keyword);
				$terms[$keyword] = (int)$term['frequency'];
			}
			foreach($indexed_phrases as $term) {
				$keyword = strtolower(SearchIndex::stripPunctuation($term['keyword']));
				$keyword = trim($keyword);
				if(isset($terms[$keyword])) {
					$terms[$keyword] += (int)$term['frequency'];
				} else {
					$terms[$keyword] = (int)$term['frequency'];
				}
			}
			foreach($indexed_phrases_longer as $term) {
				$keyword = strtolower(SearchIndex::stripPunctuation($term['keyword']));
				$keyword = trim($keyword);
				if(isset($terms[$keyword])) {
					$terms[$keyword] += (int)$term['frequency'];
				} else {
					$terms[$keyword] = (int)$term['frequency'];
				}
			}
			
			foreach($logged_phrases as $term) {
				$keyword = strtolower(SearchIndex::stripPunctuation($term['keyword']));
				$keyword = trim($keyword);
				$keyword = stripslashes($keyword);
				if(isset($terms[$keyword])) {
					$terms[$keyword] += (int)$term['frequency'];
				} else {
					$terms[$keyword] = (int)$term['frequency'];
				}
				// from search logs given heavier weighting
				$terms[$keyword] = $terms[$keyword] * 3;
				//$terms['___SUGGESTION___' . $keyword] = $terms[$keyword] * 3;
				//unset($terms[$keyword]);
			}
			
			// sort most frequent/popular first
			uasort($terms, array('datasourcesearch_suggestions', 'sortFrequencyDesc'));
			
			// remove similar terms, where one term is just one character different from another
			$remove_terms = array();
			foreach($terms as $term => $i) {
				$remove = FALSE;
				foreach($terms as $t => $j) {
					if(in_array($term, $remove_terms) || in_array($t, $remove_terms)) continue;
					if(levenshtein($term, $t) == 1) $remove_terms[] = $term;
				}
			}
			//foreach($remove_terms as $term) unset($terms[$term]);
			
			$i = 0;
			foreach($terms as $term => $frequency) {
				if($i > 25) continue;
				
				$words = explode(' ', $term);
				$last_word = end($words);
				
				if(SearchIndex::isStopWord($last_word)) {
					continue;
				}
				if(SearchIndex::strlen($last_word) >= (int)Symphony::Configuration()->get('max-word-length', 'search_index') || SearchIndex::strlen($last_word) < (int)Symphony::Configuration()->get('min-word-length', 'search_index')) {
					continue;
				}
				
				// $is_phrase = FALSE;
				// if(preg_match('/^___SUGGESTION___/', $term)) {
				// 	$term = preg_replace('/^___SUGGESTION___/', '', $term);
				// 	if(str_word_count($term) > 1) $is_phrase = TRUE;
				// }
				
				$result->appendChild(
					new XMLElement(
						'word',
						General::sanitize($term),
						array(
							'weighting' => $frequency,
							'handle' => Lang::createHandle($term),
							//'phrase' => ($is_phrase) ? 'yes' : 'no'
						)
					)
				);
				
				$i++;
			}
			
			return $result;
	
	}
}