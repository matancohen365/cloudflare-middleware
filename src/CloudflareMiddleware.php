<?php namespace Tuna;

use Exception;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use function GuzzleHttp\Psr7\modify_request;

class CloudflareMiddleware
{
    /**
     * WAIT_RESPONSE_CODE this is the response code which Cloudflare throws when UAM is active
     */
    const WAIT_RESPONSE_CODE = 503;

    /**
     * SERVER_NAME name of the server which Cloudflare uses
     */
    const SERVER_NAME = [
        'cloudflare-nginx',
        'cloudflare'
    ];

    /**
     * REFRESH_EXPRESSION regular expression used to parse the 'Refresh' header
     */
    const REFRESH_EXPRESSION = '/8;URL=(\/cdn-cgi\/l\/chk_jschl\?pass=[0-9]+\.[0-9]+-.*)/';

    /** @var callable */
    protected $nextHandler;

    /**
     * @param callable $nextHandler Next handler to invoke.
     */
    public function __construct(callable $nextHandler)
    {
        $this->nextHandler = $nextHandler;
    }

    /**
     * @return \Closure
     */
    public static function create()
    {
        return function ($handler) {
            return new static($handler);
        };
    }

    /**
     * @param \Psr\Http\Message\RequestInterface $request
     * @param array $options
     * @return \Psr\Http\Message\RequestInterface
     */
    public function __invoke(RequestInterface $request, array $options = [])
    {
        $next = $this->nextHandler;

        return $next($request, $options)
            ->then(
                function (ResponseInterface $response) use ($request, $options) {
                    return $this->checkResponse($request, $options, $response);
                }
            );
    }

    /**
     * @param \Psr\Http\Message\RequestInterface $request
     * @param array $options
     * @param \Psr\Http\Message\ResponseInterface $response
     * @return \Psr\Http\Message\RequestInterface|\Psr\Http\Message\ResponseInterface
     * @throws \Exception
     */
    protected function checkResponse(RequestInterface $request, array $options = [], ResponseInterface $response)
    {
        if (!$this->needVerification($response)) {
            return $response;
        }

        if (empty($options['cookies'])) {
            throw new Exception('you have to use cookies');
        }

        if (empty($options['allow_redirects'])) {
            throw new Exception('you have to use the allow_redirects options');
        }

        return $this($this->modifyRequest($request, $response), $options);
    }

    /**
     * @param \Psr\Http\Message\ResponseInterface $response
     * @return bool
     */
    protected function needVerification(ResponseInterface $response)
    {
        return $response->getStatusCode() === static::WAIT_RESPONSE_CODE
            && in_array($response->getHeaderLine('Server'), static::SERVER_NAME, true);
    }

    /**
     * @param \Psr\Http\Message\RequestInterface $request
     * @param \Psr\Http\Message\ResponseInterface $response
     * @return \Psr\Http\Message\RequestInterface
     * @throws \Exception
     */
    protected function modifyRequest(RequestInterface $request, ResponseInterface $response)
    {
        sleep(8);

        return modify_request(
            $request,
            [
                'uri' => UriResolver::resolve(
                    $request->getUri(),
                    $this->getRefreshUri($request, $response)
                ),
                'body' => '',
                'method' => 'GET',
                'set_headers' => [
                    'Referer' => $request->getUri()->withUserInfo('', ''),
                ],
            ]
        );
    }

    /**
     * @param \Psr\Http\Message\RequestInterface $request
     * @param \Psr\Http\Message\ResponseInterface $response
     * @return \GuzzleHttp\Psr7\Uri
     * @throws \Exception
     */
    protected function getRefreshUri(RequestInterface $request, ResponseInterface $response)
    {
        if (preg_match(static::REFRESH_EXPRESSION, $response->getHeaderLine('Refresh'), $matches)) {
            return new Uri($matches[1]);
        }

        return $this->solveJavascriptChallenge($request, $response);
    }

    /**
     * Try to solve the JavaScript challenge
     * Thanks to: KyranRana, https://github.com/KyranRana/cloudflare-bypass
     *
     * @param \Psr\Http\Message\RequestInterface $request
     * @param \Psr\Http\Message\ResponseInterface $response
     * @return \GuzzleHttp\Psr7\Uri
     * @throws \Exception
     */
    protected function solveJavascriptChallenge(RequestInterface $request, ResponseInterface $response)
    {
        $content = $response->getBody();

        /*
         * Source: https://github.com/KyranRana/cloudflare-bypass/blob/master/v2/src/CloudflareBypass/CFBypass.php
         */

        /*
         * Extract "jschl_vc" and "pass" params
         */
        preg_match_all('/name="\w+" value="(.+?)"/', $content, $matches);

        if (!isset($matches[1]) || !isset($matches[1][1])) {
            throw new \ErrorException('Unable to fetch jschl_vc and pass values; maybe not protected?');
        }

        $params = array();
        list($params['jschl_vc'], $params['pass']) = $matches[1];

        /*
         * Extract JavaScript challenge logic
         */
        preg_match_all('/:[!\[\]+()]+|[-*+\/]?=[!\[\]+()]+/', $content, $matches);

        if (!isset($matches[0]) || !isset($matches[0][0])) {
            throw new \ErrorException('Unable to find javascript challenge logic; maybe not protected?');
        }

        try {
            /*
             * Convert challenge logic to PHP
             */
            $php_code = "";
            foreach ($matches[0] as $js_code) {
                // [] causes "invalid operator" errors; convert to integer equivalents
                $js_code = str_replace(array(
                    ")+(",
                    "![]",
                    "!+[]",
                    "[]"
                ), array(
                    ").(",
                    "(!1)",
                    "(!0)",
                    "(0)"
                ), $js_code);
                $php_code .= '$params[\'jschl_answer\']' . ($js_code[0] == ':' ? '=' . substr($js_code, 1) : $js_code) . ';';
            }

            /*
             * Eval PHP and get solution
             */
            eval($php_code);
            // Split url into components.
            $uri = parse_url($request->getUri());
            // Add host length to get final answer.
            $params['jschl_answer'] += strlen($uri['host']);
            /*
             * 6. Generate clearance link
             */
            return new Uri(sprintf("/cdn-cgi/l/chk_jschl?%s",
                http_build_query($params)
            ));
        } catch (Exception $ex) {
            // PHP evaluation bug; inform user to report bug
            throw new \ErrorException(sprintf('Something went wrong! Please report an issue: %s', $ex->getMessage()));
        }


    }


}
