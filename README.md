# About

Winston is a AB/split/multivariate testing library utilizing Redis and a basic machine learning algorithm for automatically displaying the most successful test variations. Winston also has the ability to employ confidence interval checks on your test variation performance to ensure that randomization of variations continues to occur until Winston is confident that a test variation is indeed statistically performing better than the others.

## Status

Winston is in active development and pre-alpha. You can contribute, but it's not ready for showtime.

## Usage

#### Client Side Code

This example uses short tags, but you don't have to if they aren't enabled. In this example, we're checking to see if varying the page's headline/tagline has any affect on the frequency of clicking a button directly below it.

```php
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

This implementation is only an example with minimal routing support to give you an idea for how to tie in the endpoints from the config file.

```php
<?php
// include the composer autoloader
require_once 'vendor/autoloader.php';

// load your configuration array from a file
$config = include('/path/to/config.php');

// load the client library
$winston = new \Pop\Winston($config);

// we want the post data
$data = $_POST;

// determine which endpoint we're requesting
$uri = getenv('REQUEST_URI');
if ($uri == 'winston/event') {
    $winston->recordEvent($data);
} else if ($uri == 'winston/pageview') {
    $winston->recordPageview($data);
}
```

## Supported Client Side Events

Winston supports triggering variation successes for all of the popular DOM events, however we suggest steering clear of mouse movement events given how frequently they trigger. The full list of supported events is `click`, `submit`, `focus`, `blur`, `change`, `mouseover`, `mouseout`, `mousedown`, `mouseup`, `keypress`, `keydown`, and `keyup`.

To trigger an event in your client side code, simply call: `$winston->event('name-of-your-test', EVENT_TYPE)` where `EVENT_TYPE` is one of the events mentioned above. This method will then generate and return a DOM event string for you to output directly in your HTML, i.e.

```php
// returns: onclick="POP.Winston.event('name-of-your-test', 'SELECTED_VARIATION_ID', 'click');"
// where SELECTED_VARIATION_ID is the variation id found to be optimal/randomized by Winston
$winston->event('name-of-your-test', 'click');
```

Let's now bind a form submission event directly to a form as an example which will get attributed to the chosen variation. The order of using `event()` and `variation()` doesn't matter:

```php
<form <?= $winston->event('name-of-your-test', 'submit'); ?>>
    <?= $winston->variation('name-of-your-test'); ?>
    <button type="submit">Submit Form</button>
</form>
```

## Requirements

  1. PHP 5.3+
  2. Redis must be installed and accessible.
  3. Composer is required for loading dependencies such as Predis, a popular PHP Redis client.
  4. You must create server side API endpoints in your framework or custom rolled application for Winston to be able to interact with the server side Winston library. These endpoints will need to take in `POST` data, load the Winston library, and pass in the `POST` data to the Winston library. More documentation to come.
