# Newrphus
Let users send misprints from your website to Slack.

[![License](https://poser.pugx.org/tjournal/newrphus/license)](https://packagist.org/packages/tjournal/newrphus)
[![Latest Stable Version](https://poser.pugx.org/tjournal/newrphus/v/stable)](https://packagist.org/packages/tjournal/newrphus)

Library contains PHP back-end library and Javascript front-end file. You should use both of them to make misprint reporter works.

![How it works](http://i.imgur.com/zYoWcat.png)

When user selects text on a page and presses <kbd>Ctrl+Enter</kbd>, the Newrphus sends POST request to `url`. You can also include `userId` parameter to track users. Right after the keypress event, it calls `callback` function, where you can tell user that report was sent.

## How to use
1. [Create new Incoming webhook](https://slack.com/services/new/incoming-webhook) in Slack.
2. Install PHP library with [Composer](#installing-via-composer).
3. Create backend handler for JS Ajax call.
    ```php
    $newrphus = new TJ\Newrphus();
    $reviewer->setSlackSettings([
        'endpoint' => 'https://hooks.slack.com/services/ABCDE/QWERTY',
        'channel' => '#misprints'
    ]);
    $reviewer->report($_POST['misprintText']);
    ```

    If you want to customize Slack message, see `example.php`.

4. Include js to the page, where you want to track misprints.
    ```html
    <script src="js/newrphus.js"></script>
    <script>
      newrphus.init({
        url: 'example.php',
        userId: 12345,
        callback: function() {
          alert('Misprint sent');
        }
      });
    </script>
    ```

5. Tell users to select text and press <kbd>Ctrl+Enter</kbd> to send report.


## Installing via Composer

```bash
composer.phar require tjournal/newrphus
```

Then include Composer's autoloader:

```php
require 'vendor/autoload.php';
```
