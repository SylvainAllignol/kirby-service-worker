<?php
if (option('debug')) {
echo <<<JS
if ('serviceWorker' in navigator) {
	window.addEventListener('load', () => {
		navigator.serviceWorker.register('/sw.js')
			.then(reg => console.log('Service Worker registered:', reg))
			.catch(err => console.error('SW error:', err));
	});
}
JS;
} else {
	echo <<<JS
if ('serviceWorker' in navigator) {
	window.addEventListener('load', () => {
		navigator.serviceWorker.register('/sw.js')
			.catch(err => console.error('SW registration failed:', err));
	});
}
JS;
}