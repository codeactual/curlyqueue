# curlyqueue

curlyqueue wraps a cURL handle queue that supports:

* Success/error callbacks
* Per-request context/userdata
* Josh Fraser's [rolling queue](http://onlineaspect.com/2009/01/26/how-to-use-curl_multi-without-blocking/) for a fixed handle pool size
* Optional run time limiting via [Flow\Interruptable](https://github.com/codeactual/flow) interface
* Control over all cURL options

## Usage

```php
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
