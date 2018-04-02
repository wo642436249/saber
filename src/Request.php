<?php
/**
 * Copyright: Swlib
 * Author: Twosee <twose@qq.com>
 * Date: 2018/1/9 下午3:19
 */

namespace Swlib\Saber;

use Swlib\Http\CookiesManagerTrait;
use Swlib\Http\Exception\ConnectException;
use Swlib\Http\StreamInterface;
use Swlib\Http\Uri;
use Swlib\Util\InterceptorTrait;
use Swoole\Coroutine;
use Swoole\Coroutine\Http\Client;

final class Request extends \Swlib\Http\Request
{
    public $name;
    /** @var \Swoole\Coroutine\Http\Client */
    public $client;
    /** @var bool 是否使用SSL连接 */
    public $ssl = false;
    /** @var string CA证书目录 */
    public $ca_file = '';
    /** @var array 代理配置 */
    public $proxy = [];
    /** @var int IO超时时间 */
    public $timeout = 3;
    /** @var int 最大重定向次数,为0时关闭 */
    public $redirect = 3;
    /** @var bool 重定向等待,即手动触发重定向 */
    public $redirect_wait = false;
    /**@var bool 长连接 */
    public $keep_alive = true;

    /** @var float request start micro time */
    public $_start_time;
    /** @var float timeout left */
    public $_timeout;
    /** @var int consuming time */
    public $_time = 0.000;
    /** @var int 已重定向次数 */
    public $_redirect_times = 0;
    /** @var array 重定向的headers */
    public $_redirect_headers = [];

    const NONE = 1;
    const WAITING = 2;
    public $_status = self::NONE;

    use CookiesManagerTrait {
        CookiesManagerTrait::initialization as private __cookiesInitialization;
    }

    use InterceptorTrait;

    function __construct(?Uri $uri = null, string $method = 'GET', array $headers = [], ?StreamInterface $body = null)
    {
        parent::__construct($uri, $method, $headers, $body);
        $this->__cookiesInitialization(true);
    }

    /**
     * 是否为SSL连接
     *
     * @return bool
     */
    public function isSSL(): bool
    {
        return $this->ssl;
    }

    /**
     * enable/disable ssl and set a ca file.
     *
     * @param bool $enable
     * @param string $caFile
     * @return $this
     */
    public function withSSL(bool $enable = true, string $caFile = __DIR__ . '/cacert.pem'): self
    {
        if ($enable) {
            if ($caFile) {
                $this->ca_file = $caFile;
            }
            $this->ssl = true;
        } else {
            $this->ssl = false;
        }

        return $this;
    }

    public function getKeepAlive()
    {
        return $this->keep_alive;
    }

    /**
     * @param bool $enable
     * @return $this
     */
    public function withKeepAlive(bool $enable): self
    {
        $this->keep_alive = $enable;

        return $this;
    }

    public function getCAFile(): string
    {
        return $this->ca_file;
    }

    /**
     * 获得当前代理配置
     *
     * @return array
     */
    public function getProxy(): array
    {
        return $this->proxy;
    }

    /**
     * 配置HTTP代理
     *
     * @param string $host
     * @param int $port
     * @return $this
     */
    public function withProxy(string $host, int $port): self
    {
        $this->proxy = [
            'http_proxy_host' => $host,
            'http_proxy_port' => $port,
        ];

        return $this;
    }

    /**
     * enable socks5 proxy
     * @param string $host
     * @param int $port
     * @param null|string $username
     * @param null|string $password
     * @return $this
     */
    public function withSocks5(string $host, int $port, ?string $username, ?string $password): self
    {
        $this->proxy = [
            'socks5_host' => $host,
            'socks5_port' => $port,
            'socks5_username' => $username,
            'socks5_password' => $password,
        ];

        return $this;
    }

    /**
     * Remove proxy config
     * @return $this
     */
    public function withoutProxy(): self
    {
        $this->proxy = null;

        return $this;
    }

    /**
     * 获取超时时间
     *
     * @return float
     */
    public function getTimeout(): float
    {
        return $this->timeout;
    }

    /**
     * 设定超时时间
     *
     * @param float $timeout
     * @return $this
     */
    public function withTimeout(float $timeout): self
    {
        $this->timeout = $timeout;

        return $this;
    }

    /**
     * 获取重定向次数
     *
     * @return int
     */
    public function getRedirect(): int
    {
        return $this->redirect;
    }

    /** @return mixed */
    public function getName()
    {
        return $this->name;
    }

    /**
     * @param $name
     * @return $this
     */
    public function withName($name): self
    {
        $this->name = $name;

        return $this;
    }

    /**
     * 设置最大重定向次数,为0则不重定向
     *
     * @param int $time
     * @return $this
     */
    public function withRedirect(int $time): self
    {
        $this->redirect = $time;

        return $this;
    }

    /**
     * @param bool $enable
     * @return $this
     */
    public function withRedirectWait(bool $enable): self
    {
        $this->redirect_wait = $enable;

        return $this;
    }

    /** @return bool */
    public function getRedirectWait(): bool
    {
        return $this->redirect_wait;
    }

    /**
     * Clear the swoole client to make it back to the first state.
     *
     * @param $client
     */
    public function resetClient(&$client)
    {
        //TODO
    }

