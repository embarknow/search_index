<?php
	
	require_once(EXTENSIONS . '/search_index/lib/class.search_index.php');
	
	Class fieldSearch_Index_Filter extends Field{	
		
		private $keywords_highlight = NULL;
		
		/**
		* Class constructor
		*/
		function __construct(&$parent){
			parent::__construct($parent);
			$this->_name = __('Search Index Filter');
			$this->_required = FALSE;			
			$this->set('hide', 'no');
		}
		
		/**
		* Allow filtering through a Data Source
		*/
		function canFilter(){
			return TRUE;
		}
		
		/**
		* Process POST data for entry saving
		*/
		public function processRawFieldData($data, &$status, $simulate=FALSE, $entry_id=NULL) {	
			$status = self::__OK__;			
			return array('value' => '');
		}
		
		/**
		* Persist field configuration
		*/
		function commit(){
			// set up standard Field settings
			if(!parent::commit()) return FALSE;
			
			$id = $this->get('id');
			if($id === FALSE) return FALSE;
			
			$fields = array();
			$fields['field_id'] = $id;
			
			// delete existing field configuration
			Symphony::Database()->query("DELETE FROM `tbl_fields_".$this->handle()."` WHERE `field_id` = '$id' LIMIT 1");
			// save new field configuration
			return Symphony::Database()->insert($fields, 'tbl_fields_' . $this->handle());
		}

		/**
		* Building HTML for entry form
		*
		* @param XMLElement $wrapper
		* @param array $data
		* @param boolean $flagWithError
		* @param string $fieldnamePrefix
		* @param string $fieldnamePostfix
		*/
		function displayPublishPanel(&$wrapper, $data=NULL, $flagWithError=NULL, $fieldnamePrefix=NULL, $fieldnamePostfix=NULL){
			$value = $data['value'];					
			$label = Widget::Label($this->get('label'));
			if($this->get('required') != 'yes') $label->appendChild(new XMLElement('i', __('Optional')));			
		}
		
		/**
		* Building HTML for section editor
		*
		* @param XMLElement $wrapper
		* @param array $data
		* @param array $errors
		* @param boolean $flagWithError
		* @param string $fieldnamePrefix
		* @param string $fieldnamePostfix
		*/
		public function displayDatasourceFilterPanel(&$wrapper, $data=NULL, $errors=NULL, $fieldnamePrefix=NULL, $fieldnamePostfix=NULL){
			$wrapper->appendChild(new XMLElement('h4', $this->get('label') . ' <i>'.$this->Name().'</i>'));
			$label = Widget::Label(__('Value'));
			$label->appendChild(Widget::Input('fields[filter]'.($fieldnamePrefix ? '['.$fieldnamePrefix.']' : '').'['.$this->get('id').']'.($fieldnamePostfix ? '['.$fieldnamePostfix.']' : ''), ($data ? General::sanitize($data) : NULL)));	
			$wrapper->appendChild($label);
		}
		
		/**
		 * Append the formatted xml output of this field as utilized as a data source.
		 *
		 * @param XMLElement $wrapper
		 * @param array $data
		 * @param boolean $encode (optional)
		 * @param string $mode
		 * @param integer $entry_id (optional)
		 */
		public function appendFormattedElement(XMLElement &$wrapper, $data, $encode = false, $mode = null, $entry_id = null) {
			
			$excerpt = Symphony::Database()->fetchVar('data', 0,
				sprintf("SELECT `data` FROM tbl_search_index_data WHERE entry_id = %d LIMIT 0, 1", $entry_id)
			);
			
			$excerpt = SearchIndex::parseExcerpt($this->keywords_highlight, $excerpt);
			
			$wrapper->appendChild(
				new XMLElement($this->get('element_name'), $excerpt)
			);
		}
		
		/**
		* Create table to hold field instance's values
		*/		
		public function createTable(){
			return Symphony::Database()->query(			
				"CREATE TABLE IF NOT EXISTS `tbl_entries_data_" . $this->get('id') . "` (
				  `id` int(11) unsigned NOT NULL auto_increment,
				  `entry_id` int(11) unsigned NOT NULL,
				  `value` double default NULL,
				  PRIMARY KEY  (`id`),
				  KEY `entry_id` (`entry_id`),
				  KEY `value` (`value`)
				) TYPE=MyISAM;"			
			);
		}
		
		/**
		* Build SQL for Data Source filter
		*
		* @param array $data
		* @param string $joins
		* @param string $where
		* @param boolean $andOperation
		*/
		function buildDSRetrivalSQL($data, &$joins, &$where, $andOperation=FALSE){
			$field_id = $this->get('id');
			
			if (!is_array($data)) $data = array($data);
			if (is_array($data)) $data = implode(' ', $data);
			
			$keywords_raw = $data;
			$keywords_parsed = SearchIndex::parseKeywordString($keywords_raw);
			$phrases = $keywords_parsed->phrases;
			$this->keywords_highlight = $keywords_parsed->highlight;
			$keywords_synonyms = SearchIndex::applySynonyms($keywords_raw);
			
			$config = (object)Symphony::Configuration()->get('search_index');
			
			$mode = !is_null($config->{'mode'}) ? $config->{'mode'} : 'like';
			$mode = strtoupper($mode);
			
			if($mode == 'FULLTEXT') {
				$sql_where = SearchIndex::buildBooleanWhere($data);
			}
			elseif($mode == 'LIKE') {
				$sql = (object)array();
				SearchIndex::buildLikeWhere($phrases, $config, $sql);
				$sql_where = $sql->where;
			}					
			
			$joins .= " LEFT JOIN `tbl_search_index_data` AS `search_index` ON (e.id = search_index.entry_id) ";
			if(count($phrases) > 0) $where .= " AND " . $sql_where . " ";
			
			return TRUE;
			
		}
						
	}

?>