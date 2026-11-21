<?php

require dirname(__DIR__).'/vendor/autoload.php';

(new Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__).'/.env');
$_ENV['OPENAI_API_KEY'] = '';
$_ENV['SNAPSHOT_SITE_API_KEY'] = '';
