<?php

class TokenManager
{
    private $client;
    private $tokenStorage;
    private $localSettings;
    private $logger;
    private $clientId;
    private $clientSecret;

    public function __construct($client, $tokenStorage, $localSettings, $logger)
    {
        $this->client = $client;
        $this->tokenStorage = $tokenStorage;
        $this->localSettings = $localSettings;
        $this->logger = $logger;
        $this->clientId = 'web-client';
        $this->clientSecret = 'foo';
    }

    public function getAccessToken()
    {
        $tokenData = $this->tokenStorage->load();

        // check for existing valid token
        if ($tokenData && $tokenData['expires_at'] > time()) {
            return $tokenData['access_token'];
        }

        // check for refresh token
        if ($tokenData && !empty($tokenData['refresh_token'])) {
            return $this->refreshToken($tokenData['refresh_token']);
        }

        // get new token
        return $this->fetchNewToken();
    }

    private function fetchNewToken()
    {
        $response = $this->client->request('POST', $this->localSettings['api_token_url'], [
            'form_params' => [
                'username'=>$this->localSettings['api_username'],
                'password' => $this->localSettings['api_password'],
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
        $response = $this->client->request('POST', $this->localSettings['api_token_url'], [
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
        $data['expires_at'] = time() + $data['expires_in'];
        $this->tokenStorage->save($data);
    }
}

?>
