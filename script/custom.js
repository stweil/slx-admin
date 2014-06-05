function loadContent(elem, source)
{
	var waitForIt = function() {
		if (typeof $ === 'undefined') {
			setTimeout(waitForIt, 50);
			return;
		}
		$(elem).load(source);
	}
	waitForIt();
}
