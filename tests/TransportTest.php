<?php
/**
 * Elastic Transport
 *
 * @link      https://github.com/elastic/elastic-transport-php
 * @copyright Copyright (c) Elasticsearch B.V (https://www.elastic.co)
 * @license   https://opensource.org/licenses/MIT MIT License
 *
 * Licensed to Elasticsearch B.V under one or more agreements.
 * Elasticsearch B.V licenses this file to you under the MIT License.
 * See the LICENSE file in the project root for more information.
 */
declare(strict_types=1);

namespace Elastic\Transport\Test;

use Elastic\Transport\Exception\NoNodeAvailableException;
use Elastic\Transport\NodePool\Node;
use Elastic\Transport\NodePool\NodePoolInterface;
use Elastic\Transport\Transport;
use Http\Client\Exception\TransferException;
use Http\Client\HttpAsyncClient;
use Http\Discovery\Psr17FactoryDiscovery;
use Http\Mock\Client;
use Http\Promise\Promise;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\Test\TestLogger;

final class TransportTest extends TestCase
{
    private ClientInterface $client;
    private NodePoolInterface $nodePool;
    private LoggerInterface $logger;
    private Transport $transport;

    private RequestFactoryInterface $requestFactory;
    private ResponseFactoryInterface $responseFactory;
    private UriFactoryInterface $uriFactory;

    public function setUp(): void
    {
        $this->client = new Client();
        $this->nodePool = $this->createStub(NodePoolInterface::class);
        $this->logger = new TestLogger();
        $this->transport = new Transport($this->client, $this->nodePool, $this->logger);

        $this->requestFactory = Psr17FactoryDiscovery::findRequestFactory();
        $this->responseFactory = Psr17FactoryDiscovery::findResponseFactory();
        $this->uriFactory = Psr17FactoryDiscovery::findUriFactory();
        $this->node = $this->createStub(Node::class);
    }

    public function testGetClient()
    {
        $this->assertEquals($this->client, $this->transport->getClient());
    }

    public function testGetNodePool()
    {
        $this->assertEquals($this->nodePool, $this->transport->getNodePool());
    }

    public function testGetLogger()
    {
        $this->assertEquals($this->logger, $this->transport->getLogger());
    }

    public function testSendRequestWith200Response()
    {
        $expectedResponse = $this->responseFactory->createResponse(200);
        $this->client->addResponse($expectedResponse);

        $this->node->method('getUri')
            ->willReturn($this->uriFactory->createUri('http://localhost'));
        $this->nodePool->method('nextNode')
            ->willReturn($this->node);

        $request = $this->requestFactory->createRequest('GET', '/');
        $response = $this->transport->sendRequest($request);
        
        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals($expectedResponse, $response);
    }

    public function testSendRequestWithNetworkException()
    {
        $request = $this->requestFactory->createRequest('GET', '/');

        $expectedException = $this->createStub(NetworkExceptionInterface::class);
        $expectedException->method('getRequest')
            ->willReturn($request);

        $this->client->addException($expectedException);
        $this->expectException(NoNodeAvailableException::class);

        $this->node->method('getUri')
            ->willReturn($this->uriFactory->createUri('http://localhost'));
        $this->nodePool->method('nextNode')
            ->willReturn($this->node);

        $this->transport->sendRequest($request);    
    }

    public function testSendRequestWithNetworkExceptionLogError()
    {
        $request = $this->requestFactory->createRequest('GET', '/');

        $expectedException = $this->createStub(NetworkExceptionInterface::class);
        $expectedException->method('getRequest')
            ->willReturn($request);

        $this->client->addException($expectedException);
       
        try {
            $this->node->method('getUri')
                ->willReturn($this->uriFactory->createUri('http://localhost'));
            $this->nodePool->method('nextNode')
                ->willReturn($this->node);

            $this->transport->sendRequest($request);
        } catch (NoNodeAvailableException $e) {
            $this->assertTrue(
                $this->logger->hasErrorThatContains('Exceeded maximum number of retries (0)')
            );
            $this->assertTrue(
                $this->logger->hasErrorThatContains('Retry 0')
            );
        }
    }

    public function testSendRequestWithClientException()
    {
        $expectedException = new TransferException('Error Transfer');
        $this->client->addException($expectedException);
        
        $this->expectException(ClientExceptionInterface::class);

        $this->node->method('getUri')
            ->willReturn($this->uriFactory->createUri('http://localhost'));
        $this->nodePool->method('nextNode')
            ->willReturn($this->node);
        
        $request = $this->requestFactory->createRequest('GET', '/');

        $this->transport->sendRequest($request);
    }

    public function testSendRequestWithClientExceptionLogError()
    {
        $expectedException = new TransferException('Error Transfer');
        $this->client->addException($expectedException);
        
        try {
            $this->node->method('getUri')
                ->willReturn($this->uriFactory->createUri('http://localhost'));
            $this->nodePool->method('nextNode')
                ->willReturn($this->node);
            
            $request = $this->requestFactory->createRequest('GET', '/');

            $this->transport->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            $this->assertTrue($this->logger->hasError([
                'message' => sprintf("Retry 0: %s", $e->getMessage())
            ]));
        }
    }

