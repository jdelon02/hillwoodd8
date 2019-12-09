<?php

global $config;

$config['system.performance']['cache']['page']['use_internal'] = TRUE;
$config['system.performance']['css']['preprocess'] = TRUE;
$config['system.performance']['css']['gzip'] = TRUE;
$config['system.performance']['js']['preprocess'] = TRUE;
$config['system.performance']['js']['gzip'] = TRUE;
$config['system.performance']['response']['gzip'] = TRUE;
$config['views.settings']['ui']['show']['sql_query']['enabled'] = FALSE;
$config['views.settings']['ui']['show']['performance_statistics'] = FALSE;
$config['system.logging']['error_level'] = 'none';

// Live Mail and Site settings
// $config['system.site']['mail'] = 'webmaster@amphilosoc.org';
// $config['system.site']['name'] = 'American Philosophical Society';
//
//
// // Sendgrid setup.
// $config['sendgrid_integration.settings']['apikey'] = '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';
// $config['sendgrid_integration.settings']['test_defaults']['to'] = 'admin@example.com';
// $config['sendgrid_integration.settings']['test_defaults']['subject'] = 'Test Email from MA SendGrid Module';
// $config['sendgrid_integration.settings']['test_defaults']['body']['value'] = 'Test Message for MA SendGrid.';
// $config['sendgrid_integration.settings']['test_defaults']['body']['format'] = 'plain_text';
// $config['sendgrid_integration.settings']['test_defaults']['from_name'] = '';
// $config['sendgrid_integration.settings']['test_defaults']['to_name'] = '';
// $config['sendgrid_integration.settings']['test_defaults']['reply_to'] = '';
//
// // Mail Safety settings..
// $config['mail_safety.settings']['enabled'] = FALSE;
// $config['mail_safety.settings']['send_mail_to_dashboard'] = FALSE;

// Theme rebuild on every page load.
$config['devel.settings']['rebuild_theme'] = FALSE;

// // Google Analytics
// $config['google_analytics.settings']['account'] = 'UA-5674777-2';
