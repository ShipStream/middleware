<?php

use Loguzz\Formatter\AbstractResponseFormatter;
use Psr\Http\Message\ResponseInterface;

class Plugin_ResponseFormatter extends AbstractResponseFormatter
{
    public function format (ResponseInterface $response, array $options = []) {
        $this->extractArguments($response, $options);
        $body = $this->options['body'];
        unset($this->options['headers']['Content-Security-Policy']);
        unset($this->options['headers']['X-XSS-Protection']);
        unset($this->options['headers']['Report-To']);
        unset($this->options['headers']['NEL']);
        unset($this->options['headers']['Strict-Transport-Security']);
        unset($this->options['headers']['Expect-CT']);
        unset($this->options['headers']['X-Frame-Options']);
        unset($this->options['body']);
        return json_encode($this->options, JSON_UNESCAPED_SLASHES)."\n".$body;
    }
}
