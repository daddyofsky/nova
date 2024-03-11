<?php
namespace Nova\Http;

use Nova\Exceptions\ErrorException;

/**
 * Http Class
 *
 * inspired by HopegiverPHP of MalgnSoft
 *
 * [USAGE]
 * $http = new Http;
 * $body = $http->setDebug()
 * 	->setURL('https://daum.net')
 *  ->setCookie('ASPSESSIONIDGQGQGWJC', 'FLFLJDMAOLEMKENOCCFDCKCH')
 *  ->send('GET');
 */
class HttpClient
{
    public bool $debug = false;

    protected string $url            = '';
    protected array  $header         = [];
    protected array  $cookie         = [];
    protected array  $param          = [];
    protected bool   $useUpload      = false;
    protected bool   $json           = false;
    protected string $agent          = 'PHP/HTTP_CLASS';
    protected string $referer        = '';
    protected string $auth           = '';
    protected int    $timeout        = 0;
    protected int    $timeoutConnect = 0;
    protected array  $response       = [];
	protected string $errMsg         = '';

    public function __construct(string $url = '')
    {
        if ($url) {
            $this->setURL($url);
        }
    }

    public function setDebug(): static
	{
        $this->debug = true;
        return $this;
    }

    public function setURL(string $url): static
	{
		if ($this->url) {
			$orgInfo = parse_url($this->url);
			$newInfo = parse_url($url);
			if ($orgInfo['host'] !== $newInfo['host']) {
				// reset cookie if host changed
				$this->cookie = [];
			}
		}

        $this->url = $url;
        return $this;
    }

    public function setParam(string|array $key, mixed $value = ''): static
	{
        if (is_array($key)) {
            foreach ($key as $k => $v) {
                $this->setParam($k, $v);
            }
        } elseif (is_array($value)) {
            foreach ($value as $k => $v) {
                $k = sprintf('%s[%s]', $key, $k);
                if (is_array($v)) {
                    $this->setParam($k, $v);
                } elseif (str_starts_with($v, '@file=')) {
                    $this->setUploadFile($k, substr($v, 6));
                } else {
                    $this->param[$k] = $v;
                }
            }
        } elseif (str_starts_with((string)$value, '@file=')) {
            $this->setUploadFile($key, substr($value, 6));
        } else {
            $this->param[$key] = $value;
        }
        return $this;
    }

    public function setUploadFile(string $key, string|array $file): static
	{
        if (is_array($file)) {
            foreach ($file as $k => $v) {
                $k = sprintf('%s[%s]', $key, $k);
                $this->setUploadFile($k, $v);
            }
        } elseif (is_file($file)) {
            $this->useUpload = true;
            if (function_exists('curl_file_create')) {
                // PHP 5 >= 5.5.0, PHP 7
                $this->param[$key] = curl_file_create($file);
            } else {
                // ex) @/real/path/file_name.jpg;type=image/jpg
                $path = realpath($file);
                $this->param[$key] = sprintf('@%s;type=%s', $path, mime_content_type($path));
            }
        }
        return $this;
    }

    public function setAgent(true|string $agent = true): static
	{
        if ($agent === true) {
            $this->agent = $_SERVER['HTTP_USER_AGENT'];
        } else {
            $this->agent = $agent;
        }
        return $this;
    }

    public function setReferer(string $referer): static
	{
        $this->referer = $referer;
        return $this;
    }

    public function setCookie(string|array $key, mixed $value = ''): static
	{
        if (is_array($key)) {
            if ($this->cookie) {
                $this->cookie = array_merge($this->cookie, $key);
            } else {
                $this->cookie = $key;
            }
        } else {
            $this->cookie[$key] = $value;
        }
        return $this;
    }

    public function setAuth(string $id, string $pass): static
	{
        $this->auth = sprintf('%s:%s', $id, $pass);
        return $this;
    }

    public function setHeader(string|array $key, string $value = ''): static
	{
        if (is_array($key)) {
            $this->header = array_merge($this->header, $key);
        } elseif ($value) {
            $this->header[] = sprintf('%s: %s', $key, $value);
        } else {
            $this->header[] = $key;
        }
        return $this;
    }

    public function setJson(bool $flag = true): static
	{
        $this->json = $flag;
        return $this;
    }

    public function setTimeout(int $timeout, int $timeoutConnect = 0): static
	{
        $this->timeout = $timeout;
        $this->timeoutConnect = $timeoutConnect;
        return $this;
    }

