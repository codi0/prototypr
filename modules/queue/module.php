<?php

//object registry
$this->service('queue', function() {
	//ensure dependency loaded
	$this->composer->requirePackage('php-amqplib/php-amqplib');
	//configure Rabbit MQ
	return new \Prototypr\Queue([
		'maxConsumers' => 10, //max number of consumers to have going at once
		'consumerTtl' => 60, //length of time to keep a consumer going before auto-shutdown
		'consumerSpawnUrl' => $this->url('queue/consume'), //self-spawn consumer if none started
	]);
});

//send message
$this->route('queue/send', function() {
	//add to queue
	$res = $this->queue->sendMessage('myqueue', mt_rand(100000, 99999999));
	//console output
	if($res === 'primary') {
		echo 'Message queued...' . "\n";
	} else if($res === 'fallback') {
		echo 'Message queued to fallback...' . "\n";
	} else {
		echo 'Message failed to queue...' . "\n";
	}
});

//consume messages (periodic cron job?)
$this->route('queue/consume', function() {
	//console output
	echo 'Queue consumer started...' . "\n";
	//queue listener
	$this->queue->createConsumer('myqueue', function($message) {
		//console output
		echo 'Message received...' . "\n";
		//do processing here...
		file_put_contents(__DIR__ . '/rmq.log', $message->body . "\n", LOCK_EX|FILE_APPEND);
	});
});

//process fallback (periodic cron job?)
$this->route('queue/fallback', function() {
	//process files
	list($found, $success) = $this->queue->processFallback();
	//console output
	echo $found . ' messages found, ' . $success . ' processed...' . "\n";
});