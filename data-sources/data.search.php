<?php
	
	require_once(EXTENSIONS . '/search_index/lib/class.search_index.php');
	require_once(TOOLKIT . '/class.datasource.php');
	require_once(TOOLKIT . '/class.fieldmanager.php');
	require_once(TOOLKIT . '/class.entrymanager.php');
	
	Class datasourcesearch extends Datasource{
		
		public $dsParamROOTELEMENT = 'search';
		
		public function __construct(&$parent, $env=NULL, $process_params=true){
			parent::__construct($parent, $env, $process_params);
		}
		
		public static function sortWordDistance($a, $b) {
			return $a['distance'] > $b['distance'];
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
			$config = (object)Symphony::Configuration()->get('search_index');
			
			
		// Setup
		/*-----------------------------------------------------------------------*/	
			
			// get input parameters from GET request
			$param_keywords = trim($this->__processParametersInString($config->{'param-keywords'}, $this->_env));
			
			$param_sort = trim($this->__processParametersInString($config->{'param-sort'}, $this->_env));
			if(empty($param_sort)) $param_sort = $config->{'default-sort'};
			
			$param_direction = trim($this->__processParametersInString($config->{'param-direction'}, $this->_env));
			if(empty($param_direction)) $param_direction = $config->{'default-direction'};
			
			$this->dsParamSTARTPAGE = (int)$this->__processParametersInString($config->{'param-page'}, $this->_env);
			if($this->dsParamSTARTPAGE == 0) $this->dsParamSTARTPAGE = 1;
			
			$this->dsParamLIMIT = (int)$this->__processParametersInString($config->{'param-per-page'}, $this->_env);
			if($this->dsParamLIMIT == 0) $this->dsParamLIMIT = $config->{'default-per-page'};
			
			// build ORDER BY statement for later
			switch($param_sort) {
				case 'date': $sql_order_by = "e.creation_date $param_direction"; break;
				case 'id': $sql_order_by = "e.id $param_direction"; break;
				default: $sql_order_by = "score $param_direction"; break;
			}
			
			$indexes = SearchIndex::getIndexes();
			$indexed_section_ids = array();
			foreach($indexes as $index) $indexed_section_ids[] = $index['section_id'];
		
		
		// Find valid sections to query
		/*-----------------------------------------------------------------------*/
			
			// if the sections param a URL param in the form {$url-something}
			// check if it's an array e.g. something[]=a&something[]=b
			preg_match('@{\$url-([^}]+)}@i', $config->{'param-sections'}, $matches);
			if(isset($matches[1])) $url_param_sections_name = $matches[1];
			if(isset($_GET[$url_param_sections_name]) && is_array($_GET[$url_param_sections_name])) {
				$param_sections = implode(',', $_GET[$url_param_sections_name]);
			}
			// fall back to normal param implementation, page or URL parameters
			elseif($param_sections = $this->__processParametersInString($config->{'param-sections'}, $this->_env)) {
				// normal
			}
			// fall back to the default in config
			elseif(!empty($config->{'default-sections'})) {
				$param_sections = $config->{'default-sections'};
			}
			// fall back to nothing..
			else {
				$param_sections = '';
			}
			
			// the search will be performed on each of these sections individually (result count only)
			$indexed_sections = Symphony::Database()->fetch(
				sprintf(
					"SELECT `id`, `handle`, `name` FROM `tbl_sections` WHERE id IN (%s)",
					implode(',', array_values($indexed_section_ids))
				)
			);
			
			// the search will be performed on selected sections as a single search (to get results)
			$search_sections = array();
			foreach(array_map('trim', explode(',', $param_sections)) as $handle) {
				foreach($indexed_sections as $section) {
					if($handle == $section['handle']) {
						$search_sections[$section['id']] = array('handle' => $handle, 'name' => $section['name']);
					}
				}
			}
			
			if (count($search_sections) == 0) return $this->errorXML('Invalid search sections');
		
		
		
		// Set up and manipulate keywords	
		/*-----------------------------------------------------------------------*/	

			// replace synonyms
			$keywords_raw = $param_keywords;
			$phrases = SearchIndex::parseKeywordString($keywords_raw)->phrases;
			$keywords_synonyms = SearchIndex::applySynonyms($keywords_raw);
			
		
		// Set up weighting
		/*-----------------------------------------------------------------------*/

			$sql_weighting = '';
			foreach($indexes as $index) {
				$weight = isset($index['weighting']) ? $index['weighting'] : 2;
				switch ($weight) {
					case 0: $weight = 4; break;		// highest
					case 1: $weight = 2; break;		// high
					case 2: $weight = 1; break;		// none
					case 3: $weight = 0.5; break;	// low
					case 4: $weight = 0.25; break;	// lowest
				}
				$sql_weighting .= sprintf("WHEN e.section_id = %d THEN %d \n", $index['section_id'], $weight);
			}
		
		
		// Build search SQL
		/*-----------------------------------------------------------------------*/
			
			$mode = !is_null($config->{'mode'}) ? $config->{'mode'} : 'like';
			$mode = strtoupper($mode);
			
			switch($mode) {
				
				case 'FULLTEXT':
				
					$sql_entries = sprintf(
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
							tbl_search_index_data as `index`
							JOIN tbl_entries as `e` ON (index.entry_id = e.id)
						WHERE
							MATCH(index.data) AGAINST ('%4\$s' IN BOOLEAN MODE)
							AND e.section_id IN (%5\$s)
						ORDER BY
							%6\$s
						LIMIT %7\$d, %8\$d",
						Symphony::Database()->cleanValue($keywords_synonyms),
						$sql_weighting,
						($param_sort == 'score-recency') ? '/ SQRT(GREATEST(1, DATEDIFF(NOW(), creation_date)))' : '',
						Symphony::Database()->cleanValue($keywords_synonyms),
						implode(',', array_keys($search_sections)),
						Symphony::Database()->cleanValue($sql_order_by),
						max(0, ($this->dsParamSTARTPAGE - 1) * $this->dsParamLIMIT),
						(int)$this->dsParamLIMIT
					);
					//echo $sql_entries;die;
					
					$sql_count = sprintf(
						"SELECT 
							COUNT(e.id) as `count`
						FROM
							tbl_search_index_data as `index`
							JOIN tbl_entries as `e` ON (index.entry_id = e.id)
						WHERE
							MATCH(index.data) AGAINST ('%1\$s' IN BOOLEAN MODE)
							AND e.section_id IN (__SECTIONS__)",
						Symphony::Database()->cleanValue($keywords_synonyms)
					);
				
				break;
				
				case 'LIKE':
					
					$sql_locate = '';
					$sql_replace = '';
					$sql_where = '';
					
					// all words to include in the query (single words and phrases)
					foreach($phrases as $phrase) {

						// "foo bar!"
						if($phrase->{'is-phrase'}) {
							
							$column = 'data_normalised';
							$keyword = Symphony::Database()->cleanValue($phrase->{'phrase-no-punctuation'});
							
							$sql_where .= '(';
							
							// normal phrase e.g. "foo bar!"
							$sql_where .= sprintf(
								"index.data %1\$s LIKE '%2\$s' OR index.data_normalised %1\$s LIKE '%2\$s' ",
								($phrase->include) ? '' : 'NOT',
								'% ' . Symphony::Database()->cleanValue($phrase->phrase) . ' %'
							);
							
							// phrase without terminating punctuation e.g. "foo bar"
							$sql_where .= sprintf(
								"OR index.data %1\$s LIKE '%2\$s' OR index.data_normalised %1\$s LIKE '%2\$s' ",
								($phrase->include) ? '' : 'NOT',
								'% ' . Symphony::Database()->cleanValue($phrase->{'phrase-no-punctuation'}) . ' %'
							);
							
							$sql_where .= ") AND ";
							
						}
						else {
							
							$sql_where .= '(';
							
							if($phrase->{'use-stem'}) {
								
								$column = 'data_normalised_stops_stemmed';
								$keyword = Symphony::Database()->cleanValue($phrase->{'phrase-stem'});
								
								$sql_where .= sprintf(
									"index.data_normalised_stops_stemmed %1\$s LIKE '%2\$s' ",
									($phrase->include) ? '' : 'NOT',
									'% ' . Symphony::Database()->cleanValue($phrase->{'phrase-stem'}) . (($config->{'partial-words'} == 'no') ? ' ' : '') . '%'
								);
								
							} else {
								
								$column = 'data_normalised_stops';
								$keyword = Symphony::Database()->cleanValue($phrase->{'phrase-no-punctuation'});
								
								$sql_where .= sprintf(
									"index.data_normalised_stops %1\$s LIKE '%2\$s' ",
									($phrase->include) ? '' : 'NOT',
									'% ' . Symphony::Database()->cleanValue($phrase->{'phrase-no-punctuation'}) . (($config->{'partial-words'} == 'no') ? ' ' : '') . '%'
								);
								
							}
							
							$sql_where .= ") AND ";
							
						}
						
						// if this keyword exists in the entry contents, add 1 to "keywords_matched"
						// which represents number of unique keywords in the search string that are found
						$sql_locate .= "IF(LOCATE('$keyword', LOWER(`$column`)) > 0, 1, 0) + ";
						
						// see how many times this word is found in the entry contents by removing it from
						// the column text then compare length to see how many times it was removed
						$sql_replace .= "(LENGTH(`$column`) - LENGTH(REPLACE(LOWER(`$column`),LOWER('$keyword'),''))) / LENGTH('$keyword') + ";
					}
					
					// append to complete SQL
					$sql_locate = ($sql_locate == '') ? $sql_locate = '1' : $sql_locate .= '0';
					$sql_replace = ($sql_replace == '') ? $sql_replace = '1' : $sql_replace .= '0';
					$sql_where = ($sql_where == '') ? $sql_where = 'NOT 1' : $sql_where;
					
					// trim unnecessary boolean conditions from SQL
					$sql_where = preg_replace("/ OR $/", "", $sql_where);
					$sql_where = preg_replace("/ AND $/", "", $sql_where);
					
					$sql_entries = sprintf(
						"SELECT
							SQL_CALC_FOUND_ROWS
							e.id as `entry_id`,
							data,
							e.section_id as `section_id`,
							UNIX_TIMESTAMP(e.creation_date) AS `creation_date`,
							(
								(%1\$s) * 
								(%2\$s) *
								CASE
									%3\$s
									ELSE 1
								END
								%4\$s
							) AS score
						FROM
							tbl_search_index_data as `index`
							JOIN tbl_entries as `e` ON (index.entry_id = e.id)
						WHERE
							%5\$s
							AND e.section_id IN (%6\$s)
						ORDER BY
							%7\$s
						LIMIT
							%8\$d, %9\$d",
						$sql_locate,
						$sql_replace,
						$sql_weighting,
						($param_sort == 'score-recency') ? '/ SQRT(GREATEST(1, DATEDIFF(NOW(), creation_date)))' : '',
						$sql_where,
						implode(',', array_keys($search_sections)),
						Symphony::Database()->cleanValue($sql_order_by),
						max(0, ($this->dsParamSTARTPAGE - 1) * $this->dsParamLIMIT),
						(int)$this->dsParamLIMIT
					);
					//echo $sql_entries;die;
					
					$sql_count = sprintf(
						"SELECT
							COUNT(e.id) as `count`
						FROM
							tbl_search_index_data as `index`
							JOIN tbl_entries as `e` ON (index.entry_id = e.id)
						WHERE
							%1\$s
							AND e.section_id IN (__SECTIONS__)",
						$sql_where
					);
					
					//echo $sql_entries;die;
				
				break;

			}
		
		
		// Add soundalikes ("did you mean?") to XML
		/*-----------------------------------------------------------------------*/
			$soundex_words = array();
			foreach($phrases as $phrase) {
				if($phrase->include) $soundex_words[] = $phrase->{'phrase-no-punctuation'};
			}
			
			// we have search words, check for soundalikes
			if(count($soundex_words) > 0) {
				
				$include_words_all = array();
				foreach($soundex_words as $word) {
					// don't soundalike stop words
					$word = SearchIndex::stripPunctuation($word);
					if(SearchIndex::isStopWord($word)) continue;
					$include_words_all[] = $word;
				}
				$include_words_all = array_unique($include_words_all);
				
				$sounds_like = array();
				
				foreach($include_words_all as $word) {
					
					$word = strtolower($word);
					
					$soundalikes = Symphony::Database()->fetchCol('keyword', sprintf(
						"SELECT keyword FROM tbl_search_index_keywords WHERE SOUNDEX(keyword) = SOUNDEX('%1\$s')
						UNION SELECT keywords as `keyword` FROM tbl_search_index_logs WHERE SOUNDEX(keywords) = SOUNDEX('%1\$s') AND results > 0
						GROUP BY keyword",
						Symphony::Database()->cleanValue($word)
					));
					
					foreach($soundalikes as $i => &$soundalike) {
						$soundalike = strtolower($soundalike);
						
						if($soundalike == $word) {
							unset($soundalikes[$i]);
							continue;
						}
						$soundalike = array(
							'word' => $soundalike,
							'distance' => levenshtein($soundalike, $word)
						);
					}
					usort($soundalikes, array('datasourcesearch', 'sortWordDistance'));
					$sounds_like[$word] = $soundalikes[0]['word'];
				}
				
				// add words to XML
				if(count($sounds_like) > 0) {
					$alternative_spelling = new XMLElement('alternative-keywords');
					foreach($sounds_like as $word => $soundalike) {
						// don't suggest a stop word, it's useless!
						if(SearchIndex::isStopWord($soundalike)) continue;
						$alternative_spelling->appendChild(
							new XMLElement('keyword', NULL, array(
								'original' => $word,
								'alternative' => $soundalike,
								'distance' => levenshtein($soundalike, $word)
							))
						);
					}
					$result->appendChild($alternative_spelling);
				}
				
			}
		
		
		// Run search SQL!
		/*-----------------------------------------------------------------------*/
			
			$t = microtime();
			
			// get our entries, returns entry IDs
			$entries = Symphony::Database()->fetch($sql_entries);
			$total_entries = Symphony::Database()->fetchVar('total', 0, 'SELECT FOUND_ROWS() AS `total`');
			
			// append pagination
			$result->appendChild(
				General::buildPaginationElement(
					$total_entries,
					ceil($total_entries * (1 / $this->dsParamLIMIT)),
					$this->dsParamLIMIT,
					$this->dsParamSTARTPAGE
				)
			);
			
			$index_query_counts = array();
			foreach($indexed_sections as $section) {
				$index_query_counts[] = (int)$section['id'];
			}
			
			// append list of keywords
			
			$keywords_xml = new XMLElement('keywords');
			$keywords_xml->setAttributeArray(
				array(
					'raw' => General::sanitize($keywords_raw),
					'with-synonyms' => General::sanitize($keywords_synonyms),
				)
			);
			foreach($phrases as $phrase) {
				$keyword_xml = new XMLElement('keyword', (!$phrase->include ? '-' : '') . $phrase->phrase);
				$keyword_xml->setAttribute('phrase', $phrase->{'is-phrase'} ? 'yes' : 'no');
				if(!is_null($phrase->{'original'})) $keyword_xml->setAttribute('original', (!$phrase->include ? '-' : '') . $phrase->{'original'});
				$keywords_xml->appendChild($keyword_xml);
			}
			$result->appendChild($keywords_xml);
			
			// add excerpt with highlighted search terms
			$keywords_highlight = array();
			foreach($phrases as $phrase) {
				if(!$phrase->include) continue;
				$keywords_highlight[] = $phrase->phrase;
				$keywords_highlight[] = $phrase->{'phrase-no-punctuation'};
				if(isset($phrase->{'original'})) $keywords_highlight[] = $phrase->{'original'};
				if($phrase->{'use-stem'}) $keywords_highlight[] = $phrase->{'phrase-stem'};
			}
			$keywords_highlight = array_unique($keywords_highlight);
			
			// append list of sections
			$sections_xml = new XMLElement('sections');
			foreach($indexed_sections as $section) {
				
				$section_xml = new XMLElement(
					'section',
					General::sanitize($section['name']),
					array(
						'id' => $section['id'],
						'handle' => $section['handle'],
						'selected' => (in_array($section['id'], array_keys($search_sections))) ? 'yes' : 'no'
					)
				);
				
				if($config->{'return-count-for-each-section'} == 'yes') {
					$section_xml->setAttribute('results', Symphony::Database()->fetchVar('count', 0, preg_replace('/__SECTIONS__/', $section['id'], $sql_count)));
				}
				
				$sections_xml->appendChild($section_xml);
			}
			$result->appendChild($sections_xml);
		
		
		// Append entries to XML, build if desired	
		/*-----------------------------------------------------------------------*/	
			
			// if true then the entire entry will be appended to the XML. If not, only
			// a "stub" of the entry ID is provided, allowing other data sources to
			// supplement with the necessary fields
			$build_entries = ($config->{'build-entries'} == 'yes') ? TRUE : FALSE;
			if($build_entries) {
				$em = new EntryManager(Frontend::instance());
				$fm = new FieldManager(Frontend::instance());
				$field_pool = array();
			}
			
			// container for entry ID output parameter
			$param_output = array();
			
			foreach($entries as $entry) {
				
				$param_output[] = $entry['entry_id'];
				
				$entry_xml = new XMLElement(
					'entry',
					NULL,
					array(
						'id' => $entry['entry_id'],
						'section' => $search_sections[$entry['section_id']]['handle'],
						//'score' => round($entry['score'], 3)
					)
				);
				
				$excerpt = SearchIndex::parseExcerpt($keywords_highlight, $entry['data']);
				$entry_xml->appendChild(new XMLElement('excerpt', $excerpt));
				
				// build and append entry data
				if($build_entries) {
					$e = reset($em->fetch($entry['entry_id']));
					$data = $e->getData();
					foreach($data as $field_id => $values){
						if(!isset($field_pool[$field_id]) || !is_object($field_pool[$field_id])) {
							$field_pool[$field_id] = $em->fieldManager->fetch($field_id);
						}
						$field_pool[$field_id]->appendFormattedElement($entry_xml, $values, FALSE, NULL, $e->get('id'));
					}
				}
				
				$result->appendChild($entry_xml);
			}
			
			$search_time = microtime() - $t;
			
			// append input values
			$result->setAttributeArray(
				array(
					'sort' => General::sanitize($param_sort),
					'direction' => General::sanitize($param_direction),
					'time' => round($search_time, 3) . 's'
				)
			);
			
			// send entry IDs as Output Parameterss
			$param_pool['ds-' . $this->dsParamROOTELEMENT] = $param_output;
		
		// Log query
		/*-----------------------------------------------------------------------*/	
		
			if ($config->{'log-keywords'} == 'yes') {
				$section_handles = array_map('reset', array_values($search_sections));
				SearchIndexLogs::save($param_keywords, $section_handles, $this->dsParamSTARTPAGE, $total_entries);
			}
		
			return $result;		

		}
	
	}