    /**
     * @throws ErrorException
     */
    public function send(string $mode = 'GET', bool $wait = true): bool|string
	{
        if (!$this->url) {
            return false;
        }

	    $options = [
		    CURLOPT_URL            => $this->url,            // url
		    CURLOPT_RETURNTRANSFER => true,                  // return body
		    CURLOPT_HEADER         => true,                  // return headers
		    CURLOPT_ENCODING       => '',                    // handle all encodings
		    CURLOPT_USERAGENT      => $this->agent,          // agent
		    CURLOPT_FOLLOWLOCATION => true,                  // follow redirects
		    CURLOPT_AUTOREFERER    => true,                  // set referer on redirect
		    CURLOPT_CONNECTTIMEOUT => $this->timeoutConnect, // timeout on connect
		    CURLOPT_TIMEOUT        => $this->timeout,        // timeout
		    CURLOPT_MAXREDIRS      => 10,                    // max redirection
		    CURLOPT_SSL_VERIFYPEER => false,                 // disabled SSL Cert checks
		    CURLINFO_HEADER_OUT    => true,                  // curl_getinfo() return request header
	    ];

        // header
        $this->setHeader('Expect:');
        if ($this->useUpload) {
            $this->json = false;
        }
        if ($this->json) {
            $this->setHeader('Content-Type: application/json');
        }
        $options[CURLOPT_HTTPHEADER] = $this->header;

        // auth
        $this->auth && ($options[CURLOPT_USERPWD] = $this->auth);

        // cookie
        if ($this->cookie) {
            $tmp = [];
            foreach ($this->cookie as $k => $v) {
                $tmp[] = $k . '=' . urlencode($v);
            }
            $options[CURLOPT_COOKIE] = implode(';', $tmp);
        }

        // params
        if ($this->param) {
            $mode = strtoupper($mode);
            if ($mode === 'GET') {
                $options[CURLOPT_URL] .= (str_contains($options[CURLOPT_URL], '?') ? '&' : '?') . http_build_query($this->param);
            } else {
                if ($mode === 'POST') {
                    $options[CURLOPT_POST] = 1;
                } else {
                    $options[CURLOPT_CUSTOMREQUEST] = $mode;
                }
                $options[CURLOPT_POSTFIELDS] = $this->json ? json_encode($this->param) : $this->param;
            }
        }

        // send
        $http = curl_init();
        curl_setopt_array($http, $options);
        $content = curl_exec($http);
	    $this->response = curl_getinfo($http);

	    $this->errMsg = '';
		if (curl_errno($http)) {
			$this->errMsg = curl_error($http);
		}
        curl_close($http);

        // debug
        if ($this->debug) {
			echo /** @lang text */ '<xmp>';
            echo "[ REQUEST ]\n";
            print_r($this->response['request_header']);
            echo "\n";
            echo "[ RESPONSE ]\n";
            print_r($content);
			echo /** @lang text */ '</xmp>';
        }

	    if ($this->errMsg) {
			error($this->errMsg);
	    }

        if (!$wait) {
            return true;
        }

        if ($content) {
            // split response header and body
            [$header, $body] = explode("\r\n\r\n", (string)$content, 2);
            $this->response['response_header'] = $header;

            // preserve response cookie
            $this->cookie = array_merge($this->cookie, $this->getCookie());
        } else {
            $this->response['response_header'] = null;
            $body = '';
        }

        // for continuous request
        $this->setReferer($this->url);

        // init one time info
        $this->url = '';
        $this->header = [];
        $this->param = [];

        return $body;
    }

    /**
     * get response info
     * (header + curl_getinfo return value)
     */
    public function getResponseInfo(string $key = ''): mixed
	{
		if ($key) {
			return $this->response[$key] ?? null;
		}
		return $this->response;
    }

	public function getHttpCode(): int
	{
		return (int)$this->getResponseInfo('http_code');
	}

    public function getHeader(): mixed
	{
        return $this->getResponseInfo('response_header');
    }

    public function getCookie(string $key = ''): false|array|string
	{
        if (empty($this->response['cookie'])) {
            $cookies = [];
            if (preg_match_all('/Set-Cookie:\\s*([^\\n]+)/mi', $this->getHeader(), $match)) {
                $now = time();
                foreach ($match[1] as $item) {
                    $tmp = array_map('trim', explode(';', $item));
                    // check expire
                    if (isset($tmp[1])) {
                        [$a, $b] = explode('=', (string)$tmp[1], 2);
                        if ($a === 'expires' && strtotime($b) < $now) {
                            // expired
                            continue;
                        }
                    }
                    parse_str($tmp[0], $cookie);
                    $cookies[] = $cookie;
                }
				$cookies = array_merge(...$cookies);
            }
            $this->response['cookie'] = $cookies;
        }

        if ($key) {
            return $this->response['cookie'][$key] ?? false;
        }

        return $this->response['cookie'];
    }
}
