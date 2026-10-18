// loadCSS('css/style.css', true);
// loadCSS('css/media800.css', true);
// loadCSS('css/media500.css', true);
loadCSS('https://fonts.googleapis.com/css?family=Open+Sans:300,400,600,700,800&subset=latin,cyrillic,cyrillic-ext', false);

function loadCSS(web, ver) {
	const link = document.createElement('link');
	if (ver == true) {
		link.href = web + "?v=" + Date.now();
	} else {
		link.href = web;
	}

	link.rel="stylesheet";
	document.head.appendChild(link);
}