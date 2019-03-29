<?php

namespace Neostrada;

use GuzzleHttp\Psr7\Response;
use function GuzzleHttp\Psr7\parse_response;

/**
 * @method Response get(string $uri, array $payload = [])
 * @method Response post(string $uri, array $payload = [])
 * @method Response delete(string $uri, array $payload = [])
 * @method Response patch(string $uri, array $payload = [])
 */
class Http
{
    const METHODS = ['GET', 'POST', 'DELETE', 'PATCH'];

    private $baseUri = '';

    private $headers = [];

    private $method = '';

    public function __construct($options)
    {
        $this->parseOptions($options);
    }

    /**
     * @param $method
     * @param $arguments
     * @return Response|null
     */
    public function __call($method, $arguments)
    {
        $response = null;

        $method = strtoupper($method);

        if (in_array($method, self::METHODS)) {
            $this->method = $method;

            $uri = isset($arguments[0]) && is_string($arguments[0]) ? $arguments[0] : '';
            $payload = isset($arguments[1]) && is_array($arguments[1]) ? $arguments[1] : [];

            $response = $this->request($method, $uri, $payload);
        }

        return $response;
    }

    /**
     * @param $method
     * @param $uri
     * @param $payload
     * @return Response
     */
    private function request($method, $uri, $payload)
    {
        $url = rtrim($this->baseUri, '/') . '/' . ltrim($uri);

        // Build payload as query when we're getting something
        if ($this->isMethod('get') && $payload) {
            $url = rtrim($url, '?') . '?' . http_build_query($payload);
        }

        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_HEADER, true);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

        // Set the payload as the post fields when we're posting or patching something
        if ($this->isMethod(['post', 'patch', 'delete'])) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($payload));
        }

        if ($headers = $this->getHeaders()) {
            curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        }

        $response = parse_response(curl_exec($curl));

        curl_close($curl);

        return $response;
    }

    /**
     * @return array
     */
    private function getHeaders()
    {
        $headers = [];

        if (!empty($this->headers)) {
            foreach ($this->headers as $key => $value) {
                $headers[] = "{$key}: {$value}";
            }
        }

        return $headers;
    }

    /**
     * @param $options
     */
    private function parseOptions($options)
    {
        if (isset($options['base_uri'])) {
            $this->baseUri = $options['base_uri'];
        }

        if (isset($options['headers']) && is_array($options['headers'])) {
            $this->headers = $options['headers'];
        }
    }

    /**
     * @param $method
     * @return bool
     */
    private function isMethod($method)
    {
        $rc = false;

        if (is_array($method)) {
            // Make sure the methods are only strings and are uppercase
            $methods = array_map('strtoupper', array_filter($method, 'is_string'));

            $rc = in_array($this->method, $methods);
        }

        if (is_string($method)) {
            $rc = $this->method === strtoupper($method);
        }

        return $rc;
    }
}