    public function testSendRequestWithQueryAndEmptyHost()
    {
        $expectedResponse = $this->responseFactory->createResponse(200);
        $this->client->addResponse($expectedResponse);

        $this->node->method('getUri')
            ->willReturn($this->uriFactory->createUri('http://localhost'));
        $this->nodePool->method('nextNode')
            ->willReturn($this->node);

        $request = $this->requestFactory->createRequest('GET', '/');
        $request = $request->withUri($request->getUri()->withQuery('name=test'));
        $this->transport->sendRequest($request);

        $lastRequest = $this->transport->getLastRequest();
        $this->assertEquals('name=test', $lastRequest->getUri()->getQuery());
    }

    public function testSendRequestWithQueryAndHost()
    {
        $expectedResponse = $this->responseFactory->createResponse(200);
        $this->client->addResponse($expectedResponse);

        $request = $this->requestFactory->createRequest('GET', 'http://localhost/');
        $request = $request->withUri($request->getUri()->withQuery('name=test'));
        $this->transport->sendRequest($request);

        $lastRequest = $this->transport->getLastRequest();
        $this->assertEquals('name=test', $lastRequest->getUri()->getQuery());
        $this->assertEquals('localhost', $lastRequest->getUri()->getHost());
        $this->assertEquals('http', $lastRequest->getUri()->getScheme());
    }

    public function testSendRequestWithHost()
    {
        $expectedResponse = $this->responseFactory->createResponse(200);
        $this->client->addResponse($expectedResponse);

        $request = $this->requestFactory->createRequest('GET', 'https://domain/path');
        $this->transport->sendRequest($request);

        $lastRequest = $this->transport->getLastRequest();
        $this->assertEquals('https', $lastRequest->getUri()->getScheme());
        $this->assertEquals('domain', $lastRequest->getUri()->getHost());
        $this->assertEquals('/path', $lastRequest->getUri()->getPath());
    }

    public function testSendRequestWithHostAndPort()
    {
        $expectedResponse = $this->responseFactory->createResponse(200);
        $this->client->addResponse($expectedResponse);

        $request = $this->requestFactory->createRequest('GET', 'https://domain:9200/path');
        $this->transport->sendRequest($request);

        $lastRequest = $this->transport->getLastRequest();
        $this->assertEquals('domain', $lastRequest->getUri()->getHost());
        $this->assertEquals('/path', $lastRequest->getUri()->getPath());
        $this->assertEquals(9200, $lastRequest->getUri()->getPort());
    }

    public function testLoggerWithSendRequest()
    {
        $statusCode = 200;
        $msg = 'Hello, World';

        $expectedResponse = $this->responseFactory->createResponse($statusCode);
        $body = $expectedResponse->getBody();
        $body->write($msg);
        $expectedResponse = $expectedResponse->withBody($body)->withHeader('X-Foo', 'Bar');
        $this->client->addResponse($expectedResponse);

        $this->node->method('getUri')
            ->willReturn($this->uriFactory->createUri('http://localhost'));
        $this->nodePool->method('nextNode')
            ->willReturn($this->node);

        $request = $this->requestFactory->createRequest('GET', '/');
        $this->transport->sendRequest($request);

        $this->assertTrue($this->logger->hasInfo([
            'message' => "Request: GET http://localhost/"
        ]));
        $this->assertTrue($this->logger->hasDebug([
            'message' => "Headers: {\"Host\":[\"localhost\"]}\nBody: "
        ]));
        $this->assertTrue($this->logger->hasInfo([
            'message' => sprintf("Response (retry 0): %s", $statusCode),
        ]));
        $this->assertTrue($this->logger->hasDebug([
            'message' => "Headers: {\"X-Foo\":[\"Bar\"]}\nBody: "
        ]));
    }

    public function testGetLastRequest()
    {
        $expectedResponse = $this->responseFactory->createResponse(200);
        $this->client->addResponse($expectedResponse);

        $this->node->method('getUri')
            ->willReturn($this->uriFactory->createUri('http://localhost'));
        $this->nodePool->method('nextNode')
            ->willReturn($this->node);

        $request = $this->requestFactory->createRequest('GET', '/');
        $response = $this->transport->sendRequest($request);

        $lastRequest = $this->transport->getLastRequest();
        // Test the request decoration with http://localhost
        $this->assertEquals('localhost', $lastRequest->getUri()->getHost());
        $this->assertEquals('http', $lastRequest->getUri()->getScheme());
        $this->assertEquals('GET', $lastRequest->getMethod());
        $this->assertEquals('/', $lastRequest->getUri()->getPath());
    }

