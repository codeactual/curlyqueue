# curlyqueue

curlyqueue wraps a cURL handle queue that supports:

* Success/error callbacks
* Per-request context/userdata
* Fixed handle pool size
* Optional run time limiting via [\Flow\Interruptable](https://github.com/codeactual/flow) interface
* Control over all cURL options

High unit test coverage using PHPUnit.

## Usage

``` php
<?php
$poolSize = 2;
$maxRunTimeInSecs = 4;

$queue = new CurlyQueue($curlOpts);
$queue->add($uri1);
$queue->add($uri2, $optionalContext1);
$queue->add($uri3, $optionalContext2);
$queue->add($uri4);

Flow::setMaxRuntime($queue, $maxRunTimeInSecs);

$queue->setResponseCallback(function($ch, $content, $context) {
  echo "OK {$context['apiName']}";
});
$queue->setErrorCallback(function($ch, $context) {
  echo "ERR {$context['apiName']}";
});

$queue->exec($poolSize);
```

## Events

* `setResponseCallback($ch, $content, $context)` - Fired on each received response, whether 200 or not.
* `setErrorCallback($ch, $context)` - Fired on each failed request (e.g. host lookup error).
* `setEndCallback()` - Fired on completed queue.

## Requirements

* PHP 5.3+
* [flow](https://github.com/codeactual/flow) (submodule)

## License / Credit

I originally started this in 2009 before the base code, by [Josh Fraser](http://onlineaspect.com/2009/01/26/how-to-use-curl_multi-without-blocking/), was later updated, licensed and posted to [Google Code](http://code.google.com/p/rolling-curl/). So it's not clear to me that I need to switch to Apache License Version 2.0.

But what should be made clear is that he deserves credit for the base code of the rolling queue, callbacks, and cURL option support. Please check out his repository to see if his current version better suits your needs.