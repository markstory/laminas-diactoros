<?php

declare(strict_types=1);

namespace LaminasTest\Diactoros\ServerRequestFilter;

use Laminas\Diactoros\ConfigProvider;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\ServerRequestFilter\LegacyXForwardedHeaderFilter;
use Laminas\Diactoros\ServerRequestFilter\LegacyXForwardedHeaderFilterFactory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

class LegacyXForwardedHeaderFilterFactoryTest extends TestCase
{
    /** @var ContainerInterface */
    private $container;

    public function setUp(): void
    {
        $this->container = new class() implements ContainerInterface {
            private $services = [];

            /**
             * @param string $id
             * @return bool
             */
            public function has($id)
            {
                return array_key_exists($id, $this->services);
            }

            /**
             * @param string $id
             * @return mixed
             */
            public function get($id)
            {
                if (! array_key_exists($id, $this->services)) {
                    return null;
                }

                return $this->services[$id];
            }

            /** @param mixed $value */
            public function set(string $id, $value): void
            {
                $this->services[$id] = $value;
            }
        };

        $this->container->set('config', []);
    }

    public function generateServerRequest(array $headers, array $server, string $baseUrlString): ServerRequest
    {
        return new ServerRequest($server, [], $baseUrlString, 'GET', 'php://temp', $headers);
    }

    /** @psalm-return iterable<string, array{0: string}> */
    public function randomIpGenerator(): iterable
    {
        yield 'class-a' => ['10.1.1.1'];
        yield 'class-c' => ['192.168.1.1'];
        yield 'localhost' => ['127.0.0.1'];
        yield 'public' => ['4.4.4.4'];
    }

    /** @dataProvider randomIpGenerator */
    public function testIfNoConfigPresentFactoryReturnsFilterThatDoesNotTrustAny(string $remoteAddr): void
    {
        $factory = new LegacyXForwardedHeaderFilterFactory();
        $filter = $factory($this->container);
        $request = $this->generateServerRequest(
            [
                'Host' => 'localhost',
                LegacyXForwardedHeaderFilter::HEADER_HOST  => 'api.example.com',
                LegacyXForwardedHeaderFilter::HEADER_PROTO => 'https',
            ],
            [
                'REMOTE_ADDR' => $remoteAddr,
            ],
            'http://localhost/foo/bar',
        );

        $filteredRequest = $filter->filterRequest($request);
        $this->assertSame($request, $filteredRequest);
    }

    /** @psalm-return iterable<string, array{0: string, 1: array<string, string>}> */
    public function trustAnyProvider(): iterable
    {
        $headers = [
            LegacyXForwardedHeaderFilter::HEADER_HOST  => 'api.example.com',
            LegacyXForwardedHeaderFilter::HEADER_PROTO => 'https',
            LegacyXForwardedHeaderFilter::HEADER_PORT  => '4443',
        ];

        foreach ($this->randomIpGenerator() as $name => $arguments) {
            $arguments[] = $headers;
            yield $name => $arguments;
        }
    }

    /** @dataProvider trustAnyProvider */
    public function testIfTrustAnyFlagIsEnabledReturnsFilterConfiguredToTrustAny(
        string $remoteAddr,
        array $headers
    ): void {
        $headers['Host'] = 'localhost';
        $this->container->set('config', [
            ConfigProvider::CONFIG_KEY => [
                ConfigProvider::LEGACY_X_FORWARDED => [
                    ConfigProvider::LEGACY_X_FORWARDED_TRUST_ANY => true,
                ],
            ],
        ]);

        $factory = new LegacyXForwardedHeaderFilterFactory();
        $filter = $factory($this->container);
        $request = $this->generateServerRequest(
            $headers,
            ['REMOTE_ADDR' => $remoteAddr],
            'http://localhost/foo/bar',
        );

        $filteredRequest = $filter->filterRequest($request);
        $this->assertNotSame($request, $filteredRequest);

        $uri = $filteredRequest->getUri();
        $this->assertSame($headers[LegacyXForwardedHeaderFilter::HEADER_HOST], $uri->getHost());
        // Port is always cast to int
        $this->assertSame((int) $headers[LegacyXForwardedHeaderFilter::HEADER_PORT], $uri->getPort());
        $this->assertSame($headers[LegacyXForwardedHeaderFilter::HEADER_PROTO], $uri->getScheme());
    }

    /** @dataProvider trustAnyProvider */
    public function testEnabledTrustAnyFlagHasPrecedenceOverTrustedProxiesConfig(
        string $remoteAddr,
        array $headers
    ): void {
        $headers['Host'] = 'localhost';
        $this->container->set('config', [
            ConfigProvider::CONFIG_KEY => [
                ConfigProvider::LEGACY_X_FORWARDED => [
                    ConfigProvider::LEGACY_X_FORWARDED_TRUST_ANY => true,
                    ConfigProvider::LEGACY_X_FORWARDED_TRUSTED_PROXIES => [
                        '192.168.0.0/24',
                    ],
                ],
            ],
        ]);

        $factory = new LegacyXForwardedHeaderFilterFactory();
        $filter = $factory($this->container);
        $request = $this->generateServerRequest(
            $headers,
            ['REMOTE_ADDR' => $remoteAddr],
            'http://localhost/foo/bar',
        );

        $filteredRequest = $filter->filterRequest($request);
        $this->assertNotSame($request, $filteredRequest);

        $uri = $filteredRequest->getUri();
        $this->assertSame($headers[LegacyXForwardedHeaderFilter::HEADER_HOST], $uri->getHost());
        // Port is always cast to int
        $this->assertSame((int) $headers[LegacyXForwardedHeaderFilter::HEADER_PORT], $uri->getPort());
        $this->assertSame($headers[LegacyXForwardedHeaderFilter::HEADER_PROTO], $uri->getScheme());
    }

