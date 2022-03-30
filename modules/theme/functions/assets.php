<?php

/**
 *
 * Any php files added to the theme functions directory will be automatically loaded
 *
 * $this references the View object
 * $this->kernel can be used to access the core
 *
**/


//Queue canonical
$this->queue('canonical', $this->url(null, [ 'query' => false ]));

//Queue manifest
$this->queue('manifest', 'manifest.json');

//Queue favicon
$this->queue('favicon', 'assets/img/favicon.png');

//Queue css
$this->queue('css', 'https://cdn.jsdelivr.net/gh/codi0/fstage@0.3.3/src/css/fstage.min.css');
$this->queue('css', 'assets/css/app.css', [ 'fstage' ]);

//Queue js
$this->queue('js', 'https://cdn.jsdelivr.net/gh/codi0/fstage@0.3.3/src/js/fstage.min.js');
$this->queue('js', 'assets/js/app.js', [ 'fstage' ]);

//You can also arbitrary css or js code
$this->queue('js', 'console.log(Fstage);', [ 'fstage' ]);
