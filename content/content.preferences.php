<?php
	
	require_once(EXTENSIONS . '/search_index/lib/class.search_index_administrationpage.php');
	
	class contentExtensionSearch_IndexPreferences extends SearchIndex_AdministrationPage {
		
		public function __actionIndex() {
			
			$config = $_POST['config'];
			
			foreach($config as $name => $value) {
				if(is_array($value)) $config[$name] = implode(',', $value);
			}
			
			Symphony::Configuration()->setArray(array('search_index' => $config));
			Administration::instance()->saveConfig();
			
			$stopwords = explode(',', $_POST['stopwords']);
			SearchIndex::saveStopWords($stopwords);
			
			$this->pageAlert(
				__(
					'Preferences updated at %s. If you changed indexing settings uou need to <a href="%s">re-index your entries</a> for these changes to take effect.',
					array(
						DateTimeObj::getTimeAgo(__SYM_TIME_FORMAT__),
						$this->uri . '/indexes/'
					)
				),
				Alert::SUCCESS
			);
		}
		
		public function view() {
			parent::view();
			$this->setTitle(__('Symphony') . ' &ndash; ' . __('Search Index') . ' &ndash; ' . __('Preferences'));
		}
		
		public function __viewIndex() {
			
			$this->setPageType('form');
			$this->appendSubheading(__('Search Index') . ' &rsaquo; ' . __('Preferences'));
			
			$config = (object)Symphony::Configuration()->get('search_index');
		
		// Indexing Performance
		
			$fieldset = new XMLElement('fieldset');
			$fieldset->setAttribute('class', 'settings');
			$fieldset->appendChild(new XMLElement('legend', __('Indexing Performance')));
			$p = new XMLElement('p', __('Entries are processed in batches. Configure batch size to optimise performance.'));
			$p->setAttribute('class', 'help');
			$fieldset->appendChild($p);
		
			$group = new XMLElement('div');
			$group->setAttribute('class', 'group');
			
			$label = Widget::Label(__('Entries per batch'));
			$label->appendChild(Widget::Input(
				'config[re-index-per-page]',
				$config->{'re-index-per-page'}
			));
			$label->appendChild(new XMLElement('span', __('Fewer entries is less server-intensive, but indexing will take longer.'), array('class'=>'help')));
			$group->appendChild($label);
			
			$label = Widget::Label(__('Delay between batches (seconds)'));
			$label->appendChild(Widget::Input(
				'config[re-index-refresh-rate]',
				$config->{'re-index-refresh-rate'}
			));
			$label->appendChild(new XMLElement('span', __('Pausing between batches momentarily frees up sever power for your frontend visitors, but indexing will take longer.'), array('class'=>'help')));
			$group->appendChild($label);
			
			$fieldset->appendChild($group);
			$this->Form->appendChild($fieldset);
			
		// Keyword filtering
			
			$fieldset = new XMLElement('fieldset');
			$fieldset->setAttribute('class', 'settings');
			$fieldset->appendChild(new XMLElement('legend', __('Keyword Filtering')));
			$p = new XMLElement('p', __('Exclude unnecessary words to remove noise from your indexes, and improve the precision of search results. You will need to rebuild your indexes for these changes to take effect.'));
			$p->setAttribute('class', 'help');
			$fieldset->appendChild($p);
			
			$group = new XMLElement('div');
			$group->setAttribute('class', 'group');
			
			$label = Widget::Label(__('Minimum word length'));
			$label->appendChild(Widget::Input(
				'config[min-word-length]',
				$config->{'min-word-length'}
			));
			$label->appendChild(new XMLElement('span', __('Words shorter than this will not be indexed.'), array('class'=>'help')));
			$group->appendChild($label);
			
			$label = Widget::Label(__('Maximum word length'));
			$label->appendChild(Widget::Input(
				'config[max-word-length]',
				$config->{'max-word-length'}
			));
			$label->appendChild(new XMLElement('span', __('Words longer than this will not be indexed.'), array('class'=>'help')));
			$group->appendChild($label);
			
			$fieldset->appendChild($group);
			
			$label = Widget::Label('Stop words');
			$label->appendChild(Widget::Textarea(
				'stopwords',
				15, 40,
				implode(', ', SearchIndex::getStopWords())
			));
			$label->appendChild(new XMLElement('span', __('These are common terms that will be ignored. You should supplement this list with common "noise" words you find in your <a href="'.$this->uri.'/queries/'.'">query logs</a>. Separate multiple words with commas; e.g. who, what, when, where.'), array('class'=>'help')));
			$fieldset->appendChild($label);
			
			$this->Form->appendChild($fieldset);
			
		// Searching	
			
			$fieldset = new XMLElement('fieldset');
			$fieldset->setAttribute('class', 'settings');
			$fieldset->appendChild(new XMLElement('legend', __('Searching')));
			$p = new XMLElement('p', __('These options affect searches using both the Search Index field and custom data source.'));
			$p->setAttribute('class', 'help');
			$fieldset->appendChild($p);
			
			$group = new XMLElement('div');
			$group->setAttribute('class', 'group');
			
			$label = Widget::Label(__('Excerpt length'));
			$label->appendChild(Widget::Input(
				'config[excerpt-length]',
				$config->{'excerpt-length'} ? $config->{'excerpt-length'} : 200
			));
			$label->appendChild(new XMLElement('span', __('Number of characters to return in highlighted search excerpt. Length is approximate.'), array('class'=>'help')));
			$group->appendChild($label);
			
			$fieldset->appendChild($group);
			
			
			$group = new XMLElement('div');
			$group->setAttribute('class', 'group');
			
			$sub_group = new XMLElement('div');
			$label = Widget::Label();
			$input = Widget::Input('config[stem-words]', 'yes', 'checkbox');
			if($config->{'stem-words'} == 'yes') $input->setAttribute('checked', 'checked');
			$label->setValue($input->generate() . ' ' . __('Use word stemming'));
			$p = new XMLElement('p', __('Expand searches to normalise word stems e.g. "library" has a stem "librari" therefore will be found with "library", "libraries" or "librarian". Enabling this reduces the precision of results. Suitable for English only.'));
			$p->setAttribute('class', 'help');
			$sub_group->appendChild($label);
			$sub_group->appendChild($p);
			$group->appendChild($sub_group);
			
			$sub_group = new XMLElement('div');
			$label = Widget::Label();
			$input = Widget::Input('config[partial-words]', 'yes', 'checkbox');
			if($config->{'partial-words'} == 'yes') $input->setAttribute('checked', 'checked');
			$label->setValue($input->generate() . ' ' . __('Use partial matching'));
			$p = new XMLElement('p', __('Partial matching will ignore word boundaries and match the first letters of a word/phrase e.g. "cheeky monkey" will be found with the query "cheeky monk". If disabled, the query term must be found in its entirety. Enabling this reduces the precision of results. '));
			$p->setAttribute('class', 'help');
			$sub_group->appendChild($label);
			$sub_group->appendChild($p);
			$group->appendChild($sub_group);
			
			$fieldset->appendChild($group);
			
			
			
			$this->Form->appendChild($fieldset);			
		
		
		// Search Index Data Source	

			$fieldset = new XMLElement('fieldset');
			$fieldset->setAttribute('class', 'settings');
			$fieldset->appendChild(new XMLElement('legend', __('Data Source Options')));
			$p = new XMLElement('p', __('Configure how the custom Search Index data source operates. These options cannot be overwritten at runtime.'));
			$p->setAttribute('class', 'help');
			$fieldset->appendChild($p);
			
			$group = new XMLElement('div');
			$group->setAttribute('class', 'group');
			
			$label = Widget::Label(__('Search mode'));
			$label->appendChild(Widget::Select(
				'config[mode]',
				array(
					array('like', ($config->{'mode'} == 'like'), __('String pattern matching (LIKE)')),
					array('fulltext', ($config->{'mode'} == 'fulltext'), __('Boolean fulltext (MATCH AGAINST)'))
				)
			));
			$label->appendChild(new XMLElement('span', __('Fulltext search is generally faster, but has limitations of word length. String pattern matching uses a custom relevance algorithm whereas fulltext as native to MySQL.'), array('class'=>'help')));
			$group->appendChild($label);
			
			
			$fieldset->appendChild($group);
			
			$group = new XMLElement('div');
			$group->setAttribute('class', 'group');

			$sub_group = new XMLElement('div');
			$label = Widget::Label();
			$input = Widget::Input('config[build-entries]', 'yes', 'checkbox');
			if($config->{'build-entries'} == 'yes') $input->setAttribute('checked', 'checked');
			$label->setValue($input->generate() . ' ' . __('Build full entry XML'));
			$p = new XMLElement('p', __('Return all entry fields in the XML result, an alternative to data source chaining. Can reduce performance if entry contains many fields.'));
			$p->setAttribute('class', 'help');
			$sub_group->appendChild($label);
			$sub_group->appendChild($p);
			$group->appendChild($sub_group);

			$sub_group = new XMLElement('div');
			$label = Widget::Label();
			$input = Widget::Input('config[return-count-for-each-section]', 'yes', 'checkbox');
			if($config->{'return-count-for-each-section'} == 'yes') $input->setAttribute('checked', 'checked');
			$label->setValue($input->generate() . ' ' . __('Show query count for each section'));
			$p = new XMLElement('p', __('Run the search on every individual indexed section and return number of matched entries in XML.'));
			$p->setAttribute('class', 'help');
			$sub_group->appendChild($label);
			$sub_group->appendChild($p);
			$group->appendChild($sub_group);

			$fieldset->appendChild($group);
			
			$group = new XMLElement('div');
			$group->setAttribute('class', 'group');
			
			$sub_group = new XMLElement('div');
			$label = Widget::Label();
			$input = Widget::Input('config[log-keywords]', 'yes', 'checkbox');
			if($config->{'log-keywords'} == 'yes') $input->setAttribute('checked', 'checked');
			$label->setValue($input->generate() . ' ' . __('Keep a log of search queries'));
			$p = new XMLElement('p', __('Enabling this allows you to analyse search performance over time.'));
			$p->setAttribute('class', 'help');
			$sub_group->appendChild($label);
			$sub_group->appendChild($p);
			$group->appendChild($sub_group);
			
			$fieldset->appendChild($group);
			

			$this->Form->appendChild($fieldset);
			
			
			
		// Search Index Data Source	

			$fieldset = new XMLElement('fieldset');
			$fieldset->setAttribute('class', 'settings');
			$fieldset->appendChild(new XMLElement('legend', __('Data Source Defaults')));
			$p = new XMLElement('p', __('Default options for the custom Search Index data source. They can be overwritten at runtime by passing parameters to the data source.'));
			$p->setAttribute('class', 'help');
			$fieldset->appendChild($p);

			$group = new XMLElement('div');
			$group->setAttribute('class', 'group');

			$sm = new SectionManager(Symphony::Engine());
			$sections = array();
			foreach(SearchIndex::getIndexes() as $index) {
				$section = $sm->fetch($index['section_id']);
				$sections[] = array(
					$section->get('handle'),
					(in_array($section->get('handle'), explode(',', $config->{'default-sections'}))),
					$section->get('name')
				);
			}
			$label = Widget::Label(__('Default indexes'));
			$label->appendChild(Widget::Select(
				'config[default-sections][]',
				$sections,
				array('multiple' => 'multiple')
			));
			$label->appendChild(new XMLElement('span', __('Section indexes to query if none specified in page parameters.'), array('class'=>'help')));
			$group->appendChild($label);

			$label = Widget::Label(__('Default results per page'));
			$label->appendChild(Widget::Input(
				'config[default-per-page]',
				$config->{'default-per-page'}
			));
			$label->appendChild(new XMLElement('span', __('Number of results returned per page if not specified in page parameters.'), array('class'=>'help')));
			$group->appendChild($label);

			$fieldset->appendChild($group);


			$group = new XMLElement('div');
			$group->setAttribute('class', 'group');

			$label = Widget::Label(__('Default sort field'));
			$label->appendChild(Widget::Select(
				'config[default-sort]',
				array(
					array('id', ($config->{'default-sort'} == 'id'), __('System ID (id)')),
					array('date', ($config->{'default-sort'} == 'date'), __('System Date (date)')),
					array('score', ($config->{'default-sort'} == 'score'), __('Relevance (score)')),
					array('score-recency', ($config->{'default-sort'} == 'score-recency'), __('Relevance with System Date (score-recency)')),
				)
			));
			$label->appendChild(new XMLElement('span', __('Search results sort column if none specified in page parameters.'), array('class'=>'help')));
			$group->appendChild($label);

			$label = Widget::Label(__('Default sort direction'));
			$label->appendChild(Widget::Select(
				'config[default-sort]',
				array(
					array('asc', ($config->{'default-direction'} == 'asc'), __('Ascending (asc)')),
					array('desc', ($config->{'default-direction'} == 'desc'), __('Descending (desc)'))
				)
			));
			$label->appendChild(new XMLElement('span', __('Search results sort direction if none specified in page parameters.'), array('class'=>'help')));
			$group->appendChild($label);

			$fieldset->appendChild($group);

			$this->Form->appendChild($fieldset);
			
			
			
			
			
		// Search Index Data Source Parameters	

			$fieldset = new XMLElement('fieldset');
			$fieldset->setAttribute('class', 'settings');
			$fieldset->appendChild(new XMLElement('legend', __('Data Source Parameters')));
			$p = new XMLElement('p', __('These are the names of page parameters used to pass context to the custom Search Index data source. Use either page parameters (<code>{$keywords}</code>) or URL parameters (<code>{$url-keywords}</code>).'));
			$p->setAttribute('class', 'help');
			$fieldset->appendChild($p);

			$group = new XMLElement('div');
			$group->setAttribute('class', 'group');

			$label = Widget::Label(__('Keywords'));
			$label->appendChild(Widget::Input(
				'config[param-keywords]',
				$config->{'param-keywords'}
			));
			$label->appendChild(new XMLElement('span', __('Search query.'), array('class'=>'help')));
			$group->appendChild($label);

			$label = Widget::Label(__('Indexes'));
			$label->appendChild(Widget::Input(
				'config[param-sections]',
				$config->{'param-sections'}
			));
			$label->appendChild(new XMLElement('span', __('Indexes in which to search. Value expects a comma-delimited list of section handles. If parameter not set, defaults to value specified in defaults section above.'), array('class'=>'help')));
			$group->appendChild($label);

			$fieldset->appendChild($group);
			
			$group = new XMLElement('div');
			$group->setAttribute('class', 'group');

			$label = Widget::Label(__('Results per page'));
			$label->appendChild(Widget::Input(
				'config[param-per-page]',
				$config->{'param-per-page'}
			));
			$label->appendChild(new XMLElement('span', __('Number of entries to return per page of results. If parameter not set, defaults to value specified in defaults section above.'), array('class'=>'help')));
			$group->appendChild($label);

			$label = Widget::Label(__('Indexes'));
			$label->appendChild(Widget::Input(
				'config[param-page]',
				$config->{'param-page'}
			));
			$label->appendChild(new XMLElement('span', __('Page number of results. If parameter not set, defaults to 1.'), array('class'=>'help')));
			$group->appendChild($label);

			$fieldset->appendChild($group);

			$group = new XMLElement('div');
			$group->setAttribute('class', 'group');

			$label = Widget::Label(__('Sort field'));
			$label->appendChild(Widget::Input(
				'config[param-sort]',
				$config->{'param-sort'}
			));
			$label->appendChild(new XMLElement('span', __('Expects a value from the sort field list above (id, date, score, score-recency). If parameter not set, defaults to value specified in defaults section above.'), array('class'=>'help')));
			$group->appendChild($label);

			$label = Widget::Label(__('Sort direction'));
			$label->appendChild(Widget::Input(
				'config[param-direction]',
				$config->{'param-direction'}
			));
			$label->appendChild(new XMLElement('span', __('Expects a value from the sort direction list above (asc, desc). If parameter not set, defaults to value specified in defaults section above.'), array('class'=>'help')));
			$group->appendChild($label);

			$fieldset->appendChild($group);
			
			
			$this->Form->appendChild($fieldset);
	
		
			
			$div = new XMLElement('div');
			$div->setAttribute('class', 'actions');
			$div->appendChild(
				Widget::Input('action[save]',
					__('Save Changes'),
					'submit', array(
						'accesskey'		=> 's'
					)
				)
			);
						
			$this->Form->appendChild($div);
			
		}
			
	}