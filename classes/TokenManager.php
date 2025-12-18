<?php
namespace Denkmalatlas;

class TokenManager
{
  private $client;
  private $tokenStorage;
  private $settings;
  private $logger;
  private $clientId;
  private $clientSecret;

  public function __construct($client, $tokenStorage, $settings, $logger)
  {
    $this->client = $client;
    $this->tokenStorage = $tokenStorage;
    $this->settings = $settings;
    $this->logger = $logger;
    $this->clientId = 'web-client';
    $this->clientSecret = 'foo';
  }

  public function getAccessToken($force=false)
  {
    // do not use token storage
    // simply get new token
    if ($force) {
      return $this->fetchNewToken();
    }

    // load token data from storage
    $tokenData = $this->tokenStorage->load();

    // check for existing valid token
    if ($tokenData && $tokenData['expires_at'] > time()) {
      return $tokenData['access_token'];
    }

    // check for refresh token
    if ($tokenData && !empty($tokenData['refresh_token'])) {
      return $this->refreshToken($tokenData['refresh_token']);
    }

    // if no token was found in storage
    // get new token
    return $this->fetchNewToken();
  }

  private function fetchNewToken()
  {
    $response = $this->client->request('POST', $this->settings->tokenUrl, [
      'form_params' => [
        'username' => $this->settings->apiUsername,
        'password' => $this->settings->apiPassword,
        'client_id' => $this->clientId,
        'client_secret' => $this->clientSecret,
        'grant_type' => 'password',
        'scope' => 'offline'
      ]
    ]);
    $data = json_decode($response->getBody(), true);

    $this->storeToken($data);
    return $data['access_token'];
  }

  private function refreshToken($refreshToken)
  {
    $response = $this->client->request('POST', $this->settings->tokenUrl, [
      'form_params' => [
        'grant_type' => 'refresh_token',
        'refresh_token' => $refreshToken,
        'client_id' => $this->clientId,
        'client_secret' => $this->clientSecret
      ]
    ]);

    $data = json_decode($response->getBody(), true);

    $this->storeToken($data);
    return $data['access_token'];
  }

  private function storeToken($data)
  {
    // compute absolute expiry timestamp
    // with small leeway to avoid using near-expiry tokens
    $expiresIn = isset($data['expires_in']) ? (int)$data['expires_in'] : 0;
    $leeway = 3600; // refresh X seconds before actual expiry
    $data['expires_at'] = time() + max(0, $expiresIn - $leeway);
    $this->tokenStorage->save($data);
  }
}

?>