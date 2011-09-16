<?php

Class SearchIndex {
	
	private static $_entry_manager = NULL;
	private static $_entry_xml_datasource = NULL;
	
	private static $_where = NULL;
	private static $_joins = NULL;
	
	private static $_stopwords = NULL;
	
	/**
	* Set up static members
	*/
	private function assert() {
		require_once(TOOLKIT . '/class.entrymanager.php');
		if (self::$_entry_manager == NULL) self::$_entry_manager = new EntryManager(Symphony::Engine());
		if (self::$_entry_xml_datasource == NULL) self::$_entry_xml_datasource = new EntryXMLDataSource(Symphony::Engine(), NULL, FALSE);
	}
	
	/**
	* Returns an array of all indexed sections and their filters
	*/
	public static function getIndexes($id=NULL) {
		$indexes = Symphony::Database()->fetch(sprintf(
			"SELECT
				*
			FROM
				tbl_search_index_indexes
			WHERE 1=1
				%s",
			(isset($id) ? (' AND section_id=' . $id) : '')
		));
		foreach($indexes as $i => $index) {
			$indexes[$i]['included_fields'] = unserialize($index['included_fields']);
			$indexes[$i]['filters'] = unserialize($index['filters']);
		}
		return $indexes;
	}
	
	public static function getIndex($id) {
		$indexes = self::getIndexes($id);
		return reset($indexes);
	}
	
	public static function saveIndex($index) {
		// remove existing index
		Symphony::Database()->query(sprintf(
			"DELETE FROM tbl_search_index_indexes WHERE section_id=%d",
			$index['section_id']
		));
		// insert index
		Symphony::Database()->insert(
			array(
				'section_id' => $index['section_id'],
				'included_fields' => serialize($index['included_fields']),
				'weighting' => $index['weighting'],
				'filters' => serialize($index['filters'])
			),
			'tbl_search_index_indexes'
		);
	}
	
	public static function deleteIndex($id) {
		// remove existing index
		Symphony::Database()->query(sprintf(
			"DELETE FROM tbl_search_index_indexes WHERE section_id=%d",
			$id
		));
		self::deleteIndexedEntriesBySection($id);
	}
	
	/**
	* Parse the indexable content for an entry
	*
	* @param int $entry
	* @param int $section
	*/
	public function indexEntry($entry, $section, $check_filters=TRUE) {
		self::assert();
		
		if (is_object($entry)) $entry = $entry->get('id');
		if (is_object($section)) $section = $section->get('id');
		
		$index = self::getIndex($section);
		
		// go no further if this section isn't being indexed
		if (!isset($index)) return;
		
		// only pass entries through filters if we need to. If entry is being sent
		// from the Re-Index AJAX it has already gone through filtering, so no need here
		if ($check_filters === TRUE) {

			if (self::$_where == NULL || self::$_joins == NULL) {
				
				// modified from the core's class.datasource.php
				
				// create filters and build SQL required for each
				if(is_array($index['filters']) && !empty($index['filters'])) {				
					
					foreach($index['filters'] as $field_id => $filter){

						if((is_array($filter) && empty($filter)) || trim($filter) == '') continue;
						
						if(!is_array($filter)){
							$filter_type = DataSource::__determineFilterType($filter);

							$value = preg_split('/'.($filter_type == DS_FILTER_AND ? '\+' : '(?<!\\\\),').'\s*/', $filter, -1, PREG_SPLIT_NO_EMPTY);			
							$value = array_map('trim', $value);

							$value = array_map(array('Datasource', 'removeEscapedCommas'), $value);
						}

						else $value = $filter;

						if(!isset($fieldPool[$field_id]) || !is_object($fieldPool[$field_id]))
							$fieldPool[$field_id] =& self::$_entry_manager->fieldManager->fetch($field_id);

						if($field_id != 'id' && !($fieldPool[$field_id] instanceof Field)){
							throw new Exception(
								__(
									'Error creating field object with id %1$d, for filtering in data source "%2$s". Check this field exists.', 
									array($field_id, $this->dsParamROOTELEMENT)
								)
							);
						}

						if($field_id == 'id') $where = " AND `e`.id IN ('".@implode("', '", $value)."') ";
						else{ 
							if(!$fieldPool[$field_id]->buildDSRetrivalSQL($value, $joins, $where, ($filter_type == DS_FILTER_AND ? true : false))){ $this->_force_empty_result = true; return; }
							if(!$group) $group = $fieldPool[$field_id]->requiresSQLGrouping();
						}
											
					}
				}
				self::$_where = $where;
				self::$_joins = $joins;
			}

			// run entry though filters
			$entry_prefilter = self::$_entry_manager->fetch($entry, $section, 1, 0, self::$_where, self::$_joins, FALSE, FALSE);
			
			// if no entry found, it didn't pass the pre-filtering
			if (empty($entry_prefilter)) return;

			// if entry passes filtering, pass entry_id as a DS filter to the EntryXMLDataSource DS
			$entry = reset($entry_prefilter);
			$entry = $entry['id'];
			
		}
		
		if (!is_array($entry)) $entry = array($entry);
		
		// create a DS and filter on System ID of the current entry to build the entry's XML			
		self::$_entry_xml_datasource->dsParamINCLUDEDELEMENTS = $index['included_fields'];
		self::$_entry_xml_datasource->dsParamFILTERS['id'] = implode(',',$entry);
		self::$_entry_xml_datasource->dsSource = (string)$section;
		
		$param_pool = array();
		$entry_xml = self::$_entry_xml_datasource->grab($param_pool);
		
		require_once(TOOLKIT . '/class.xsltprocess.php');
		
		$xml = simplexml_load_string($entry_xml->generate());
		
		foreach($xml->xpath("//entry") as $entry_xml) {
			
			$entry_id = (int)$entry_xml->attributes()->id;
			
			// delete existing index for this entry
			self::deleteIndexedEntriesByEntry($entry_id);
			
			// get text value of the entry
			$proc = new XsltProcess();
			$data = $proc->process($entry_xml->asXML(), file_get_contents(EXTENSIONS . '/search_index/lib/parse-entry.xsl'));
			$data = trim($data);
			$data = preg_replace("/\n/m", ' ', $data); // remove new lines
			$data = preg_replace("/[\s]{2,}/m", ' ', $data); // remove muliple spaces
			
			self::saveEntryIndex($entry_id, $section, $data);
			
		}
		
	}
	
	/**
	* Store the indexable content for an entry
	*
	* @param int $entry
	* @param int $section
	* @param string $data
	*/
	public function saveEntryIndex($entry_id, $section_id, $data) {
		
		require_once(EXTENSIONS . '/search_index/lib/porterstemmer/class.porterstemmer.php');
		
		$data_normalised = array();
		$data_normalised_stops = array();
		$data_normalised_stops_stemmed = array();
		
		$words = explode(' ', trim($data));
		foreach($words as $word) {
			$word = trim(self::stripPunctuation($word));
			$data_normalised[] = $word;
			if(self::isStopWord($word)) continue;
			$data_normalised_stops[] = $word;
			$data_normalised_stops_stemmed[] = PorterStemmer::Stem($word);
		}
		
		// stores the full entry text
		Symphony::Database()->insert(
			array(
				'entry_id' => $entry_id,
				'section_id' => $section_id,
				'data' => ' ' . $data . ' ',
				'data_normalised' => ' ' . implode(' ', $data_normalised) . ' ',
				'data_normalised_stops' => ' ' . implode(' ', $data_normalised_stops) . ' ',
				'data_normalised_stops_stemmed' => ' ' . implode(' ', $data_normalised_stops_stemmed) . ' '
			),
			'tbl_search_index_data'
		);
		// stores the entry text keywords, one row per word
		self::saveEntryKeywords($entry_id, $data);
	}
	
	/**
	* Remove stored keywords for an entry
	*
	* @param int $entry
	*/
	public function deleteEntryKeywords($entry_id) {
		// get all keywords for this entry
		$keywords = Symphony::Database()->fetch(sprintf("SELECT keyword_id FROM `tbl_search_index_entry_keywords` WHERE `entry_id` = %d", $entry_id));
		// delete the keyword association (unlink the keyword from the entry)
		Symphony::Database()->query(sprintf("DELETE FROM `tbl_search_index_entry_keywords` WHERE `entry_id` = %d", $entry_id));
		// see if each keyword exists for other entries
		foreach($keywords as $keyword) {
			$exists = Symphony::Database()->fetchVar('count', 0, sprintf("SELECT COUNT(keyword_id) AS `count` FROM `sym_search_index_entry_keywords` WHERE `keyword_id` = %d AND `entry_id` <> %d", $keyword['keyword_id'], $entry_id));
			if((int)$exists == 0) {
				Symphony::Database()->query(sprintf("DELETE FROM `tbl_search_index_keywords` WHERE `id` = %d", $keyword['keyword_id']));
			}
		}
	}
	
	public function saveEntryKeywords($entry_id, $data) {
		
		// delete keyword associations for this entry
		self::deleteEntryKeywords($entry_id);
		
		// remove as much crap as possible
		$data = strip_tags($data);
		$data = strtolower($data);
		
		// force UTF-8 encoding
		if(!self::is_utf8($data)) $data = utf8_encode($data);
		
		// remove dodgy ASCII characters
		// note: I don't recall where these line came from or what they do,
		// but they look suitably complex to leave in...
		$data = preg_replace('~&#x([0-9a-f]+);~ei', 'chr(hexdec("\\1"))', $data);
	    $data = preg_replace('~&#([0-9]+);~e', 'chr("\\1")', $data);
	
		// remove punctuation between words such as commas, stops and so on
		$data = self::stripPunctuation($data);
		
		$words = explode(' ', trim($data));
		$words = array_unique($words);
		
		// store words to log this time around
		$all_keywords = array();
		$add_keywords = array();
		
		foreach($words as $word) {
			$word = trim($word);
			
			// exclude words that are too short or too long
			if(strlen($word) >= (int)Symphony::Configuration()->get('max-word-length', 'search_index') || self::strlen($word) < (int)Symphony::Configuration()->get('min-word-length', 'search_index')) {
				continue;
			}
			
			if(self::isStopWord($word)) {
				continue;
			}
			
			// exclude words that are obviously code
			if(
				// probably HTML
				preg_match("/^lt;/", $word) || 
				preg_match("/&gt$/", $word) ||
				preg_match("/&lt;/", $word) ||
				preg_match("/&gt;/", $word) || 
				preg_match("/=/", $word) || 
				// start with $, probably an XSLT param
				preg_match("/^\\$/", $word) || 
				// start with /, probably XPath
				preg_match("/^\//", $word) || 
				// probably PHP
				preg_match("/->/", $word) || 
				preg_match("/::/", $word)
			) {
				continue;
			}
			
			$all_keywords[$word] = Symphony::Database()->cleanValue($word);
			$add_keywords[$word] = Symphony::Database()->cleanValue($word);
			
		}
		
		// which keywords have already been logged before?
		$existing_keywords = Symphony::Database()->fetch(sprintf(
			"SELECT id, keyword FROM `tbl_search_index_keywords` WHERE `keyword` IN ('%s')",
			implode("','", array_values($all_keywords))
		));
		// remove existing words from the set we need to add
		foreach($existing_keywords as $word) unset($add_keywords[$word['keyword']]);
		
		// add new keywords, retrieving their ID
		foreach($add_keywords as $word => $clean) {
			Symphony::Database()->insert(array('keyword' => $word), 'tbl_search_index_keywords');
			$keyword_id = Symphony::Database()->getInsertID();
			$existing_keywords[] = array('id' => $keyword_id, 'keyword' => $word);
		}
		
		// no words to log
		if(count($existing_keywords) == 0) return;
		
		// add all the new word associations in one batch (MUCH faster than an INSERT per word!)
		$insert = "INSERT INTO tbl_search_index_entry_keywords (entry_id, keyword_id, frequency) VALUES ";
		foreach($existing_keywords as $word) {
			$insert .= sprintf("(%d, %d, '%s'),", $entry_id, $word['id'], substr_count($data, $word['keyword']));
		}
		$insert = trim($insert, ',');
		Symphony::Database()->query($insert);
		
	}
	
	/**
	* Delete indexed entry data for a section
	*
	* @param int $section_id
	*/
	public function deleteIndexedEntriesBySection($section_id) {
		$entries = Symphony::Database()->fetch(sprintf("SELECT id FROM `tbl_entries` WHERE `section_id` = %d", $section_id));
		foreach($entries as $entry) {
			self::deleteIndexedEntriesByEntry($entry['id']);
		}
	}
	
	/**
	* Delete indexed entry data for an entry
	*
	* @param int $entry_id
	*/
	public function deleteIndexedEntriesByEntry($entry_id) {
		self::deleteEntryKeywords($entry_id);
		Symphony::Database()->query(
			sprintf(
				"DELETE FROM `tbl_search_index_data` WHERE `entry_id` = %d",
				$entry_id
			)
		);
	}
	
	/**
	* Pre-manipulation of search string
	* 1. Make all words required by prefixing with + (if no +/- already prefixed)
	* 2. Leave "quoted phrases" untouched
	* 3. If enabled in config, append wildcard * to end of words for partial matching
	*
	* @param string $string
	*/
	public function manipulateKeywords($string) {
		
		// replace spaces within quoted phrases
		$string = preg_replace('/"(?:[^\\"]+|\\.)*"/e', "str_replace(' ', 'SEARCH_INDEX_SPACE', '$0')", $string);
		// correct slashed quotes sa a result of above
		$string = stripslashes(trim($string));
		
		$keywords = '';
		
		// get each word
		foreach(explode(' ', $string) as $word) {
			if (!preg_match('/^(\-|\+)/', $word) && !preg_match('/^"/', $word)) {
				if (Symphony::Configuration()->get('append-all-words-required', 'search_index') == 'yes') {
					$word = '+' . $word;
				}
				if (!preg_match('/\*$/', $word) && Symphony::Configuration()->get('append-wildcard', 'search_index') == 'yes') {
					$word = $word . '*';
				}
			}
			$keywords .= $word . ' ';
		}
		
		$keywords = trim($keywords);
		$keywords = preg_replace('/SEARCH_INDEX_SPACE/', ' ', $keywords);
		
		return $keywords;
	}
	
	public static function parseExcerpt($keywords, $text) {
	
		if(!is_array($keywords)) $keywords = array();
	
		$text = trim($text);
		$text = preg_replace("/\n/m", ' ', $text);
		$text = preg_replace("/[\s]{2,}/m", ' ', $text);
	
		$string_length = (Symphony::Configuration()->get('excerpt-length', 'search_index')) ? Symphony::Configuration()->get('excerpt-length', 'search_index') : 200;
		$between_start = $string_length / 2;
		$between_end = $string_length / 2;
		$elipsis = '__SEARCH_INDEX_ELIPSIS__';
		
		// don't highlight short words
		foreach($keywords as $i => $keyword) {
			if (self::strlen($keyword) < (int)Symphony::Configuration()->get('min-word-length', 'search_index')) unset($keywords[$i]);
		}
		
		// don't highlight stop words
		foreach($keywords as $i => $keyword) {
			if (self::isStopWord($keyword)) unset($keywords[$i]);
		}

		// Prepare text
		$text = ' '. strip_tags(str_replace(array('<', '>'), array(' <', '> '), $text)) .' ';
		// no idea what this next line actually does, nothing is harmed if it's simply commented out...
		array_walk($keywords, 'SearchIndex::_parseExcerptReplace');
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
				if (self::strlen($key) == 0) {
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
					if (($q = self::strpos($text, ' ', max(0, $p - $between_start))) !== FALSE) {
						$end = self::substr($text, $p, $between_end);
						if (($s = self::strrpos($end, ' ')) !== FALSE) {
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
			if (self::strlen($text) > $string_length) {
				$text = self::substr($text, 0, $string_length) . $elipsis; 
			}
			$text = General::sanitize($text);
			$text = preg_replace('/'.$elipsis.'/', '&#8230;', $text);
			return '<p>' . $text . '</p>';
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
			$out[] = self::substr($text, $from, $to - $from);
		}
		
		$text = (isset($newranges[0]) ? '' : $elipsis) . implode($elipsis, $out) . $elipsis;

		// Highlight keywords. Must be done at once to prevent conflicts ('strong' and '<strong>').
		$boundary_prefix = '';
		$boundary_suffix = '(.){0,}?\s';
		$text = preg_replace('/'. $boundary_prefix .'('. implode('|', $keywords) .')'. $boundary_suffix .'/iu', '__SEARCH_INDEX_START_HIGHLIGHT__\0__SEARCH_INDEX_END_HIGHLIGHT__', $text);
	
		$text = preg_replace("/[\s]{2,}/m", ' ', $text);
		$text = trim($text);
		
		$text = General::sanitize($text);
		$text = preg_replace('/__SEARCH_INDEX_START_HIGHLIGHT__/', '<strong>', $text);
		$text = preg_replace('/__SEARCH_INDEX_END_HIGHLIGHT__/', '</strong>', $text);
		$text = preg_replace('/__SEARCH_INDEX_ELIPSIS__/', '&#8230;', $text);
		
		return '<p>' . $text . '</p>';
	}
	
	private static function _parseExcerptReplace(&$text) {
		$text = preg_quote($text, '/');
	}
	
	
	
	
	
	
	public static function getSynonyms($id=NULL) {
		$synonyms = Symphony::Database()->fetch(sprintf(
			"SELECT
				*
			FROM
				tbl_search_index_synonyms
			WHERE 1=1
				%s
			ORDER BY
				word ASC",
			(isset($id) ? (' AND id=' . $id) : '')
		));
		return $synonyms;
	}
	
	public static function getSynonym($id) {
		$synonyms = self::getSynonyms($id);
		return reset($synonyms);
	}
	
	public static function saveSynonym($synonym) {
		// remove existing
		if(isset($synonym['id'])) self::deleteSynonym($synonym['id']);
		// insert index
		Symphony::Database()->insert(
			array(
				'word' => $synonym['word'],
				'synonyms' => $synonym['synonyms']
			),
			'tbl_search_index_synonyms'
		);
	}
	
	public static function deleteSynonym($id) {
		Symphony::Database()->query(sprintf(
			"DELETE FROM tbl_search_index_synonyms WHERE id=%d",
			$id
		));
	}
	
	
	
	
	public static function getStopWords() {
		$stopwords = Symphony::Database()->fetchCol('word',
			"SELECT
				word
			FROM
				tbl_search_index_stopwords
			ORDER BY
				word ASC"
		);
		return $stopwords;
	}
	
	public static function saveStopWords($stopwords) {
		
		// remove existing
		Symphony::Database()->query("DELETE FROM tbl_search_index_stopwords");
		
		foreach($stopwords as $word) {
			Symphony::Database()->insert(
				array(
					'word' => trim($word)
				),
				'tbl_search_index_stopwords'
			);
		}
		
	}
	
	public static function applySynonyms($keywords) {
		
		$keywords = explode(' ', $keywords);
		$synonyms = self::getSynonyms();
		
		$keywords_manipulated = '';
		
		foreach($keywords as $word) {
			$boolean_characters = array();
			preg_match('/^(\-|\+)/', $word, $boolean_characters);
			$word = strtolower(trim(preg_replace('/^(\-|\+)/', '', $word)));
			
			foreach($synonyms as $synonym) {
				$synonym_terms = explode(',', $synonym['synonyms']);
				foreach($synonym_terms as $s) {
					$s = strtolower(trim($s));
					// replace word with synonym replace word
					if ($s == $word) $word = $synonym['word'];
				}
			}
			
			// add boolean character back in front of word
			if (count($boolean_characters) > 0) $word = $boolean_characters[0] . $word;
			$keywords_manipulated .= $word . ' ';
		}
		
		return trim($keywords_manipulated);
		
	}
	
	public static function parseKeywordString($keywords, $normalise=TRUE) {
		
		$config = (object)Symphony::Configuration()->get('search_index');
		
		// should we apply word stemming?
		$do_stemming = ($config->{'stem-words'} == 'yes') ? TRUE : FALSE;
		require_once(EXTENSIONS . '/search_index/lib/porterstemmer/class.porterstemmer.php');
		
		// we will store the various keywords under these categories
		$keyword_types = array(
			
			'include-phrase' => array(),// "foo bar" or +"foo bar"
			'exclude-phrase' => array(), // -"foo bar"
			
			'include-word' => array(), // foo or +foo
			'exclude-word' => array(), // -foo
			
			//'include-words-all' => array(),
			//'exclude-words-all' => array(),
			
			'highlight' => array() // we can highlight these in the returned excerpts
		);
		
		$matches = array();
		
		// look for phrases, surrounded by double quotes
		while (preg_match("/([-]?)\"([^\"]+)\"/", $keywords, $matches)) {
			if ($matches[1] == '') {
				$keyword_types['include-phrase'][] = $matches[2];
			} else {
				$keyword_types['exclude-phrase'][] = $matches[2];
			}
			$keywords = str_replace($matches[0], '', $keywords);
		}
		
		$keywords = strtolower(preg_replace("/[ ]+/", " ", $keywords));
		$keywords = trim($keywords);
		$keywords = explode(' ', $keywords);
		
		if ($keywords == '') {
			$limit = 0;
		} else {
			$limit = count($keywords);
		}
		
		$i = 0;
		
		//get all words (both include and exlude)
		$tmp_include_words = array();
		while ($i < $limit) {
			if (self::substr($keywords[$i], 0, 1) == '+') {
				$tmp_include_words[] = self::substr($keywords[$i], 1);
			} else if (self::substr($keywords[$i], 0, 1) == '-') {
				$keyword_types['exclude-word'][] = self::substr($keywords[$i], 1);
			} else {
				$tmp_include_words[] = $keywords[$i];
			}
			$i++;
		}

		foreach ($tmp_include_words as $word) {
			$word = SearchIndex::stripPunctuation($word);
			// exclude words that are too short or too long
			if($normalise && (self::strlen($word) >= (int)Symphony::Configuration()->get('max-word-length', 'search_index') || self::strlen($word) < (int)Symphony::Configuration()->get('min-word-length', 'search_index'))) {
				continue;
			}
			if($normalise && self::isStopWord($word)) {
				continue;
			}
			$keyword_types['include-word'][] = $word;
		}
		
		$keyword_types['include-phrase'] = array_unique($keyword_types['include-phrase']);
		$keyword_types['exclude-phrase'] = array_unique($keyword_types['exclude-phrase']);
		
		$keyword_types['include-word'] = array_unique($keyword_types['include-word']);
		$keyword_types['exclude-word'] = array_unique($keyword_types['exclude-word']);
				
		$terms = array();
		
		foreach($keyword_types['include-phrase'] as $phrase) {
			$phrase = trim($phrase);
			$terms[] = (object)array(
				'is-phrase' => TRUE,
				'phrase' => $phrase,
				'phrase-no-punctuation' => SearchIndex::stripPunctuation($phrase),
				'use-stem' => FALSE,				
				'phrase-stem' => NULL,
				'include' => TRUE
			);
		}
		
		foreach($keyword_types['exclude-phrase'] as $phrase) {
			$phrase = trim($phrase);
			$terms[] = (object)array(
				'is-phrase' => TRUE,
				'phrase' => $phrase,
				'phrase-no-punctuation' => SearchIndex::stripPunctuation($phrase),
				'use-stem' => FALSE,				
				'phrase-stem' => NULL,
				'include' => FALSE
			);
		}
		
		// single words
		foreach($keyword_types['include-word'] as $keyword) {
			$phrase = trim(SearchIndex::stripPunctuation($keyword));
			$phrase_stem = PorterStemmer::Stem($phrase);
			$use_stem = ($do_stemming && ($phrase_stem != $phrase));
			$phrase_synonym = (!$use_stem) ? SearchIndex::applySynonyms($phrase) : $phrase;
			$terms[] = (object)array(
				'is-phrase' => FALSE,
				'phrase' => $phrase_synonym,
				'phrase-no-punctuation' => $phrase_synonym,
				'use-stem' => $use_stem,
				'phrase-stem' => $phrase_stem,
				'include' => TRUE,
				'original' => ($phrase != $phrase_synonym) ? $phrase : NULL
			);
		}
		
		foreach($keyword_types['exclude-word'] as $keyword) {
			$phrase = trim(SearchIndex::stripPunctuation($keyword));
			$phrase_stem = PorterStemmer::Stem($phrase);
			$use_stem = ($do_stemming && ($phrase_stem != $phrase));
			$phrase_synonym = (!$use_stem) ? SearchIndex::applySynonyms($phrase) : $phrase;
			$terms[] = (object)array(
				'is-phrase' => FALSE,
				'phrase' => $phrase_synonym,
				'phrase-no-punctuation' => $phrase_synonym,
				'use-stem' => $use_stem,
				'phrase-stem' => $phrase_stem,
				'include' => FALSE,
				'original' => ($phrase != $phrase_synonym) ? $phrase : NULL
			);
		}
		
		$keyword_types['phrases'] = $terms;
		
		// add excerpt with highlighted search terms
		$keywords_highlight = array();
		foreach($terms as $phrase) {
			if(!$phrase->include) continue;
			$keywords_highlight[] = $phrase->phrase;
			$keywords_highlight[] = $phrase->{'phrase-no-punctuation'};
			if(isset($phrase->{'original'})) $keywords_highlight[] = $phrase->{'original'};
			if($phrase->{'use-stem'}) $keywords_highlight[] = $phrase->{'phrase-stem'};
		}
		$keywords_highlight = array_unique($keywords_highlight);
		
		return (object)array(
			'phrases' => $keyword_types['phrases'],
			'highlight' => $keywords_highlight
		);
		
	}
	
	public static function substr($str, $pos, $length=NULL) {
		if(function_exists('mb_substr')) {
			if(is_null($length)) return mb_substr($str, $pos);
			return mb_substr($str, $pos, $length);
		} else {
			if(is_null($length)) return substr($str, $pos, $length);
			return substr($str, $pos, $length);
		}
	}
	
	public static function strlen($str) {
		if(function_exists('mb_strlen')) {
			return mb_strlen($str);
		} else {
			return strlen($str);
		}
	}
	
	public static function strpos($str1, $str2, $pos) {
		if(function_exists('mb_strpos')) {
			return mb_strpos($str1, $str2, $pos);
		} else {
			return strpos($str1, $str2, $pos);
		}
	}
	
	public static function strrpos($str1, $str2) {
		if(function_exists('mb_strrpos')) {
			return mb_strrpos($str1, $str2);
		} else {
			return strrpos($str1, $str2);
		}
	}
		
	// pinched from FirePHP (FirePHP.class.php)
	public static function is_utf8($str) {
		$c = 0;
		$b = 0;
		$bits = 0;
		$len = strlen($str);
		for($i=0; $i<$len; $i++){
			$c=ord($str[$i]);
			if($c > 128){
				if(($c >= 254)) return false;
				elseif($c >= 252) $bits=6;
				elseif($c >= 248) $bits=5;
				elseif($c >= 240) $bits=4;
				elseif($c >= 224) $bits=3;
				elseif($c >= 192) $bits=2;
				else return false;
				if(($i+$bits) > $len) return false;
				while($bits > 1){
					$i++;
					$b = ord($str[$i]);
					if($b < 128 || $b > 191) return false;
					$bits--;
				}
			}
		}
		return true;
	}
		
	public static function isStopWord($word) {
		// load stopwords if not already loaded
		if(is_null(self::$_stopwords)) {
			self::$_stopwords = self::getStopWords();
		}
		return in_array($word, self::$_stopwords);
	}
	
	public static function stripPunctuation($phrase) {
		
		// TODO: str_word_count removed "ps3. WHY?
		
		require_once(EXTENSIONS . '/search_index/lib/strip_punctuation.php');
		// proptietary strip, returns string
		$phrase = strip_punctuation($phrase);
		// php strip, returns array
		//$phrase = str_word_count(strtolower($phrase), 1);
		// run through proprietary again, just for good measure
		//$phrase = strip_punctuation(implode(' ', $phrase));
		return $phrase;
	}
	
	public static function buildBooleanWhere($keywords) {
		return sprintf("
			MATCH(search_index.data) AGAINST ('%s' IN BOOLEAN MODE)
			",
			Symphony::Database()->cleanValue($keywords)
		);
	}
	
	public static function buildLikeWhere($phrases, $config, &$sql) {
		
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
					"search_index.data %1\$s LIKE '%2\$s' OR search_index.data_normalised %1\$s LIKE '%2\$s' ",
					($phrase->include) ? '' : 'NOT',
					'% ' . Symphony::Database()->cleanValue($phrase->phrase) . ' %'
				);
				
				// phrase without terminating punctuation e.g. "foo bar"
				$sql_where .= sprintf(
					"OR search_index.data %1\$s LIKE '%2\$s' OR search_index.data_normalised %1\$s LIKE '%2\$s' ",
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
						"search_index.data_normalised_stops_stemmed %1\$s LIKE '%2\$s' ",
						($phrase->include) ? '' : 'NOT',
						'% ' . Symphony::Database()->cleanValue($phrase->{'phrase-stem'}) . (($config->{'partial-words'} == 'no') ? ' ' : '') . '%'
					);
					
				} else {
					
					$column = 'data_normalised_stops';
					$keyword = Symphony::Database()->cleanValue($phrase->{'phrase-no-punctuation'});
					
					$sql_where .= sprintf(
						"search_index.data_normalised_stops %1\$s LIKE '%2\$s' ",
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
		
		$sql->locate = $sql_locate;
		$sql->replace = $sql_replace;
		$sql->where = $sql_where;
		
	}
	
	
	
}