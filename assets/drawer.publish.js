jQuery(document).ready(function() {
	
	$ = jQuery;
	
	$('.drawer').each(function() {
		
		var drawer = $(this);
		var id = drawer.attr('id');
		var expanded = false;
		
		// should be expanded (initial state set from PHP)
		if(drawer.hasClass('expanded')) expanded = true;
		
		// remember state between use
		if (Symphony.Support.localStorage && localStorage[id]) {
			expanded = (localStorage[id] == 'true') ? true : false;
			if(expanded === true) drawer.addClass('expanded');
		}
		
		$('a[href="#'+id+'"]').live('click', function(e) {
			
			e.preventDefault();
			if(expanded === true) {
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
			
			expanded = !expanded;
			localStorage[id] = expanded;
			
		});
		
	});
	
	
	
});