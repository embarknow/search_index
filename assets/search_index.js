Symphony.Language.add({
	'Indexing page {$page} of {$total}': false,
	'{$total} entries': false,
	'{$total} entry': false
});

var SearchIndex_Indexes = {

	sections: [],
	progress: 0,
	refresh_rate: 0,

	init: function() {

		var self = this;
		
		// new index section selection
		jQuery('select[name="fields[section_id]"]').bind('change', function() {
			var id = jQuery(this).val();
			window.location.href = Symphony.Context.get('root') + '/symphony/extension/search_index/indexes/new/' + id + '/';
		});

		// cache IDs of sections to re-index
		jQuery('span.to-re-index').each(function() {
			var span = jQuery(this);
			self.sections.push(span.attr('id').replace(/section\-/,''));
			span.removeClass('to-re-index');
		});

		this.refresh_rate = Symphony.Context.get('search_index')['re-index-refresh-rate'] * 1000;

		// go, go, go
		this.indexNextSection();
		
		this.buildDrawerFilters();

	},

	indexNextSection: function() {
		if (this.sections.length == this.progress) return;
		this.indexSectionByPage(this.sections[this.progress], 1);
	},

	indexSectionByPage: function(section_id, page) {
		var self = this;
		var span = jQuery('#section-' + section_id);
		span.parent().prev().addClass('spinner');

		jQuery.ajax({
			url: Symphony.Context.get('root') + '/symphony/extension/search_index/reindex/?section=' + section_id + '&page=' + page,
			success: function(xml) {
				var total_pages = parseInt(jQuery('pagination', xml).attr('total-pages'));
				var total_entries = jQuery('pagination', xml).attr('total-entries');

				span.show().text(
					Symphony.Language.get('Indexing page {$page} of {$total}', { page: page, total: total_pages})
				);

				// there are more pages left
				if (total_pages > 0 && total_pages != page++) {
					setTimeout(function() {
						self.indexSectionByPage(section_id, page);
					}, self.refresh_rate);
				}
				// proceed to next section
				else {					
					setTimeout(function() {
						if(total_entries == 1) {
							span.text(
								Symphony.Language.get('{$total} entry', { total: total_entries})
							);
						} else {
							span.text(
								Symphony.Language.get('{$total} entries', { total: total_entries})
							);
						}
						span.parent().prev().removeClass('spinner');
						self.progress++;
						self.indexNextSection();
					}, self.refresh_rate);
				}
			}
		});
	},
	
	buildDrawerFilters: function() {
		var drawer = jQuery('#drawer-filters');
		if(!drawer.length) return;
		
		var date_min = '';
		var date_max = '';

		jQuery('.date-range input:first').each(function() {
			// if the drawer is collapsed (display: none) we need to
			// temporarily show it to allow element heights to be calculated
			var drawer = jQuery(this).parents('.drawer:not(.expanded)');
			if(drawer.length) drawer.show();
			var height = jQuery(this).height();		
			jQuery(this).parent().find('span.conjunctive').height(height);
			if(drawer.length) drawer.hide();

			date_min = jQuery(this).parent().data('dateMin');
			date_max = jQuery(this).parent().data('dateMax');
		});

		jQuery('.date-range input').daterangepicker({
			arrows: false,
			presetRanges: [
				{text: 'Last 7 days', dateStart: 'today-7days', dateEnd: 'today' },
				{text: 'Last 30 days', dateStart: 'today-30days', dateEnd: 'today' },
				{text: 'Last 12 months', dateStart: 'today-1year', dateEnd: 'today' }
			],
			presets: {
				dateRange: 'Date range...'
			},
			nextLinkText: '&#8594;',
			prevLinkText: '&#8592;',
			closeOnSelect: true,
			datepickerOptions: {
				nextText: '&#8594;',
				prevText: '&#8592;',
				minDate: Date.parse(date_min),
				maxDate: Date.parse(date_max),
				showOtherMonths: true
			},
			earliestDate: date_min,
			latestDate: date_max
		});

		jQuery('.drawer.filters form').bind('submit', function(e) {
			e.preventDefault();
			var get = '';
			jQuery(this).find('input, textarea, select').each(function() {
				var type = jQuery(this).attr('type');
				// no need to send buttons
				if(type == 'button' || type == 'submit') return;
				get += jQuery(this).attr('name') + '=' + encodeURI(jQuery(this).val()) + '&';
			});
			// remove trailing ampersand
			get = get.replace(/&$/,'');
			window.location.href = '?' + get;
		});

		jQuery('.drawer.filters input.secondary').bind('click', function(e) {
			e.preventDefault();
			window.location.href = '?';
		});
		
	}
};

jQuery(document).ready(function() {
	SearchIndex_Indexes.init();
});