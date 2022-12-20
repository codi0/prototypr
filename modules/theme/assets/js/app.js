window.addEventListener('load', function() {

	setTimeout(function() {

		//can use service worker?
		if('serviceWorker' in window.navigator) {
			//register service worker
			navigator.serviceWorker.register(pageData.baseUrl + 'sw.js').then(function(reg) {
				//is waiting?
				if(reg && reg.waiting) {
					reg.waiting.postMessage('skipWaiting');
				}
				//update found
				reg.addEventListener('updatefound', function() {
					location.reload();
				});
			}).catch(function(error) {
				//show error
				console.error(error.message);
			});
			//listen for messages
			navigator.serviceWorker.addEventListener('message', function(e) {
				//can listen and react to SW messages here (E.g. notifications)
			});
		}

	}, 1);

});