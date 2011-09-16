jQuery(document).ready(function() {
	
	$ = jQuery;
	
	$('a.button[href^="#drawer"]').live('click', function(e) {
		e.preventDefault();
		var href = $(this).attr('href');
		var drawer = $(href);
		
		if(drawer.hasClass('expanded')) {
			$(this).removeClass('selected');
			drawer.slideUp('fast', function() {
				$(this).removeClass('expanded');
			});
		} else {
			$(this).addClass('selected');
			drawer.slideDown('fast', function() {
				$(this).addClass('expanded');
			});
		}
		
	});
	
	$('body').live('click', function(e) {
		if($(e.target).parents('.mega-selector').length == 0) {
			$('.mega-selector.expanded').each(function() {
				var container = $(this);
				var options = container.find('.options');
				container.toggleClass('highlighted')
				if(container.hasClass('expanded')) {
					options.slideUp('fast', function() {
						container.removeClass('expanded');
					});
				}
			});
		}
	});
	
	$('.mega-selector').each(function() {
		var label = $('.label');
		var arrow = label.find('.arrow');
		var container = $(this);
		var options = container.find('.options');
		
		label.bind('click', function() {
			container.toggleClass('highlighted')
			if(container.hasClass('expanded')) {
				options.slideUp('fast', function() {
					container.removeClass('expanded');
				});
			}
			else {
				options.slideDown('fast', function() {
					container.addClass('expanded');
				});
			}
		});
		
		options.find('a').bind('click', function(e) {
			e.preventDefault();
			var date = $(this).data('from') + ' - ' + $(this).data('to');
			label.find('.text').text(date);
			container.toggleClass('highlighted')
			options.slideUp('fast', function() {
				container.removeClass('expanded');
			});
		});
		
	});
	
});