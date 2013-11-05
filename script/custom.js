function loadContent(elem, source)
{
	$(elem).html('<div class="progress progress-striped active"><div class="progress-bar" style="width:100%"><span class="sr-only">In Progress....</span></div></div>');
	$(elem).load(source);
}

