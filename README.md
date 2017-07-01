# Bypass Cloudflare
Bypass Cloudflare Middleware For Guzzle

# Installation
Using [composer][1]:

``` 
composer require tunaabutbul/cloudflare-middleware
```

# Usage

``` php
use GuzzleHttp\Client;
use Tuna\CloudflareMiddleware;
use GuzzleHttp\Cookie\FileCookieJar;

$client = new Client(['cookies' => new FileCookieJar('cookies.txt')]);

$client->getConfig('handler')->push(CloudflareMiddleware::create());

$res = $client->request('GET', 'http://www.exemple.com/');
echo $res->getBody();
```

# Thanks 
[stackoverflowin][2] for [CloudFlare-PHP-Bypass][3]

# License
-------
This middleware is licensed under the MIT License - see the LICENSE file for details

[1]: https://getcomposer.org/
[2]: https://github.com/stackoverflowin
[3]: https://github.com/stackoverflowin/CloudFlare-PHP-Bypass
