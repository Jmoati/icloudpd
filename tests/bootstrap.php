<?php

require __DIR__.'/../vendor/autoload.php';

// upstream tests only pass in UTC (tests/test_cli.py compares timezone-aware datetimes)
date_default_timezone_set(getenv('TZ') ?: 'Etc/UTC');
