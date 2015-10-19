MailChimp API V3 Wrapper
========================

This is a basic PHP wrapper for the MailChimp API V3 for subscribe/unsubscribe to/from lists.

[![Author](http://img.shields.io/badge/author-@1upgmbh-blue.svg?style=flat-square)](https://twitter.com/1upgmbh)
[![Software License](http://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE)
[![Total Downloads](http://img.shields.io/packagist/dt/oneup/mailchimp-api-v3.svg?style=flat-square)](https://packagist.org/packages/oneup/mailchimp-api-v3)

# Examples
**Subscribe**

````php
$mc = new MailChimp('thisShouldBeYourApiKeyFromMailChimp-us1');
$response = $mc->subscribeToList(
    'ea06b81001',           // List ID
    'foo@bar.baz',          // E-Mail address 
    [                       // Array with first/lastname (MailChimp merge tags) 
        'FNAME' =>  'Foo',
        'LNAME' => 'Bar',
    ],
    true                    // Double opt-in true
);
````

**Unsubscribe**

````php
$mc = new MailChimp('thisShouldBeYourApiKeyFromMailChimp-us1');
$response = $mc->unsubscribeFromList('yourListId', 'foo@bar.baz');
````
