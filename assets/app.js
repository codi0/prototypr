window.addEventListener('load', function() {

	setTimeout(function() {

		//can use service worker?
		if('serviceWorker' in window.navigator) {
			//register service worker
			navigator.serviceWorker.register('sw.js').then(function(reg) {
				//is waiting?
				if(reg.waiting) {
					reg.waiting.postMessage('skipWaiting');
				}
				//is ready?
				navigator.serviceWorker.ready.then(function() {
					//do nothing
				});
			}).catch(function(error) {
				//show error
				console.error(error.message);
			});
		}

	}, 1);

});