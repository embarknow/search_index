<?php
	
	require_once(TOOLKIT . '/class.administrationpage.php');
	require_once(EXTENSIONS . '/search_index/lib/class.search_index.php');
	require_once(EXTENSIONS . '/search_index/lib/class.search_index_logs.php');
	
	class SearchIndex_AdministrationPage extends AdministrationPage {
		
		protected $filter = NULL;
		protected $sort = NULL;
		protected $pagination = NULL;
		
		public function view() {
			$this->addStylesheetToHead(URL . '/extensions/search_index/assets/search_index.css', 'screen', 100);
			$this->addScriptToHead(URL . '/extensions/search_index/assets/search_index.analysis.js', 100);			
		}
		
		protected function __buildPagination($pagination) {
			
			$ul = new XMLElement('ul');
			$ul->setAttribute('class', 'page');
			
			// first
			$li = new XMLElement('li');
			if ($pagination->{'current-page'} > 1) {
				$li->appendChild(
					Widget::Anchor(
						__('First'),
						$this->__buildURL(array('pagination->current-page' => 1))
					)
				);
			} else {
				$li->setValue(__('First'));
			}
			$ul->appendChild($li);
			
			// previous
			$li = new XMLElement('li');
			if ($pagination->{'current-page'} > 1) {
				$li->appendChild(
					Widget::Anchor(
						__('&larr; Previous'),
						$this->__buildURL(array('pagination->current-page' => $pagination->{'current-page'} - 1))
					)
				);
			} else {
				$li->setValue('&larr; ' . __('Previous'));
			}				
			$ul->appendChild($li);

			// summary
			$li = new XMLElement('li', __('Page %1$s of %2$s', array($pagination->{'current-page'}, max($pagination->{'current-page'}, $pagination->{'total-pages'}))));
			$li->setAttribute('title', __('Viewing %1$s - %2$s of %3$s entries', array(
				$pagination->start,
				$pagination->end,
				$pagination->{'total-entries'}
			)));
			$ul->appendChild($li);

			// next
			$li = new XMLElement('li');				
			if ($pagination->{'current-page'} < $pagination->{'total-pages'}) {
				$li->appendChild(
					Widget::Anchor(
						__('Next &rarr;'),
						$this->__buildURL(array('pagination->current-page' => ($pagination->{'current-page'} + 1)))
					)
				);
			} else {
				$li->setValue(__('Next') . ' &rarr;');
			}				
			$ul->appendChild($li);

			// last
			$li = new XMLElement('li');
			if ($pagination->{'current-page'} < $pagination->{'total-pages'}) {
				$li->appendChild(
					Widget::Anchor(
						__('Last'),
						$this->__buildURL(array('pagination->current-page' => $pagination->{'total-pages'}))
					)
				);
			} else {
				$li->setValue(__('Last'));
			}				
			$ul->appendChild($li);
			
			return $ul;
		}
		
		protected function __buildColumnHeader($label='', $column_name, $default_direction) {
			if($default_direction == 'asc') {
				$direction = 'asc';
				$direction_reverse = 'desc';
			} else {
				$direction = 'desc';
				$direction_reverse = 'asc';
			}
			return array(
				Widget::Anchor(
					$label,
					$this->__buildURL(
						array(
							'pagination->current-page' => 1,
							'sort->column' => $column_name,
							'sort->direction' => (($this->sort->column == $column_name && $this->sort->direction == $direction) ? $direction_reverse : $direction))
					),
					'',
					($this->sort->column == $column_name ? 'active' : '')
				),
				'col',
				array(
					'class' => $column_name
				)
			);
		}
		
		protected function __buildURL($override=array()) {
	
			$sort = clone $this->sort;
			$filter = clone $this->filter;
			$pagination = clone $this->pagination;
			
			foreach($override as $context => $value) {
				$group = reset(explode('->', $context));
				$key = end(explode('->', $context));
				switch($group) {
					case 'sort': $sort->{$key} = $value; break;
					case 'filter': $filter->{$key} = $value; break;
					case 'pagination': $pagination->{$key} = $value; break;
				}
			}
			
			$parameters = array(
				'sort' => $sort,
				'filter' => $filter,
				'pagination' => $pagination
			);
			
			foreach($parameters as $name => $group) {
				foreach($group as $key => $value) {
					if(empty($value) || ($name == 'pagination' && $key != 'current-page')) continue;
					$url .= sprintf('%s[%s]=%s&amp;', (string)$name, (string)$key, (string)$value);
				}
			}
			
			$url = preg_replace("/&amp;$/", '', $url);
			return Administration::instance()->getCurrentPageURL() . '?' . $url;
		}
		
	}