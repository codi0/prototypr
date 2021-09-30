<!DOCTYPE html>
<html lang="<?= $tpl->data('page.lang') ?: 'en' ?>">
<head>
	<meta charset="<?= $tpl->data('page.charset') ?: 'utf-8' ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?= $tpl->data('page.title') ?></title>
	<?php if($tpl->data('page.noindex')) { ?>
	<meta name="robots" content="noindex">
	<?php } ?>
	<link rel="icon" href="data:,">
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/codi0/fstage@0.2.2/fstage.min.css">
	<link rel="stylesheet" href="<?= $tpl->url('assets/app.css') ?>">
	<script defer src="https://cdn.jsdelivr.net/gh/codi0/fstage@0.2.2/fstage.min.js"></script>
	<script defer src="<?= $tpl->url('assets/app.js') ?>"></script>
</head>
<body>
	<div id="welcome">
		<?= $tpl->data('welcome') ?>
	</div>
</body>
</html>