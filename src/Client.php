<?php

declare(strict_types=1);
/**
 * This file is part of icomet.
 *
 * @link     https://github.com/friendsofhyperf/icomet
 * @document https://github.com/friendsofhyperf/icomet/blob/main/README.md
 * @contact  huangdijia@gmail.com
 */
namespace FriendsOfHyperf\IComet;

use FriendsOfHyperf\Http\Client\Http;
use FriendsOfHyperf\Http\Client\PendingRequest;
use FriendsOfHyperf\IComet\Http\ClientFactory;
use FriendsOfHyperf\IComet\Http\Response;
use Hyperf\Utils\Coroutine\Concurrent;
use Psr\Container\ContainerInterface;
use RuntimeException;

class Client implements ClientInterface
{
    /**
     * @var array
     */
    protected $config;

    /**
     * @var ClientFactory
     */
    protected $clientFactory;

    /**
     * @var Concurrent
     */
    protected $concurrent;

    public function __construct(ContainerInterface $container, array $config = [])
    {
        $this->config = $config;
        $this->concurrent = new Concurrent((int) data_get($config, 'concurrent.limit', 128));
    }

    public function sign($cname, int $expires = 60)
    {
        return $this->client()
            ->get('/sign', compact('cname', 'expires'))
            ->throw()
            ->json();
    }

    public function push($cname, $content)
    {
        if (is_array($content)) {
            $content = json_encode($content, JSON_UNESCAPED_UNICODE);
        }

        return $this->client()
            ->get('/push', compact('cname', 'content'))
            ->throw()
            ->json('type') == 'ok';
    }

    public function broadcast($content, $cnames = null)
    {
        if (is_array($content)) {
            $content = json_encode($content, JSON_UNESCAPED_UNICODE);
        }

        if (is_null($cnames)) {
            return $this->client()
                ->get('/broadcast', compact('content'))
                ->throw()
                ->body() == 'ok';
        }

        foreach ((array) $cnames as $cname) {
            $this->concurrent->create(function () use ($cname, $content) {
                $this->push($cname, $content);
            });
        }

        return true;
    }

    public function check($cname)
    {
        return with(
            $this->client()
                ->get('/check', compact('cname'))
                ->throw()
                ->json(),
            function ($json) use ($cname) {
                return isset($json[$cname]);
            }
        );
    }

    public function close($cname)
    {
        with(
            $this->client()
                ->get('/close', compact('cname'))
                ->throw()
                ->body(),
            function ($body) {
                return substr($body, 0, 2) == 'ok';
            }
        );
    }

    public function clear($cname)
    {
        return with(
            $this->client()
                ->get('/clear', compact('cname'))
                ->throw()
                ->body(),
            function ($body) {
                return substr($body, 0, 2) == 'ok';
            }
        );
    }

    public function info($cname = '')
    {
        return $this->client()
            ->get('/info', $cname ? compact('cname') : [])
            ->throw()
            ->json();
    }

    public function psub(callable $callback)
    {
        $url = rtrim(data_get($this->config, 'uri'), '/') . '/psub';
        $handle = fopen($url, 'rb');

        if ($handle === false) {
            throw new RuntimeException('Cannot open ' . $url);
        }

        while (! feof($handle)) {
            $line = fread($handle, 8192);
            $line = trim($line);

            if (empty($line)) {
                continue;
            }

            $data = explode(' ', $line, 2);
            $status = (int) ($data[0] ?? 0);
            $channel = (int) ($data[1] ?? 0);

            $callback($channel, $status);
        }

        fclose($handle);
    }

    protected function client(): PendingRequest
    {
        return Http::baseUrl(data_get($this->config, 'uri'))
            ->timeout((int) data_get($this->config, 'timeout', 5));
    }
}
