<?php
	
	require_once(EXTENSIONS . '/search_index/lib/class.search_index_administrationpage.php');
	
	require_once(EXTENSIONS . '/search_index/lib/class.drawer.php');
	
	
	
	class contentExtensionSearch_IndexQueries extends SearchIndex_AdministrationPage {
						
		public function view() {
			
			parent::view(FALSE);
			
			// Get URL parameters, set defaults
			/*-----------------------------------------------------------------------*/	
			$sort = (object)$_GET['sort'];
			$filter = (object)$_GET['filter'];
			$pagination = (object)$_GET['pagination'];
			
			if (!isset($sort->column)) $sort->column = 'count';
			if (!isset($sort->direction)) $sort->direction = 'desc';
			
			if (!isset($filter->keywords) || empty($filter->keywords)) $filter->keywords = NULL;
			if (!isset($filter->date_from) || empty($filter->date_from)) $filter->date_from = date('Y-m-d', strtotime('last month'));
			if (!isset($filter->date_to) || empty($filter->date_to)) $filter->date_to = date('Y-m-d', strtotime('today'));
			if (!isset($filter->average_results['value']) || !is_numeric($filter->average_results['value'])) $filter->average_results = NULL;
			if (!isset($filter->average_depth['value']) || $filter->average_depth['value'] == '') $filter->average_depth = NULL;
			
			if(is_array($filter->average_results)) $filter->average_results = implode('', $filter->average_results);
			if(is_array($filter->average_depth)) $filter->average_depth = implode('', $filter->average_depth);
			
			$output_mode = $_GET['output'];
			if (!isset($output_mode)) $output_mode = 'table';
			
			// Build pagination and fetch rows
			/*-----------------------------------------------------------------------*/
			$pagination->{'per-page'} = 50;
			$pagination->{'current-page'} = (@(int)$pagination->{'current-page'} > 1 ? (int)$pagination->{'current-page'} : 1);
			
			// get the logs!
			$rows = SearchIndexLogs::getQueries(
				$sort->column, $sort->direction,
				$pagination->{'current-page'}, $pagination->{'per-page'},
				$filter
			);
			
			// total number of unique query terms
			$pagination->{'total-entries'} = SearchIndexLogs::getTotalQueries($filter);
			
			$pagination->start = max(1, (($pagination->{'current-page'} - 1) * $pagination->{'per-page'}));
			$pagination->end = ($pagination->start == 1 ? $pagination->{'per-page'} : $pagination->start + count($rows));
			$pagination->{'total-pages'} = ceil($pagination->{'total-entries'} / $pagination->{'per-page'});
			
			// sum of the "count" column for all queries i.e. total number of searches
			$total_search_count = SearchIndexLogs::getSearchCount($filter);

			// cache amended filters for use elsewhere
			$this->sort = $sort;
			$this->filter = $filter;
			$this->pagination = $pagination;
			
			
			
			$filters_drawer = new Drawer('Filters', $this->__buildDrawerHTML($filter));
			$this->addStylesheetToHead(URL . '/extensions/search_index/assets/drawer.publish.css', 'screen', 100);
			$this->addScriptToHead(URL . '/extensions/search_index/assets/drawer.publish.js', 101);
			
			$this->addStylesheetToHead(URL . '/extensions/search_index/assets/ui.daterangepicker.css', 'screen', 102);
			$this->addScriptToHead(URL . '/extensions/search_index/assets/jquery-ui-1.7.1.custom.min.js', 103);
			$this->addScriptToHead(URL . '/extensions/search_index/assets/daterangepicker.jQuery.js', 104);
			
			
			// Set up page meta data
			/*-----------------------------------------------------------------------*/	
			
			$this->setPageType('table');
			$this->setTitle(__('Symphony') . ' &ndash; ' . __('Search Index') . ' &ndash; ' . __('Query Logs'));
			$this->appendSubheading(
				__('Search Index') . ' &rsaquo; ' . __('Query Logs') .
				Widget::Anchor(
					__('Export CSV'),
					$this->__buildURL(NULL, array('output' => 'csv')),
					NULL,
					'button'
				)->generate() . 
				$filters_drawer->button->generate()
			);
			
			$this->Contents->appendChild($filters_drawer->drawer);
			
			
			// Build table
			/*-----------------------------------------------------------------------*/
								
			$tableHead = array();
			$tableBody = array();
			
			// append table headings
			$tableHead[] = array(__('Rank'), 'col');
			$tableHead[] = $this->__buildColumnHeader(__('Query'), 'keywords', 'asc');
			$tableHead[] = $this->__buildColumnHeader(__('Count'), 'count', 'desc');
			$tableHead[] = array(__('%'), 'col');
			$tableHead[] = array(__('Cumulative %'), 'col');
			$tableHead[] = $this->__buildColumnHeader(__('Avg. results'), 'average_results', 'desc');
			$tableHead[] = $this->__buildColumnHeader(__('Avg. depth'), 'average_depth', 'desc');
			
			// no rows
			if (!is_array($rows) or empty($rows)) {
				$tableBody = array(
					Widget::TableRow(array(
						Widget::TableData(__('None Found.'), 'inactive', NULL, count($tableHead))
					))
				);
			}
			// we have rows
			else {
				
				// if not on the first page, the cululative percent column needs to start from the
				// column total of the previous page. Calling this method queries a dataset the size
				// of all previous pages, sums and returns the totals from all
				if($pagination->{'current-page'} > 1) {
					$cumulative_total = SearchIndexLogs::getCumulativeSearchCount(
						$sort->column, $sort->direction,
						$pagination->{'current-page'}, $pagination->{'per-page'},
						$filter
					);
				}
				
				// rank starts from 1 on first page
				$rank = ($pagination->start == 1) ? $pagination->start : $pagination->start + 1;
				// initial percentage to start from (cumulative)
				$cumulative_percent = ($cumulative_total / $total_search_count) * 100;
				
				foreach ($rows as $row) {
					
					$row_percent = ($row['count'] / $total_search_count) * 100;
					$cumulative_percent += $row_percent;
					
					$r = array();
					$r[] = Widget::TableData($rank, 'rank');
					$r[] = Widget::TableData(
						(empty($row['keywords']) ? __('None') : $row['keywords']),
						(empty($row['keywords']) ? 'inactive query' : 'query')
					);
					$r[] = Widget::TableData($row['count'], 'count');
					$r[] = Widget::TableData((number_format($row_percent, 2)) . '%', 'percent');
					$r[] = Widget::TableData((number_format($cumulative_percent, 2)) . '%', 'percent');
					$r[] = Widget::TableData(number_format($row['average_results'], 1), 'average-results');
					$r[] = Widget::TableData(number_format($row['average_depth'], 1), 'average-depth');
					
					$tableBody[] = Widget::TableRow($r);
					
					$rank++;
					
				}
				
			}
			
			if($output_mode == 'csv') {
				
				$file_path = sprintf('%s/search-index.query-log.%d.csv', TMP, time());
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
				
				fputcsv($csv, $columns, ',', '"');

				foreach($tableBody as $tr) {
					$cells = $tr->getChildren();
					$data = array();
					foreach($cells as $td) {
						$data[] = $td->getValue();
					}
					fputcsv($csv, $data, ',', '"');
				}
				
				fclose($csv);
				
				header('Content-type: application/csv');
				header('Content-Disposition: attachment; filename="' . end(explode('/', $file_path)) . '"');
				readfile($file_path);
				unlink($file_path);
				
				exit;
				
			}
			
			// append the table
			$table = Widget::Table(Widget::TableHead($tableHead), NULL, Widget::TableBody($tableBody));
			$this->Form->appendChild($table);
			
			// build pagination
			if ($pagination->{'total-pages'} > 1) {
				$this->Form->appendChild($this->__buildPagination($pagination));
			}

		}
		
		private function __buildDrawerHTML($filter) {
			
			$form = new XMLElement('form', NULL, array('action' => '', 'method' => 'get'));
			
			$range = SearchIndexLogs::getDateRange();
			
			$label = new XMLElement('div', NULL, array(
				'data-dateMin' => date('Y-m-d', strtotime($range->min)),
				'data-dateMax' => date('Y-m-d', strtotime($range->max)),
				'class' => 'label date-range'
			));
			$label->appendChild(new XMLElement('span', _('Date range')));
			$label->appendChild(new XMLElement('input', NULL, array(
				'type' => 'text',
				'placeholder' => __('From'),
				'name' => 'filter[date_from]',
				'value' => $filter->date_from
			)));
			$label->appendChild(new XMLElement('span', __('to'), array('class' => 'conjunctive')));
			$label->appendChild(new XMLElement('input', NULL, array(
				'type' => 'text',
				'placeholder' => __('To'),
				'name' => 'filter[date_to]',
				'value' => $filter->date_to
			)));
			$form->appendChild($label);
			
			// generate a random noun
			$password = General::generatePassword();
			$password = preg_replace('/[0-9]/', '', $password); // remove numbers
			preg_match('/([A-Z][a-z]+){1,}/', $password, $nouns); // split into separate words based on capitals
			$noun = strtolower(end($nouns));
			
			$label = new XMLElement('label', '<span>'.__('Keywords').'</span>', array('class' => 'keywords'));
			$label->appendChild(new XMLElement('input', NULL, array(
				'placeholder' => __('e.g. %s', array($noun)),
				'name' => 'filter[keywords]',
				'value' => $filter->keywords
			)));
			$form->appendChild($label);
			
			$label = new XMLElement('div', __('Query returned an average of'), array('class' => 'label performance'));
			$span = new XMLElement('span');
			$span->appendChild(Widget::Select('filter[average_results][compare]', array(
				array('=', preg_match('/^\=/', $filter->average_results), 'exactly'),
				array('<', preg_match('/^\</', $filter->average_results), 'less than'),
				array('>', preg_match('/^\>/', $filter->average_results), 'more than')
			)));
			$span->appendChild(new XMLElement('input', NULL, array(
				'type' => 'text',
				'name' => 'filter[average_results][value]',
				'value' => trim($filter->average_results, '=<>')
			)));
			
			$span->appendChild(new XMLElement('span', ' ' . __('result(s)')));
			$label->appendChild($span);
			$form->appendChild($label);
			
			$label = new XMLElement('div', __('Users visited depth of'), array('class' => 'label performance'));
			$span = new XMLElement('span');
			$span->appendChild(Widget::Select('filter[average_depth][compare]', array(
				array('=', preg_match('/^\=/', $filter->average_depth), 'exactly'),
				array('<', preg_match('/^\</', $filter->average_depth), 'less than'),
				array('>', preg_match('/^\>/', $filter->average_depth), 'more than')
			)));
			$span->appendChild(new XMLElement('input', NULL, array(
				'type' => 'text',
				'name' => 'filter[average_depth][value]',
				'value' => trim($filter->average_depth, '=<>')
			)));
			$span->appendChild(new XMLElement('span', ' ' . __('page(s)')));
			$label->appendChild($span);
			$form->appendChild($label);
			
			$form->appendChild(new XMLElement('input', NULL, array('type' => 'submit', 'value' => __('Apply Filters'), 'class' => 'button')));
			$form->appendChild(new XMLElement('input', NULL, array('type' => 'button', 'value' => __('Clear Filters'), 'class' => 'secondary button')));
			
			return $form->generate();
		}
		
	}