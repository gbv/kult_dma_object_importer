<?php
namespace Denkmalatlas;

use Psr\Log\LoggerInterface;
use GuzzleHttp\ClientInterface;
use Psr\Http\Message\ResponseInterface;

class ApiRequester
{
    private ClientInterface $client;
    private TokenManager $tokenManager;
    private LoggerInterface $logger;

    public function __construct(ClientInterface $client, TokenManager $tokenManager, LoggerInterface $logger)
    {
        $this->client = $client;
        $this->tokenManager = $tokenManager;
        $this->logger = $logger;
    }

    public function request(string $url): ResponseInterface
    {
        try {
            $accessToken = $this->tokenManager->getAccessToken();
            return $this->client->request('GET', $url, [
                'headers' => ['Authorization' => "Bearer {$accessToken}"]
            ]);
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $response = $e->getResponse();
            if ($response && $response->getStatusCode() === 401) {
                $this->logger->warning("Token invalid — refreshing…");
                $newToken = $this->tokenManager->getAccessToken(true);
                return $this->client->request('GET', $url, [
                    'headers' => ['Authorization' => "Bearer {$newToken}"]
                ]);
            }
            throw $e;
        }
    }
}
?>