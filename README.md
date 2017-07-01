# cloudflare-middleware
Cloudflare Middleware For Guzzle


# use

``` php
use GuzzleHttp\Client;
use Tuna\CloudflareMiddleware;
use GuzzleHttp\Cookie\FileCookieJar;

$client = new Client(['cookies' => new FileCookieJar('cookies.txt')]);

$client->getConfig('handler')->push(CloudflareMiddleware::create());

$res = $client->request('GET', 'http://www.exemple.com/');
echo $res->getBody();
```

## thanks XOXO 
@stackoverflowin for https://github.com/stackoverflowin/CloudFlare-PHP-Bypass