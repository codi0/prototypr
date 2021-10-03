<!DOCTYPE html>
<html lang="<?= $tpl->data('meta.lang') ?: 'en' ?>">
<head>
	<meta charset="<?= $tpl->data('meta.charset') ?: 'utf-8' ?>">
	<title><?= $tpl->data('meta.title') ?></title>
<?php if($tpl->data('meta.noindex')) { ?>
	<meta name="robots" content="noindex">
<?php } ?>
	<meta name="viewport" content="viewport-fit=cover, width=device-width, initial-scale=1.0, minimum-scale=1.0, maximum-scale=1.0, user-scalable=no">
	<meta name="apple-mobile-web-app-capable" content="yes">
	<meta name="mobile-web-app-capable" content="yes">
	<meta name="format-detection" content="telephone=no">
	<meta name="msapplication-tap-highlight" content="no">
	<link rel="canonical" href="<?= $tpl->url(null, [ 'query' => false ]) ?>">
	<link rel="icon" href="<?= $tpl->url('favicon.png') ?>">
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/codi0/fstage@0.2.2/fstage.min.css">
	<link rel="stylesheet" href="<?= $tpl->url('assets/app.css') ?>">
	<script defer src="https://cdn.jsdelivr.net/gh/codi0/fstage@0.2.2/fstage.min.js"></script>
	<script defer src="<?= $tpl->url('assets/app.js') ?>"></script>
	<?= $tpl->data('meta.head') ?>
</head>
<body>
	<?php $tpl->template($tpl->data('template')) ?>
</body>
</html>