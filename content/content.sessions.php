<?php
	
	require_once(EXTENSIONS . '/search_index/lib/class.search_index_administrationpage.php');
	require_once(EXTENSIONS . '/search_index/lib/phpbrowscap/browscap/Browscap.php');

	class contentExtensionSearch_IndexSessions extends SearchIndex_AdministrationPage {
		
		public function build($context) {
			parent::build($context);
			if (isset($_POST['filter']['keyword']) != '') {
				redirect(Administration::instance()->getCurrentPageURL() . '?keywords=' . $_POST['keywords']);
			}
		}
						
		public function view() {
			
			parent::view(FALSE);
			
			// Get URL parameters, set defaults
			/*-----------------------------------------------------------------------*/	
			$sort = (object)$_GET['sort'];
			$filter = (object)$_GET['filter'];
			$pagination = (object)$_GET['pagination'];
			
			if (!isset($sort->column)) $sort->column = 'date';
			if (!isset($sort->direction)) $sort->direction = 'desc';
			if (!isset($filter->keywords)) $filter->keywords = NULL;
			if (!isset($filter->session_id)) $filter->session_id = NULL;
			if (!isset($filter->date_from)) $filter->date_from = date('Y-m-d', strtotime('last month'));
			if (!isset($filter->date_to)) $filter->date_to = date('Y-m-d', strtotime('today'));
			
			$output_mode = $_GET['output'];
			if (!isset($output_mode)) $output_mode = 'table';
			
			// Build pagination and fetch rows
			/*-----------------------------------------------------------------------*/
			$pagination->{'per-page'} = (int)Symphony::Configuration()->get('pagination_maximum_rows', 'symphony');
			$pagination->{'current-page'} = (@(int)$pagination->{'current-page'} > 1 ? (int)$pagination->{'current-page'} : 1);
			
			// get the logs!
			$rows = SearchIndexLogs::getSessions(
				$sort->column, $sort->direction,
				$pagination->{'current-page'}, $pagination->{'per-page'},
				$filter
			);
			
			// total number of unique query terms
			$pagination->{'total-entries'} = SearchIndexLogs::getTotalSessions($filter);
			
			$pagination->start = max(1, (($pagination->{'current-page'} - 1) * $pagination->{'per-page'}));
			$pagination->end = ($pagination->start == 1 ? $pagination->{'per-page'} : $pagination->start + count($rows));
			$pagination->{'total-pages'} = ceil($pagination->{'total-entries'} / $pagination->{'per-page'});

			// cache amended filters for use elsewhere
			$this->sort = $sort;
			$this->filter = $filter;
			$this->pagination = $pagination;
			
			
			// Set up page meta data
			/*-----------------------------------------------------------------------*/	
			
			$this->setPageType('table');
			$this->setTitle(__('Symphony') . ' &ndash; ' . __('Search Index') . ' &ndash; ' . __('Session Logs'));
			$this->appendSubheading(
				__('Search Index') . ' &rsaquo; ' . __('Session Logs') .
				Widget::Anchor(
					__('Export CSV'),
					$this->__buildURL(NULL, array('output' => 'csv')),
					NULL,
					'button'
				)->generate()
			);
			
			
			/*$stats = array(
				'unique-users' => SearchIndexLogs::getStatsCount('unique-users', $filter),
				'unique-searches' => SearchIndexLogs::getStatsCount('unique-searches', $filter),
				'unique-terms' => SearchIndexLogs::getStatsCount('unique-terms', $filter),
				'average-results' => SearchIndexLogs::getStatsCount('average-results', $filter)
			);
			
			$filters = new XMLElement('div', NULL, array('class' => 'search-index-log-filters'));
			$label = new XMLElement('label', __('Filter searches containing the keywords %s', array(Widget::Input('keywords', $filter_keywords)->generate())));
			$filters->appendChild($label);
			$filters->appendChild(new XMLElement('input', NULL, array('type' => 'submit', 'value' => __('Filter'), 'name' => 'filter[keyword]')));
			
			$filters->appendChild(new XMLElement('p', sprintf(__('<strong>%s</strong> unique searches from <strong>%s</strong> unique users via <strong>%s</strong> distinct search terms. Each search yielded an average of <strong>%s</strong> results.', array($stats['unique-searches'], $stats['unique-users'], $stats['unique-terms'], $stats['average-results']))), array('class' => 'intro')));
			
			$this->Form->appendChild($filters);*/
			
			$tableHead = array();
			$tableBody = array();
			
			// append table headings
			$tableHead[] = $this->__buildColumnHeader(__('Date'), 'date', 'desc');
			$tableHead[] = array(__('Query'), 'keywords');
			$tableHead[] = $this->__buildColumnHeader(__('Results'), 'results', 'desc');
			$tableHead[] = $this->__buildColumnHeader(__('Depth'), 'depth', 'desc');
			$tableHead[] = array(__('Session ID'));
			$tableHead[] = array(__('IP Address'));
			$tableHead[] = array(__('Browser'));
			
			if (!is_array($rows) or empty($rows)) {
				$tableBody = array(
					Widget::TableRow(array(Widget::TableData(__('None Found.'), 'inactive', null, count($tableHead))))
				);
			}
			
			else {
				
				$browscap = new Browscap(CACHE);
				
				$alt = FALSE;
				foreach ($rows as $row) {
					
					if(!empty($row['user_agent'])) {
						$browser = $browscap->getBrowser($row['user_agent']);
						$browser_string = sprintf('%s %s (%s)', $browser->Browser, $browser->MajorVer, $browser->Platform);
					} else {
						$browser_string = '';
					}
					
					$searches = SearchIndexLogs::getSessionSearches($row['session_id']);
					
					foreach($searches as $i => $search) {
						
						$r = array();
						//$r[] = Widget::TableData('', NULL, NULL, 3);
						$r[] = Widget::TableData(
							DateTimeObj::get(
								__SYM_DATETIME_FORMAT__,
								strtotime($search['date'])
							),
							'date'
						);

						$keywords = $search['keywords'];
						$keywords_class = '';
						if ($keywords == '') {
							$keywords = __('None');
							$keywords_class = 'inactive';
						}
						
						$r[] = Widget::TableData(stripslashes($keywords), $keywords_class . ' keywords');
						$r[] = Widget::TableData($search['results'], 'results');
						$r[] = Widget::TableData($search['page'], 'depth');
						
						if($i == 0) {
							$r[] = Widget::TableData($row['session_id'], 'inactive');
							$r[] = Widget::TableData(empty($row['ip']) ? __('None') : $row['ip'], 'inactive');
							$r[] = Widget::TableData(empty($browser_string) ? __('None') : '<span title="'.$row['user_agent'].'">' . $browser_string . '</span>', 'inactive');
						} else {
							$r[] = Widget::TableData('', NULL, NULL, 3);
						}

						$tableBody[] = Widget::TableRow($r, 'search ' . ($alt ? 'alt' : '') . ($i == (count($searches) - 1) ? ' last' : ''));
						
					}
					
					$alt = !$alt;
					
				}
			}
			
			if($output_mode == 'csv') {
				
				$file_path = sprintf('%s/search-index.session-log.%d.csv', TMP, time());
				$csv = fopen($file_path, 'w');
				
				$columns = array();
				foreach($tableHead as $i => $heading) {
					$element = reset($heading);
					if($element instanceOf XMLElement) {
						$columns[] = reset($heading)->getValue();
					} else {
						$columns[] = (string)$element;
					}
				}
				$columns[] = 'Session ID';
				$columns[] = 'User Agent';
				$columns[] = 'IP';
				
				fputcsv($csv, $columns, ',', '"');

				$meta = array();

				foreach($tableBody as $tr) {
					$cells = $tr->getChildren();
					if(preg_match("/session-meta/", $tr->getAttribute('class'))) {
						$meta = array();
						foreach($cells as $i => $td) {
							switch($i) {
								case 0: $meta['session_id'] = $td->getValue(); break;
								case 1: $meta['user_agent'] = $td->getValue(); break;
								case 2: $meta['ip'] = $td->getValue(); break;
							}
						}
					} else {
						$data = array();
						foreach($cells as $td) {
							$data[] = $td->getValue();
						}
						$data[] = $meta['session_id'];
						$data[] = $meta['user_agent'];
						$data[] = $meta['ip'];
						fputcsv($csv, $data, ',', '"');
					}
					
				}
				
				fclose($csv);
				
				header('Content-type: application/csv');
				header('Content-Disposition: attachment; filename="' . end(explode('/', $file_path)) . '"');
				readfile($file_path);
				unlink($file_path);
				
				exit;
				
			}
			
			$table = Widget::Table(Widget::TableHead($tableHead), NULL, Widget::TableBody($tableBody), 'sessions');
			$this->Form->appendChild($table);
			
			// build pagination
			if ($pagination->{'total-pages'} > 1) {
				$this->Form->appendChild($this->__buildPagination($pagination));
			}

		}
				
	}