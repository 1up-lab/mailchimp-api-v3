<?php

namespace Oneup\MailChimp;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\RequestException;
use Oneup\MailChimp\Exception\ApiException;

class Client
{
    const STATUS_SUBSCRIBED = 'subscribed';
    const STATUS_PENDING = 'pending';

    /** @var Client $client */
    protected $client;
    protected $apiKey;
    protected $apiEndpoint = 'https://%dc%.api.mailchimp.com/3.0/';
    protected $headers = [];

    public function __construct($apiKey)
    {
        $this->apiKey = $apiKey;

        list(, $dc) = explode('-', $this->apiKey);
        $this->apiEndpoint = preg_replace('/%dc%/', $dc, $this->apiEndpoint);

        $this->headers = [
            'Accept' => 'application/vnd.api+json',
            'Content-Type' => 'application/vnd.api+json',
            'Authorization' => 'apikey '.$this->apiKey,
            'User-Agent' => '1up/mailchimp-api-v3 (https://github.com/1up-lab/mailchimp-api-v3)',
        ];

        $this->client = new GuzzleClient([
            'base_uri' => $this->apiEndpoint,
        ]);
    }

    public function call($type = 'get', $uri = '', $args = [], $timeout = 10)
    {
        $args['apikey'] = $this->apiKey;
        $response = null;

        try {
            switch ($type) {
                case 'post':
                    $response = $this->client->request('POST', $uri, [
                        'json' => $args,
                        'timeout' => $timeout,
                        'headers' => $this->headers,
                    ]);
                    break;

                case 'patch':
                    $response = $this->client->request('PATCH', $uri, [
                        'body' => json_encode($args),
                        'timeout' => $timeout,
                        'headers' => $this->headers,
                    ]);
                    break;

                case 'put':
                    $response = $this->client->request('PUT', $uri, [
                        'body' => json_encode($args),
                        'timeout' => $timeout,
                        'headers' => $this->headers,
                    ]);
                    break;

                case 'delete':
                    $response = $this->client->request('DELETE', $uri, [
                        'query' => $args,
                        'timeout' => $timeout,
                        'headers' => $this->headers,
                    ]);
                    break;

                case 'get':
                default:
                $response = $this->client->request('GET', $uri, [
                        'query' => $args,
                        'timeout' => $timeout,
                        'headers' => $this->headers,
                    ]);
                    break;
            }

            return $response;
        } catch (RequestException $e) {
            return $e->getResponse();
        }
    }

    public function get($uri = '', $args = [], $timeout = 10)
    {
        return $this->call('get', $uri, $args, $timeout);
    }

    public function post($uri = '', $args = [], $timeout = 10)
    {
        return $this->call('post', $uri, $args, $timeout);
    }

    public function patch($uri = '', $args = [], $timeout = 10)
    {
        return $this->call('patch', $uri, $args, $timeout);
    }

    public function put($uri = '', $args = [], $timeout = 10)
    {
        return $this->call('put', $uri, $args, $timeout);
    }

    public function delete($uri = '', $args = [], $timeout = 10)
    {
        return $this->call('delete', $uri, $args, $timeout);
    }

    public function validateApiKey()
    {
        $response = $this->get();

        return $response && 200 === $response->getStatusCode() ? true : false;
    }

    public function getAccountDetails()
    {
        $response = $this->get('');

        return $response ? json_decode($response->getBody()) : null;
    }

    public function isSubscribed($listId, $email)
    {
        return self::STATUS_SUBSCRIBED === $this->getSubscriberStatus($listId, $email);
    }

    public function getSubscriberStatus($listId, $email)
    {
        $endpoint = sprintf('lists/%s/members/%s', $listId, $this->getSubscriberHash($email));

        $response = $this->get($endpoint);

        if (null === $response) {
            throw new ApiException('Could not connect to API. Check your credentials.');
        }

        $body = json_decode($response->getBody());

        return $body->status;
    }

    public function subscribeToList($listId, $email, $mergeVars = [], $doubleOptin = true, $interests = [])
    {
        $status = $this->getSubscriberStatus($listId, $email);
        $endpoint = sprintf('lists/%s/members', $listId);

        if (self::STATUS_SUBSCRIBED !== $status) {
            $requestData = [
                'id' => $listId,
                'email_address' => $email,
                'status' => $doubleOptin ? self::STATUS_PENDING : self::STATUS_SUBSCRIBED,
            ];

            if (\count($mergeVars) > 0) {
                $requestData['merge_fields'] = $mergeVars;
            }

            if (\count($interests) > 0) {
                $requestData['interests'] = $interests;
            }

            $response = $this->put($endpoint.'/'.$this->getSubscriberHash($email), $requestData);

            if (null === $response) {
                throw new ApiException('Could not connect to API. Check your credentials.');
            }

            $body = json_decode($response->getBody());

            // This is quite hacky due to fucked up mailchimp API
            if (400 === $response->getStatusCode() && 'Member Exists' === $body->title) {
                return true;
            }

            return $response && 200 === $response->getStatusCode() ? true : false;
        }

        return false;
    }

    public function unsubscribeFromList($listId, $email)
    {
        $endpoint = sprintf('lists/%s/members/%s', $listId, $this->getSubscriberHash($email));

        $response = $this->patch($endpoint, [
            'status' => 'unsubscribed',
        ]);

        if (200 === $response->getStatusCode()) {
            return true;
        }

        return false;
    }

    public function removeFromList($listId, $email)
    {
        $endpoint = sprintf('lists/%s/members/%s', $listId, $this->getSubscriberHash($email));

        $response = $this->delete($endpoint);

        if (204 === $response->getStatusCode()) {
            return true;
        }

        return false;
    }

    public function getListFields($listId, $offset = 0, $limit = 10)
    {
        $endpoint = sprintf('lists/%s/merge-fields', $listId);

        $response = $this->get($endpoint, ['offset' => $offset, 'limit' => $limit]);

        if (null === $response) {
            throw new ApiException('Could not connect to API. Check your credentials.');
        }

        if (200 !== $response->getStatusCode()) {
            throw new ApiException('Could not fetch merge-fields from API.');
        }

        return json_decode($response->getBody());
    }

    public function getListGroupCategories($listId, $offset = 0, $limit = 10)
    {
        $endpoint = sprintf('lists/%s/interest-categories', $listId);

        $response = $this->get($endpoint, ['offset' => $offset, 'limit' => $limit]);

        if (null === $response) {
            throw new ApiException('Could not connect to API. Check your credentials.');
        }

        if (200 !== $response->getStatusCode()) {
            throw new ApiException('Could not fetch interest-categories from API.');
        }

        return json_decode($response->getBody());
    }

    public function getListGroup($listId, $groupId, $offset = 0, $limit = 10)
    {
        $endpoint = sprintf('lists/%s/interest-categories/%s/interests', $listId, $groupId);

        $response = $this->get($endpoint, ['offset' => $offset, 'limit' => $limit]);

        if (null === $response) {
            throw new ApiException('Could not connect to API. Check your credentials.');
        }

        if (200 !== $response->getStatusCode()) {
            throw new ApiException('Could not fetch interest group from API.');
        }

        return json_decode($response->getBody());
    }

    public function getSubscriberHash($email)
    {
        return md5(strtolower($email));
    }
}
