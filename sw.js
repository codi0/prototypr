/* SETUP */

var version = 'v0.0.1';
var debug = true;
var host = self.location.protocol + '//' + self.location.hostname;


/* LISTENERS */

//install service worker
self.addEventListener('install', function(e) {
	//debugging
	debug && console.log('Worker installed', version);
});

//activate service worker
self.addEventListener('activate', function(e) {
	//debugging
	debug && console.log('Worker activated', version);
});

//receive client message
self.addEventListener('message', function(e) {
	//debugging
	debug && console.log('Message received', e);
	//skip waiting?
	if(e.data === 'skipWaiting') {
		return self.skipWaiting();
	}
});

//fetch client resource
self.addEventListener('fetch', function(e) {
	//debugging
	debug && console.log('Resource fetched', e);
});

//receive push notification
self.addEventListener('push', function(e) {
	//debugging
	debug && console.log('Push notification received', e);
});

//click push notification
self.addEventListener('notificationclick', function(e) {
	//debugging
	debug && console.log('Push notification clicked', e);
});