    /**
     * 执行当前Request
     *
     * @return $this|mixed
     */
    public function exec()
    {
        /** 请求前拦截器 */
        $ret = $this->callInterceptor('request', $this);
        if ($ret !== null) {
            return $ret;
        }

        /** 获取IP地址 */
        $host = $this->uri->getHost();
        //TODO: get ip error / ip cache
        $ip = Coroutine::getHostByName($host);
        if (empty($ip)) {
            throw new ConnectException($this, 'Get ip failed!');
        }
        $port = $this->uri->getPort();
        $is_ssl = $this->isSSL();

        /** 新建协程HTTP客户端 */
        /** @noinspection PhpUndefinedFieldInspection */
        if (!$this->client || $this->client->host !== $ip || $this->client->port !== $port || $this->client->isSSL !== $is_ssl) {
            $this->client = new Client($ip, $port, $is_ssl);
            $this->client->isSSL = $is_ssl;
        }

        /** 设定配置项 */
        $settings = [
            'timeout' => $this->getTimeout(),
            'keep_alive' => $this->getKeepAlive(),
        ];
        $settings += $this->getProxy();

        if (!empty($ca_file = $this->getCAFile())) {
            $settings += [
                'ssl_verify_peer' => true,
                'ssl_allow_self_signed' => true,
                'ssl_cafile' => $ca_file,
            ];
        }
        $this->client->set($settings);

        /** 清空client自带的不靠谱的cookie */
        $this->client->cookies = null;

        /** 设置请求头 */
        $cookie =
            $this->cookies->toRequestString($this->uri) .
            $this->incremental_cookies->toRequestString($this->uri);

        $headers = ['Host' => $this->uri->getHost()] + $this->getHeaders(true, true);
        if (!empty($cookie) && empty($headers['Cookie'])) {
            $headers['Cookie'] = $cookie;
        }
        $this->client->setHeaders($headers);

        /** 设置请求方法 */
        $this->client->setMethod($this->getMethod());
        /** 设置请求主体 */
        $body = (string)($this->getBody() ?? '');
        if (!empty($body)) {
            $this->client->setData($body);
        }

        parse_str($this->uri->getQuery(), $query);
        $query = $this->getQueryParams() + $query; //attribute value first
        $query = http_build_query($query);

        $path = $this->uri->getPath() ?: '/';
        $path = empty($query) ? $path : $path . '?' . $query;

        /** calc timeout value */
        if ($this->_redirect_times > 0) {
            $timeout = $this->getTimeout() - (microtime(true) - $this->_start_time);
            //TODO timeout exception
        } else {
            $this->_start_time = microtime(true);
            $timeout = $this->getTimeout();
        }
        $this->_timeout = max($timeout, 0.001); //swoole support min 1ms

        $this->client->setDefer(); //总是延迟回包以使用timeout定时器特性
        $this->client->execute($path);
        $this->_status = self::WAITING;

        return $this;
    }

    /**
     * 收包,处理重定向,对返回数据进行处理
     *
     * @return Response|$this|mixed
     */
    public function recv()
    {
        if (self::WAITING !== $this->_status) {
            throw new \BadMethodCallException('You can\'t recv because client is not in waiting stat.');
        }
        $this->client->recv($this->_timeout);
        $this->_status = self::NONE;
        $this->_time = microtime(true) - $this->_start_time;
        if ($this->client->errCode) {
            throw new ConnectException($this, $this->client->errCode, socket_strerror($this->client->errCode));
        }

        //将服务器cookie添加到客户端cookie列表中去
        if (!empty($this->client->set_cookie_headers)) {
            $domain = $this->uri->getHost();
            //in URI, the path must end with '/', cookie path is just the opposite.
            $path = rtrim($this->uri->getDir(), '/');
            $this->incremental_cookies->adds(
                array_values($this->client->set_cookie_headers), [
                'domain' => $domain,
                'path' => $path,
            ]);
        }

        /** 处理重定向 */
        if (($this->client->headers['location'] ?? false) && $this->_redirect_times < $this->redirect) {
            $current_uri = (string)$this->uri;
            $this->_redirect_headers[$current_uri] = $this->client->headers; //记录跳转前的headers
            $location = $this->client->headers['location'];
            $this->uri = Uri::resolve($this->uri, $location);
            if ($this->uri->getPort() === 443) {
                $this->withSSL(true);
            }
            $this->withMethod('GET')
                ->withBody(null)
                ->withHeader('referer', $current_uri)
                ->removeInterceptor('request');

            $ret = $this->callInterceptor('redirect', $response);
            if ($ret !== null) {
                return $ret;
            }

            $this->exec();
            $this->_redirect_times++;

            if ($this->getRedirectWait()) {
                return $this;
            }

            return $this->recv();
        }

        /** 创建响应对象 */
        $response = new Response($this);

        /** 执行回调函数 */
        $ret = $this->callInterceptor('response', $response);
        if ($ret !== null) {
            return $ret;
        }

        /** 重置临时变量 */
        $this->clear();

        return $response;
    }

    private function clear()
    {
        $this->_redirect_times = 0;
        $this->_redirect_headers = [];
        $this->_start_time = 0;
        $this->_time = 0.000;
    }

}