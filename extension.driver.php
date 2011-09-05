<?php

	require_once(TOOLKIT . '/class.datasource.php');
	require_once(EXTENSIONS . '/search_index/lib/class.search_index.php');
	require_once(EXTENSIONS . '/search_index/lib/class.search_index_logs.php');
	require_once(EXTENSIONS . '/search_index/lib/class.entry_xml_datasource.php');
	require_once(EXTENSIONS . '/search_index/lib/class.reindex_datasource.php');
	
	class Extension_Search_Index extends Extension {
		
		/**
		* Extension meta data
		*/
		public function about() {
			return array(
				'name'			=> 'Search Index',
				'version'		=> '0.9.1',
				'release-date'	=> '2011-07-08',
				'author'		=> array(
					'name'			=> 'Nick Dunn'
				),
				'description' => 'Index text content of entries for efficient fulltext search.'
			);
		}
		
		private function createTables() {
			
			try {
				
				// Search Index field for adding to sections: adds keyword
				// searching functionality as a data source filter
				Symphony::Database()->query(
				  "CREATE TABLE IF NOT EXISTS `tbl_fields_search_index` (
					  `id` int(11) unsigned NOT NULL auto_increment,
					  `field_id` int(11) unsigned NOT NULL,
				  PRIMARY KEY  (`id`),
				  KEY `field_id` (`field_id`))");
				
				// meta data about each index, which sections, fields and filters
				Symphony::Database()->query(
					"CREATE TABLE `tbl_search_index_indexes` (
					  `section_id` int(11) NOT NULL,
					  `included_fields` varchar(255) NOT NULL DEFAULT '',
					  `weighting` int(11) DEFAULT NULL,
					  `filters` text,
					  UNIQUE KEY `section_id` (`section_id`)
					) ENGINE=MyISAM DEFAULT CHARSET=utf8;"
				);
				
				// the full indexed text of each entry
				Symphony::Database()->query(
					"CREATE TABLE IF NOT EXISTS `tbl_search_index_data` (
					  `id` int(11) NOT NULL auto_increment,
					  `entry_id` int(11) NOT NULL,
					  `section_id` int(11) NOT NULL,
					  `data` text,
					  `data_stripped` text,
					  `data_stripped_stemmed` text,
					  PRIMARY KEY (`id`),
					  KEY `entry_id` (`entry_id`),
					  FULLTEXT KEY `data` (`data`),
					  FULLTEXT KEY `data_stripped` (`data_stripped`),
					  FULLTEXT KEY `data_stripped_stemmed` (`data_stripped_stemmed`)
					) ENGINE=MyISAM DEFAULT CHARSET=utf8"
				);
				
				// unique keywords parsed from indexed text
				Symphony::Database()->query(
					"CREATE TABLE IF NOT EXISTS `tbl_search_index_keywords` (
					  `id` int(11) NOT NULL auto_increment,
					  `keyword` varchar(255) default NULL,
					  PRIMARY KEY  (`id`),
					  FULLTEXT KEY `keyword` (`keyword`)
					) ENGINE=MyISAM DEFAULT CHARSET=utf8;"
				);
				
				// mapping of unique keywords to each entry, with frequency of occurences
				Symphony::Database()->query(
					"CREATE TABLE IF NOT EXISTS `tbl_search_index_entry_keywords` (
					  `entry_id` int(11) default NULL,
					  `keyword_id` int(11) default NULL,
					  `frequency` int(11) default NULL,
					  KEY `entry_id` (`entry_id`),
					  KEY `keyword_id` (`keyword_id`)
					) ENGINE=MyISAM DEFAULT CHARSET=utf8;"
				);
				
				// synonym conversions that occur at search run time
				Symphony::Database()->query(
					"CREATE TABLE `tbl_search_index_synonyms` (
					  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
					  `word` varchar(255) DEFAULT NULL,
					  `synonyms` text,
					  PRIMARY KEY (`id`)
					) ENGINE=MyISAM DEFAULT CHARSET=utf8;"
				);
				
				// log of public searches
				Symphony::Database()->query(
					"CREATE TABLE `tbl_search_index_logs` (
					  `id` varchar(255) NOT NULL DEFAULT '',
					  `date` datetime NOT NULL,
					  `keywords` varchar(255) DEFAULT NULL,
					  `sections` varchar(255) DEFAULT NULL,
					  `page` int(11) NOT NULL,
					  `results` int(11) DEFAULT NULL,
					  `session_id` varchar(255) DEFAULT NULL,
					  `user_agent` varchar(255) DEFAULT NULL,
					  `ip` varchar(255) DEFAULT NULL,
					  PRIMARY KEY (`id`),
					  UNIQUE KEY `id` (`id`),
					  KEY `keywords` (`keywords`),
					  KEY `date` (`date`),
					  KEY `session_id` (`session_id`)
					) ENGINE=MyISAM DEFAULT CHARSET=utf8;"
				);
				
				Symphony::Database()->query(
					"CREATE TABLE `tbl_search_index_stopwords` (
					  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
					  `word` varchar(255) DEFAULT NULL,
					  PRIMARY KEY (`id`)
					) ENGINE=MyISAM DEFAULT CHARSET=utf8;"
				);
				
				$stopwords = array("a's", "able", "about", "above", "according", "accordingly", "across", "actually", "after", "afterwards", "again", "against", "ain't", "all", "allow", "allows", "almost", "alone", "along", "already", "also", "although", "always", "am", "among", "amongst", "an", "and", "another", "any", "anybody", "anyhow", "anyone", "anything", "anyway", "anyways", "anywhere", "apart", "appear", "appreciate", "appropriate", "are", "aren't", "around", "as", "aside", "ask", "asking", "associated", "at", "available", "away", "awfully", "be", "became", "because", "become", "becomes", "becoming", "been", "before", "beforehand", "behind", "being", "believe", "below", "beside", "besides", "best", "better", "between", "beyond", "both", "brief", "but", "by", "c'mon", "c's", "came", "can", "can't", "cannot", "cant", "cause", "causes", "certain", "certainly", "changes", "clearly", "co", "com", "come", "comes", "concerning", "consequently", "consider", "considering", "contain", "containing", "contains", "corresponding", "could", "couldn't", "course", "currently", "definitely", "described", "despite", "did", "didn't", "different", "do", "does", "doesn't", "doing", "don't", "done", "down", "downwards", "during", "each", "edu", "eg", "eight", "either", "else", "elsewhere", "enough", "entirely", "especially", "et", "etc", "even", "ever", "every", "everybody", "everyone", "everything", "everywhere", "ex", "exactly", "example", "except", "far", "few", "fifth", "first", "five", "followed", "following", "follows", "for", "former", "formerly", "forth", "four", "from", "further", "furthermore", "get", "gets", "getting", "given", "gives", "go", "goes", "going", "gone", "got", "gotten", "greetings", "had", "hadn't", "happens", "hardly", "has", "hasn't", "have", "haven't", "having", "he", "he's", "hello", "help", "hence", "her", "here", "here's", "hereafter", "hereby", "herein", "hereupon", "hers", "herself", "hi", "him", "himself", "his", "hither", "hopefully", "how", "howbeit", "however", "i'd", "i'll", "i'm", "i've", "ie", "if", "ignored", "immediate", "in", "inasmuch", "inc", "indeed", "indicate", "indicated", "indicates", "inner", "insofar", "instead", "into", "inward", "is", "isn't", "it", "it'd", "it'll", "it's", "its", "itself", "just", "keep", "keeps", "kept", "know", "known", "knows", "last", "lately", "later", "latter", "latterly", "least", "less", "lest", "let", "let's", "like", "liked", "likely", "little", "look", "looking", "looks", "ltd", "mainly", "many", "may", "maybe", "me", "mean", "meanwhile", "merely", "might", "more", "moreover", "most", "mostly", "much", "must", "my", "myself", "name", "namely", "nd", "near", "nearly", "necessary", "need", "needs", "neither", "never", "nevertheless", "new", "next", "nine", "no", "nobody", "non", "none", "noone", "nor", "normally", "not", "nothing", "novel", "now", "nowhere", "obviously", "of", "off", "often", "oh", "ok", "okay", "old", "on", "once", "one", "ones", "only", "onto", "or", "other", "others", "otherwise", "ought", "our", "ours", "ourselves", "out", "outside", "over", "overall", "own", "particular", "particularly", "per", "perhaps", "placed", "please", "plus", "possible", "presumably", "probably", "provides", "que", "quite", "qv", "rather", "rd", "re", "really", "reasonably", "regarding", "regardless", "regards", "relatively", "respectively", "right", "said", "same", "saw", "say", "saying", "says", "second", "secondly", "see", "seeing", "seem", "seemed", "seeming", "seems", "seen", "self", "selves", "sensible", "sent", "serious", "seriously", "seven", "several", "shall", "she", "should", "shouldn't", "since", "six", "so", "some", "somebody", "somehow", "someone", "something", "sometime", "sometimes", "somewhat", "somewhere", "soon", "sorry", "specified", "specify", "specifying", "still", "sub", "such", "sup", "sure", "t's", "take", "taken", "tell", "tends", "th", "than", "thank", "thanks", "thanx", "that", "that's", "thats", "the", "their", "theirs", "them", "themselves", "then", "thence", "there", "there's", "thereafter", "thereby", "therefore", "therein", "theres", "thereupon", "these", "they", "they'd", "they'll", "they're", "they've", "think", "third", "this", "thorough", "thoroughly", "those", "though", "three", "through", "throughout", "thru", "thus", "to", "together", "too", "took", "toward", "towards", "tried", "tries", "truly", "try", "trying", "twice", "two", "un", "under", "unfortunately", "unless", "unlikely", "until", "unto", "up", "upon", "us", "use", "used", "useful", "uses", "using", "usually", "value", "various", "very", "via", "viz", "vs", "want", "wants", "was", "wasn't", "way", "we", "we'd", "we'll", "we're", "we've", "welcome", "well", "went", "were", "weren't", "what", "what's", "whatever", "when", "whence", "whenever", "where", "where's", "whereafter", "whereas", "whereby", "wherein", "whereupon", "wherever", "whether", "which", "while", "whither", "who", "who's", "whoever", "whole", "whom", "whose", "why", "will", "willing", "wish", "with", "within", "without", "won't", "wonder", "would", "wouldn't", "yes", "yet", "you", "you'd", "you'll", "you're", "you've", "your", "yours", "yourself", "yourselves", "zero");
				SearchIndex::saveStopWords($stopwords);
				
				Symphony::Database()->query(
					"CREATE TABLE `tbl_search_index_query_suggestions` (
					  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
					  `word` varchar(255) DEFAULT NULL,
					  PRIMARY KEY (`id`)
					) ENGINE=MyISAM DEFAULT CHARSET=utf8;"
				);
				
			}
			catch (Exception $e){
				#var_dump($e);die;
				return FALSE;
			}
			
			return TRUE;
			
		}
		
		private function dropTables($exclude=array()) {
			
			try {
				
				if(!in_array('tbl_fields_search_index', $exclude)) Symphony::Database()->query("DROP TABLE `tbl_fields_search_index`");
				if(!in_array('tbl_search_index_indexes', $exclude)) Symphony::Database()->query("DROP TABLE `tbl_search_index_indexes`");
				if(!in_array('tbl_search_index_data', $exclude)) Symphony::Database()->query("DROP TABLE `tbl_search_index_data`");
				if(!in_array('tbl_search_index_keywords', $exclude)) Symphony::Database()->query("DROP TABLE `tbl_search_index_keywords`");
				if(!in_array('tbl_search_index_entry_keywords', $exclude)) Symphony::Database()->query("DROP TABLE `tbl_search_index_entry_keywords`");
				if(!in_array('tbl_search_index_synonyms', $exclude)) Symphony::Database()->query("DROP TABLE `tbl_search_index_synonyms`");
				if(!in_array('tbl_search_index_logs', $exclude)) Symphony::Database()->query("DROP TABLE `tbl_search_index_logs`");
				
			} catch(Exception $ex) {
				return FALSE;
			}
			
			return TRUE;
			
		}
		
		private function setInitialConfig() {
			
			Symphony::Configuration()->set('re-index-per-page', 20, 'search_index');
			Symphony::Configuration()->set('re-index-refresh-rate', 0.5, 'search_index');
			
			// names of GET parameters used for custom search DS
			Symphony::Configuration()->set('get-param-prefix', '', 'search_index');
			Symphony::Configuration()->set('get-param-keywords', 'keywords', 'search_index');
			Symphony::Configuration()->set('get-param-per-page', 'per-page', 'search_index');
			Symphony::Configuration()->set('get-param-sort', 'sort', 'search_index');
			Symphony::Configuration()->set('get-param-direction', 'direction', 'search_index');
			Symphony::Configuration()->set('get-param-sections', 'sections', 'search_index');
			Symphony::Configuration()->set('get-param-page', 'page', 'search_index');
			
			// default search params, used if not specifed in GET
			Symphony::Configuration()->set('default-sections', '', 'search_index');
			Symphony::Configuration()->set('default-per-page', 20, 'search_index');
			Symphony::Configuration()->set('default-sort', 'score', 'search_index');
			Symphony::Configuration()->set('default-direction', 'desc', 'search_index');
			
			Symphony::Configuration()->set('excerpt-length', 250, 'search_index');
			Symphony::Configuration()->set('min-word-length', 3, 'search_index');
			Symphony::Configuration()->set('max-word-length', 30, 'search_index');
			Symphony::Configuration()->set('stem-words', 'yes', 'search_index');
			Symphony::Configuration()->set('partial-words', 'yes', 'search_index');
			Symphony::Configuration()->set('build-entries', 'no', 'search_index');
			Symphony::Configuration()->set('return-count-for-each-section', 'yes', 'search_index');
			Symphony::Configuration()->set('mode', 'like', 'search_index');
			Symphony::Configuration()->set('log-keywords', 'yes', 'search_index');
						
			Administration::instance()->saveConfig();
			
		}

		/**
		* Set up configuration defaults and database tables
		*/		
		public function install(){
			
			$this->createTables();
			$this->setInitialConfig();
			
			return TRUE;
		}
		
		public function update($previousVersion){
			
			if(version_compare($previousVersion, '0.6', '<')) {
				Symphony::Database()->query("ALTER TABLE `tbl_search_index_logs` ADD `keywords_manipulated` varchar(255) default NULL");
			}
			
			// lower versions get the full upgrade treatment, new tables and config
			// should retain "indexes" and "synonyms" in config though.
			if(version_compare($previousVersion, '0.7.1', '<')) {
				$this->install();
			}
			
			if(version_compare($previousVersion, '0.9.1', '<')) {
				Symphony::Configuration()->set('return-count-for-each-section', 'yes', 'search_index');
				Administration::instance()->saveConfig();
			}
			
			if(version_compare($previousVersion, '1.0', '<')) {
				
				// remove all tables except for the field which can safely remain
				$this->dropTables(array('tbl_fields_search_index'));
				
				// build the empty tables again
				$this->createTables();
				
				// populate index meta data from config
				$indexes = Symphony::Configuration()->get('indexes', 'search_index');
				$indexes = preg_replace("/\\\/",'',$indexes);
				$unserialised_indexes = unserialize($indexes);
				if(!is_array($unserialised_indexes)) $unserialised_indexes = array();
				
				foreach($unserialised_indexes as $section_id => $index) {
					SearchIndex::saveIndex(array(
						'section_id' => $section_id,
						'included_fields' => $index['fields'],
						'weighting' => $index['weighting'],
						'filters' => $index['filters']
					));
				}
				
				// populate synonyms from config
				$synonyms = Symphony::Configuration()->get('synonyms', 'search_index');
				$synonyms = preg_replace("/\\\/",'',$synonyms);
				$unserialised_synonyms = unserialize($synonyms);
				if(!is_array($unserialised_synonyms)) $unserialised_synonyms = array();
				
				foreach($unserialised_synonyms as $synonym) {
					SearchIndex::saveSynonym(array(
						'word' => $synonym['word'],
						'synonyms' => $synonym['synonyms']
					));
				}
				
				Symphony::Configuration()->remove('indexes', 'search_index');
				Symphony::Configuration()->remove('synonyms', 'search_index');
				Administration::instance()->saveConfig();
			
			}
			
			return TRUE;
		}

		/**
		* Cleanup after yourself, remove configuration and database tables
		*/
		public function uninstall(){
			
			Symphony::Configuration()->remove('search_index');			
			Administration::instance()->saveConfig();
			
			$this->dropTables();
			
			return true;
		}
		
		/**
		* Callback functions for backend delegates
		*/		
		public function getSubscribedDelegates() {
			return array(
				array(
					'page'		=> '/publish/new/',
					'delegate'	=> 'EntryPostCreate',
					'callback'	=> 'indexEntry'
				),				
				array(
					'page'		=> '/publish/edit/',
					'delegate'	=> 'EntryPostEdit',
					'callback'	=> 'indexEntry'
				),
				array(
					'page'		=> '/publish/',
					'delegate'	=> 'Delete',
					'callback'	=> 'deleteEntryIndex'
				),
				array(
					'page' => '/frontend/',
					'delegate' => 'EventPostSaveFilter',
					'callback' => 'indexEntry'
				),
				// Dashboard
				array(
					'page'		=> '/backend/',
					'delegate'	=> 'DashboardPanelRender',
					'callback'	=> 'renderPanel'
				),
				array(
					'page'		=> '/backend/',
					'delegate'	=> 'DashboardPanelTypes',
					'callback'	=> 'dashboardPanelTypes'
				),
				array(
					'page'		=> '/frontend/',
					'delegate'	=> 'FrontendPageResolved',
					'callback'	=> 'generate_session'
				),
			);
		}
		
		/**
		* Append navigation to Blueprints menu
		*/
		public function fetchNavigation() {
			return array(
				array(
					'location'	=> __('Search Index'),
					'name'		=> __('Indexes'),
					'link'		=> '/indexes/'
				),
				array(
					'location'	=> __('Search Index'),
					'name'		=> __('Synonyms'),
					'link'		=> '/synonyms/'
				),
				array(
					'location'	=> __('Search Index'),
					'name'		=> __('Search Suggestions'),
					'link'		=> '/searchsuggestions/'
				),
				array(
					'location'	=> __('Search Index'),
					'name'		=> __('Session Logs'),
					'link'		=> '/sessions/'
				),
				array(
					'location'	=> __('Search Index'),
					'name'		=> __('Query Logs'),
					'link'		=> '/queries/'
				),
				array(
					'location'	=> __('Search Index'),
					'name'		=> __('Preferences'),
					'link'		=> '/preferences/'
				),
			);
		}
		
		public function generate_session($context) {
			$cookie_name = sprintf('%ssearch-index-session', Symphony::Configuration()->set('cookie_prefix', 'symphony'));
			$cookie_value = $_COOKIE[$cookie_name];
			
			// cookie has not been set
			if(!isset($cookie_value)) {
				$cookie_value = uniqid();
				setcookie($cookie_name, $cookie_value);
			}
			
			SearchIndexLogs::setSessionIdFromCookie($cookie_value);
		}
		
		/**
		* Index this entry for search
		*
		* @param object $context
		*/
		public function indexEntry($context) {
			SearchIndex::indexEntry($context['entry']->get('id'), $context['entry']->get('section_id'));
		}
		
		/**
		* Delete this entry's search index
		*
		* @param object $context
		*/
		public function deleteEntryIndex($context) {
			if (is_array($context['entry_id'])) {
				foreach($context['entry_id'] as $entry_id) {
					SearchIndex::deleteIndexedEntriesByEntry($entry_id);
				}
			} else {
				SearchIndex::deleteIndexedEntriesByEntry($context['entry_id']);
			}
		}
		
		/*-------------------------------------------------------------------------
			Dashboard
		-------------------------------------------------------------------------*/
		
		public function dashboardPanelTypes($context) {
			$context['types']['search_index'] = "Search Index";
		}

		public function renderPanel($context) {
			$config = $context['config'];

			switch($context['type']) {
				case 'search_index':

					$logs = SearchIndex::getLogs('date', 'desc', 1);

					$thead = array(
						array(__('Date'), 'col'),
						array(__('Keywords'), 'col'),
						array(__('Results'), 'col')
					);
					$tbody = array();

					if (!is_array($logs) or empty($logs)) {
						$tbody = array(Widget::TableRow(array(
							Widget::TableData(
								__('No data available.'),
								'inactive',
								null,
								count($thead)
							)))
						);
					}
					
					else {

						foreach ($logs as $log) {
							$tbody[] = Widget::TableRow(
								array(
									Widget::TableData(DateTimeObj::get(__SYM_DATETIME_FORMAT__, strtotime($log['date']))),
									Widget::TableData($log['keywords']),
									Widget::TableData($log['results'])
								)
							);
						}
					}

					$table = Widget::Table(
						Widget::TableHead($thead), null,
						Widget::TableBody($tbody), null
					);
					$table->setAttribute('class', 'skinny');

					$context['panel']->appendChild($table);
					$context['panel']->appendChild(new XMLElement('p', '<a href="'.(URL . '/symphony/extension/search_index/logs/').'">' . __('View full search logs') . ' &#8594;</a>', array('style' => 'margin:0.7em;text-align:right;')));
					
				break;

			}
			
		}
		
		
	}
	