    /** @dataProvider randomIpGenerator */
    public function testEmptyProxiesListDoesNotTrustXForwardedHeaders(string $remoteAddr): void
    {
        $this->container->set('config', [
            ConfigProvider::CONFIG_KEY => [
                ConfigProvider::LEGACY_X_FORWARDED => [
                    ConfigProvider::LEGACY_X_FORWARDED_TRUST_ANY => false,
                    ConfigProvider::LEGACY_X_FORWARDED_TRUSTED_PROXIES => [],
                    ConfigProvider::LEGACY_X_FORWARDED_TRUSTED_HEADERS => [
                        LegacyXForwardedHeaderFilter::HEADER_HOST,
                    ],
                ],
            ],
        ]);

        $factory = new LegacyXForwardedHeaderFilterFactory();
        $filter = $factory($this->container);
        $request = $this->generateServerRequest(
            [
                'Host' => 'localhost',
                LegacyXForwardedHeaderFilter::HEADER_HOST  => 'api.example.com',
                LegacyXForwardedHeaderFilter::HEADER_PROTO => 'https',
            ],
            [
                'REMOTE_ADDR' => $remoteAddr,
            ],
            'http://localhost/foo/bar',
        );

        $filteredRequest = $filter->filterRequest($request);
        $this->assertSame($request, $filteredRequest);
    }

    /** @dataProvider randomIpGenerator */
    public function testEmptyHeadersListTrustsAllXForwardedHeadersForMatchedProxies(string $remoteAddr): void
    {
        $this->container->set('config', [
            ConfigProvider::CONFIG_KEY => [
                ConfigProvider::LEGACY_X_FORWARDED => [
                    ConfigProvider::LEGACY_X_FORWARDED_TRUST_ANY => false,
                    ConfigProvider::LEGACY_X_FORWARDED_TRUSTED_PROXIES => ['0.0.0.0/0'],
                    ConfigProvider::LEGACY_X_FORWARDED_TRUSTED_HEADERS => [],
                ],
            ],
        ]);

        $factory = new LegacyXForwardedHeaderFilterFactory();
        $filter = $factory($this->container);
        $request = $this->generateServerRequest(
            [
                'Host' => 'localhost',
                LegacyXForwardedHeaderFilter::HEADER_HOST  => 'api.example.com',
                LegacyXForwardedHeaderFilter::HEADER_PROTO => 'https',
                LegacyXForwardedHeaderFilter::HEADER_PORT  => '4443',
            ],
            [
                'REMOTE_ADDR' => $remoteAddr,
            ],
            'http://localhost/foo/bar',
        );

        $filteredRequest = $filter->filterRequest($request);
        $this->assertNotSame($request, $filteredRequest);

        $uri = $filteredRequest->getUri();
        $this->assertSame('api.example.com', $uri->getHost());
        $this->assertSame(4443, $uri->getPort());
        $this->assertSame('https', $uri->getScheme());
    }

