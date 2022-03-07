<!DOCTYPE html>
<html lang="<?= $tpl->data('meta.lang') ?: 'en' ?>">
<head>
	<meta charset="<?= $tpl->data('meta.charset') ?: 'utf-8' ?>">
	<title><?= $tpl->data('meta.title') ?> | <?= $tpl->data('config.name') ?></title>
<?php if($tpl->data('meta.noindex')) { ?>
	<meta name="robots" content="noindex">
<?php } ?>
	<meta name="viewport" content="viewport-fit=cover, width=device-width, initial-scale=1.0, minimum-scale=1.0, maximum-scale=1.0, user-scalable=no">
	<meta name="apple-mobile-web-app-capable" content="yes">
	<meta name="mobile-web-app-capable" content="yes">
	<meta name="format-detection" content="telephone=no">
	<meta name="msapplication-tap-highlight" content="no">
</head>
<body class="page <?= str_replace([ '_', '-', '/' ], ' ', $tpl->data('template')) ?>">
	<div id="app">
		<?php $tpl->tpl($tpl->data('template')) ?>
	</div>
</body>
</html>