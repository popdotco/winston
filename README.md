# About

Winston is a AB/split/multivariate testing library utilizing Redis and a basic machine learning algorithm for automatically displaying the most successful test variations. Winston also has the ability to employ confidence interval checks on your test variation performance to ensure that randomization of variations continues to occur until Winston is confident that a test variation is indeed statistically performing better than the others.

## Status

Winston is in active development and pre-alpha. You can contribute, but it's not ready for showtime.

## Usage

#### Client Side Code

This example uses short tags, but you don't have to if they aren't enabled. In this example, we're checking to see if varying the page's headline/tagline has any affect on the frequency of clicking a button directly below it.

```html
<?php
// include the composer autoloader
require_once 'vendor/autoloader.php';

// load your configuration array from a file
$config = include('/path/to/config.php');

// load the client library
$winston = new \Pop\Winston($config);
?>

<html>
<head><title>Sample</title></head>
<body>
<!-- show a test variation -->
<h1><?= $winston->test('my-first-headline-test'); ?></h1>

<!-- add an event separate from the test that also triggers a success -->
<button type="button" <?= $winston->event('my-first-headline-test', 'click'); ?>>Sample Button</button>

<!-- load the footer javascript (echos) -->
<?= $winston->javascript(); ?>

<!-- include the required jquery lib -->
<script type="text/javascript" src="//ajax.googleapis.com/ajax/libs/jquery/1.10.2/jquery.min.js"></script>
<script type="text/javascript" src="/path/to/jquery.winston.js"></script>
</body>
</html>
```

#### Server Side Code

Todo...

## Requirements

  1. PHP 5.3+
  2. Redis must be installed and accessible.
  3. Composer is required for loading dependencies such as Predis, a popular PHP Redis client.
  4. You must create server side API endpoints in your framework or custom rolled application for Winston to be able to interact with the server side Winston library. These endpoints will need to take in `POST` data, load the Winston library, and pass in the `POST` data to the Winston library. More documentation to come.