    /**
     * @psalm-return iterable<string, array{
     *     0: bool,
     *     1: array<string, array<string, array<string, mixed>>>,
     *     2: array<string, string>,
     *     3: array<string, string>,
     *     4: string,
     *     5: string
     * }>
     */
    public function trustedProxiesAndHeaders(): iterable
    {
        yield 'string-proxy-single-header' => [
            false,
            [
                ConfigProvider::CONFIG_KEY => [
                    ConfigProvider::LEGACY_X_FORWARDED => [
                        ConfigProvider::LEGACY_X_FORWARDED_TRUST_ANY => false,
                        ConfigProvider::LEGACY_X_FORWARDED_TRUSTED_PROXIES => '192.168.1.1',
                        ConfigProvider::LEGACY_X_FORWARDED_TRUSTED_HEADERS => [
                            LegacyXForwardedHeaderFilter::HEADER_HOST,
                        ],
                    ],
                ],
            ],
            [
                'Host' => 'localhost',
                LegacyXForwardedHeaderFilter::HEADER_HOST  => 'api.example.com',
                LegacyXForwardedHeaderFilter::HEADER_PROTO => 'https',
                LegacyXForwardedHeaderFilter::HEADER_PORT  => '4443',
            ],
            ['REMOTE_ADDR' => '192.168.1.1'],
            'http://localhost/foo/bar',
            'http://api.example.com/foo/bar',
        ];

        yield 'single-proxy-single-header' => [
            false,
            [
                ConfigProvider::CONFIG_KEY => [
                    ConfigProvider::LEGACY_X_FORWARDED => [
                        ConfigProvider::LEGACY_X_FORWARDED_TRUST_ANY => false,
                        ConfigProvider::LEGACY_X_FORWARDED_TRUSTED_PROXIES => ['192.168.1.1'],
                        ConfigProvider::LEGACY_X_FORWARDED_TRUSTED_HEADERS => [
                            LegacyXForwardedHeaderFilter::HEADER_HOST,
                        ],
                    ],
                ],
            ],
            [
                'Host' => 'localhost',
                LegacyXForwardedHeaderFilter::HEADER_HOST  => 'api.example.com',
                LegacyXForwardedHeaderFilter::HEADER_PROTO => 'https',
                LegacyXForwardedHeaderFilter::HEADER_PORT  => '4443',
            ],
            ['REMOTE_ADDR' => '192.168.1.1'],
            'http://localhost/foo/bar',
            'http://api.example.com/foo/bar',
        ];

        yield 'single-proxy-multi-header' => [
            false,
            [
                ConfigProvider::CONFIG_KEY => [
                    ConfigProvider::LEGACY_X_FORWARDED => [
                        ConfigProvider::LEGACY_X_FORWARDED_TRUST_ANY => false,
                        ConfigProvider::LEGACY_X_FORWARDED_TRUSTED_PROXIES => ['192.168.1.1'],
                        ConfigProvider::LEGACY_X_FORWARDED_TRUSTED_HEADERS => [
                            LegacyXForwardedHeaderFilter::HEADER_HOST,
                            LegacyXForwardedHeaderFilter::HEADER_PROTO,
                        ],
                    ],
                ],
            ],
            [
                'Host' => 'localhost',
                LegacyXForwardedHeaderFilter::HEADER_HOST  => 'api.example.com',
                LegacyXForwardedHeaderFilter::HEADER_PROTO => 'https',
                LegacyXForwardedHeaderFilter::HEADER_PORT  => '4443',
            ],
            ['REMOTE_ADDR' => '192.168.1.1'],
            'http://localhost/foo/bar',
            'https://api.example.com/foo/bar',
        ];

        yield 'unmatched-proxy-single-header' => [
            true,
            [
                ConfigProvider::CONFIG_KEY => [
                    ConfigProvider::LEGACY_X_FORWARDED => [
                        ConfigProvider::LEGACY_X_FORWARDED_TRUST_ANY => false,
                        ConfigProvider::LEGACY_X_FORWARDED_TRUSTED_PROXIES => ['192.168.1.1'],
                        ConfigProvider::LEGACY_X_FORWARDED_TRUSTED_HEADERS => [
                            LegacyXForwardedHeaderFilter::HEADER_HOST,
                        ],
                    ],
                ],
            ],
            [
                'Host' => 'localhost',
                LegacyXForwardedHeaderFilter::HEADER_HOST  => 'api.example.com',
                LegacyXForwardedHeaderFilter::HEADER_PROTO => 'https',
                LegacyXForwardedHeaderFilter::HEADER_PORT  => '4443',
            ],
            ['REMOTE_ADDR' => '192.168.2.1'],
            'http://localhost/foo/bar',
            'http://localhost/foo/bar',
        ];

        yield 'matches-proxy-from-list-single-header' => [
            false,
            [
                ConfigProvider::CONFIG_KEY => [
                    ConfigProvider::LEGACY_X_FORWARDED => [
                        ConfigProvider::LEGACY_X_FORWARDED_TRUST_ANY => false,
                        ConfigProvider::LEGACY_X_FORWARDED_TRUSTED_PROXIES => ['192.168.1.0/24', '192.168.2.0/24'],
                        ConfigProvider::LEGACY_X_FORWARDED_TRUSTED_HEADERS => [
                            LegacyXForwardedHeaderFilter::HEADER_HOST,
                        ],
                    ],
                ],
            ],
            [
                'Host' => 'localhost',
                LegacyXForwardedHeaderFilter::HEADER_HOST  => 'api.example.com',
                LegacyXForwardedHeaderFilter::HEADER_PROTO => 'https',
                LegacyXForwardedHeaderFilter::HEADER_PORT  => '4443',
            ],
            ['REMOTE_ADDR' => '192.168.2.1'],
            'http://localhost/foo/bar',
            'http://api.example.com/foo/bar',
        ];
    }

    /** @dataProvider trustedProxiesAndHeaders */
    public function testCombinedProxiesAndHeadersDefineTrust(
        bool $expectUnfiltered,
        array $config,
        array $headers,
        array $server,
        string $baseUriString,
        string $expectedUriString
    ): void {
        $this->container->set('config', $config);

        $factory = new LegacyXForwardedHeaderFilterFactory();
        $filter = $factory($this->container);
        $request = $this->generateServerRequest($headers, $server, $baseUriString);

        $filteredRequest = $filter->filterRequest($request);

        if ($expectUnfiltered) {
            $this->assertSame($request, $filteredRequest);
            return;
        }

        $this->assertNotSame($request, $filteredRequest);
        $this->assertSame($expectedUriString, $filteredRequest->getUri()->__toString());
    }
}