    public function testGetLastResponse()
    {
        $expectedResponse = $this->responseFactory->createResponse(200);
        $this->client->addResponse($expectedResponse);

        $this->node->method('getUri')
            ->willReturn($this->uriFactory->createUri('http://localhost'));
        $this->nodePool->method('nextNode')
            ->willReturn($this->node);

        $request = $this->requestFactory->createRequest('GET', '/');
        $response = $this->transport->sendRequest($request);

        $this->assertEquals($response, $this->transport->getLastResponse());
        $this->assertEquals($expectedResponse, $this->transport->getLastResponse());
    }

    public function testSetUserInfo()
    {
        $expectedResponse = $this->responseFactory->createResponse(200);
        $this->client->addResponse($expectedResponse);

        $this->node->method('getUri')
            ->willReturn($this->uriFactory->createUri('http://localhost'));
        $this->nodePool->method('nextNode')
            ->willReturn($this->node);

        $user = 'test';
        $password = '1234567890';

        $this->transport->setUserInfo($user, $password);

        $request = $this->requestFactory->createRequest('GET', '/');
        $response = $this->transport->sendRequest($request);

        $this->assertEquals(
            $user . ':' . $password,
            $this->transport->getLastRequest()->getUri()->getUserInfo(),
        );
        $this->assertEquals(
            'http://test:1234567890@localhost/',
            (string) $this->transport->getLastRequest()->getUri()
        );
    }

    public function testSetHeader()
    {
        $expectedResponse = $this->responseFactory->createResponse(200);
        $this->client->addResponse($expectedResponse);

        $headers = [
            'X-Foo' => 'Bar'
        ];
        $this->transport->setHeader('X-Foo', $headers['X-Foo']);
        $this->assertEquals($headers, $this->transport->getHeaders());
        
        $this->node->method('getUri')
            ->willReturn($this->uriFactory->createUri('http://localhost'));
        $this->nodePool->method('nextNode')
            ->willReturn($this->node);
        
        $request = $this->requestFactory->createRequest('GET', '/');
        $response = $this->transport->sendRequest($request);
        
        $this->assertTrue($this->transport->getLastRequest()->hasHeader('X-Foo'));
        $this->assertEquals($headers['X-Foo'], $this->transport->getLastRequest()->getHeader('X-Foo')[0]);
    }

    public function testSetUserAgent()
    {
        $expectedResponse = $this->responseFactory->createResponse(200);
        $this->client->addResponse($expectedResponse);

        $request = $this->requestFactory->createRequest('GET', 'http://domain/path');
        $this->transport->setUserAgent('test', '1.0');
        $this->transport->sendRequest($request);

        $userAgent = $this->transport->getLastRequest()->getHeader('User-Agent')[0] ?? '';
        $this->assertMatchesRegularExpression('/^test\/1\.0 \(.+\)$/', $userAgent);
    }

    public function testSetElasticMetaHeader()
    {
        $expectedResponse = $this->responseFactory->createResponse(200);
        $this->client->addResponse($expectedResponse);

        $request = $this->requestFactory->createRequest('GET', 'http://domain/path');
        $this->transport->setElasticMetaHeader('es', '7.11.0');
        $this->transport->sendRequest($request);

        $meta = $this->transport->getLastRequest()->getHeader('x-elastic-client-meta')[0] ?? null;
        $this->assertMatchesRegularExpression('/^[a-z]{1,}=[a-z0-9\.\-]{1,}(?:,[a-z]{1,}=[a-z0-9\.\-]+)*$/', $meta);
    }

    public function testSetElasticMetaHeaderWithSnapshotVersion()
    {
        $expectedResponse = $this->responseFactory->createResponse(200);
        $this->client->addResponse($expectedResponse);

        $request = $this->requestFactory->createRequest('GET', 'http://domain/path');
        $this->transport->setElasticMetaHeader('es', '7.11.0-snapshot');
        $this->transport->sendRequest($request);

        $meta = $this->transport->getLastRequest()->getHeader('x-elastic-client-meta')[0] ?? null;
        $this->assertStringContainsString('es=7.11.0-p', $meta);
    }

    public function testSetRetries()
    {
        $this->transport->setRetries(1);
        $this->assertEquals(1, $this->transport->getRetries());
    }
    
    /**
     * @group async
     */
    public function testSetAsyncClient()
    {
        $asyncClient = $this->createStub(HttpAsyncClient::class);
        $this->transport->setAsyncClient($asyncClient);
        $this->assertEquals($asyncClient, $this->transport->getAsyncClient());
    }

    /**
     * @group async
     */
    public function testSendAsyncClient()
    {
        $expectedResponse = $this->responseFactory->createResponse(200);
        $this->client->addResponse($expectedResponse);

        $request = $this->requestFactory->createRequest('GET', 'http://localhost');
        $response = $this->transport->sendAsyncRequest($request);
        $this->assertInstanceOf(Promise::class, $response);
    }
}