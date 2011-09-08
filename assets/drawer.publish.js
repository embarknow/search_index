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
	
});