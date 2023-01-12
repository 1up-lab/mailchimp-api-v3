<?php

declare(strict_types=1);

namespace Oneup\MailChimp;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Oneup\MailChimp\Exception\ApiException;
use Psr\Http\Message\ResponseInterface;

class Client
{
    public const STATUS_ARCHIVED = 'archived';
    public const STATUS_PENDING = 'pending';
    public const STATUS_SUBSCRIBED = 'subscribed';

    protected GuzzleClient $client;
    protected string $apiKey;
    protected string $apiEndpoint = 'https://%dc%.api.mailchimp.com/3.0/';
    protected array $headers = [];
    protected ?object $lastError = null;

    public function __construct($apiKey)
    {
        $this->apiKey = $apiKey;

        [, $dc] = explode('-', $this->apiKey);
        $this->apiEndpoint = preg_replace('/%dc%/', $dc, $this->apiEndpoint);

        $this->headers = [
            'Accept' => 'application/vnd.api+json',
            'Content-Type' => 'application/vnd.api+json',
            'Authorization' => 'apikey ' . $this->apiKey,
            'User-Agent' => '1up/mailchimp-api-v3 (https://github.com/1up-lab/mailchimp-api-v3)',
        ];

        $this->client = new GuzzleClient([
            'base_uri' => $this->apiEndpoint,
        ]);
    }

    public function call($type = 'get', $uri = '', $args = [], $timeout = 10): ?ResponseInterface
    {
        $args['apikey'] = $this->apiKey;

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
                        'body' => json_encode($args, \JSON_THROW_ON_ERROR),
                        'timeout' => $timeout,
                        'headers' => $this->headers,
                    ]);
                    break;

                case 'put':
                    $response = $this->client->request('PUT', $uri, [
                        'body' => json_encode($args, \JSON_THROW_ON_ERROR),
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

            $this->lastError = null;

            return $response;
        } catch (RequestException $e) {
            $response = $e->getResponse();

            if (null === $response) {
                throw $e;
            }

            try {
                $this->lastError = json_decode((string) $response->getBody(), false, 512, \JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                $this->lastError = $e;
            }

            return $response;
        } catch (\JsonException|GuzzleException $e) {
            $this->lastError = $e;
        }

        return null;
    }

    public function get($uri = '', $args = [], $timeout = 10): ?ResponseInterface
    {
        return $this->call('get', $uri, $args, $timeout);
    }

    public function post($uri = '', $args = [], $timeout = 10): ?ResponseInterface
    {
        return $this->call('post', $uri, $args, $timeout);
    }

    public function patch($uri = '', $args = [], $timeout = 10): ?ResponseInterface
    {
        return $this->call('patch', $uri, $args, $timeout);
    }

    public function put($uri = '', $args = [], $timeout = 10): ?ResponseInterface
    {
        return $this->call('put', $uri, $args, $timeout);
    }

    public function delete($uri = '', $args = [], $timeout = 10): ?ResponseInterface
    {
        return $this->call('delete', $uri, $args, $timeout);
    }

    public function validateApiKey(): bool
    {
        $response = $this->get();

        return $response && 200 === $response->getStatusCode();
    }

    /**
     * @throws \JsonException
     */
    public function getAccountDetails(): ?object
    {
        $response = $this->get('');

        return $response ? json_decode((string) $response->getBody(), false, 512, \JSON_THROW_ON_ERROR) : null;
    }

    public function isSubscribed($listId, $email): bool
    {
        return self::STATUS_SUBSCRIBED === $this->getSubscriberStatus($listId, $email);
    }

    /**
     * @throws ApiException
     * @throws \JsonException
     */
    public function getSubscriberStatus(string $listId, string $email): string
    {
        $endpoint = sprintf('lists/%s/members/%s', $listId, $this->getSubscriberHash($email));

        $response = $this->get($endpoint);

        if (null === $response) {
            throw new ApiException('Could not connect to API. Check your credentials.');
        }

        $body = json_decode((string) $response->getBody(), false, 512, \JSON_THROW_ON_ERROR);

        return (string) $body->status;
    }

    /**
     * @throws ApiException
     * @throws \JsonException
     */
    public function subscribeToList(string $listId, string $email, array $mergeVars = [], bool $doubleOptIn = true, array $interests = []): bool
    {
        $endpoint = sprintf('lists/%s/members', $listId);

        $status = $this->getSubscriberStatus($listId, $email);

        if (self::STATUS_SUBSCRIBED !== $status) {
            $requestData = [
                'id' => $listId,
                'email_address' => $email,
                'status' => $doubleOptIn ? self::STATUS_PENDING : self::STATUS_SUBSCRIBED,
            ];

            if (\count($mergeVars) > 0) {
                $requestData['merge_fields'] = $mergeVars;
            }

            if (\count($interests) > 0) {
                $requestData['interests'] = $interests;
            }

            if (self::STATUS_ARCHIVED === $status) {
                $response = $this->patch($endpoint . '/' . $this->getSubscriberHash($email), $requestData);
            } else {
                $response = $this->put($endpoint . '/' . $this->getSubscriberHash($email), $requestData);
            }

            if (null === $response) {
                throw new ApiException('Could not connect to API. Check your credentials.');
            }

            $body = json_decode((string) $response->getBody(), false, 512, \JSON_THROW_ON_ERROR);

            // This is quite hacky due to weird mailchimp API
            if (400 === $response->getStatusCode() && 'Member Exists' === $body->title) {
                return true;
            }

            return 200 === $response->getStatusCode();
        }

        return false;
    }

    /**
     * @throws ApiException
     */
    public function unsubscribeFromList(string $listId, string $email): bool
    {
        $endpoint = sprintf('lists/%s/members/%s', $listId, $this->getSubscriberHash($email));

        $response = $this->patch($endpoint, [
            'status' => 'unsubscribed',
        ]);

        if (null === $response) {
            throw new ApiException('Could not connect to API. Check your credentials.');
        }

        return 200 === $response->getStatusCode();
    }

    /**
     * @throws ApiException
     */
    public function removeFromList(string $listId, string $email): bool
    {
        $endpoint = sprintf('lists/%s/members/%s', $listId, $this->getSubscriberHash($email));

        $response = $this->delete($endpoint);

        if (null === $response) {
            throw new ApiException('Could not connect to API. Check your credentials.');
        }

        return 204 === $response->getStatusCode();
    }

    /**
     * @throws ApiException
     * @throws \JsonException
     */
    public function getListFields(string $listId, int $offset = 0, int $limit = 10): object
    {
        $endpoint = sprintf('lists/%s/merge-fields', $listId);

        $response = $this->get($endpoint, ['offset' => $offset, 'limit' => $limit]);

        if (null === $response) {
            throw new ApiException('Could not connect to API. Check your credentials.');
        }

        if (200 !== $response->getStatusCode()) {
            throw new ApiException('Could not fetch merge-fields from API.');
        }

        return json_decode((string) $response->getBody(), false, 512, \JSON_THROW_ON_ERROR);
    }

    /**
     * @throws ApiException
     * @throws \JsonException
     */
    public function getListGroupCategories(string $listId, int $offset = 0, int $limit = 10): object
    {
        $endpoint = sprintf('lists/%s/interest-categories', $listId);

        $response = $this->get($endpoint, ['offset' => $offset, 'limit' => $limit]);

        if (null === $response) {
            throw new ApiException('Could not connect to API. Check your credentials.');
        }

        if (200 !== $response->getStatusCode()) {
            throw new ApiException('Could not fetch interest-categories from API.');
        }

        return json_decode((string) $response->getBody(), false, 512, \JSON_THROW_ON_ERROR);
    }

    /**
     * @throws ApiException
     * @throws \JsonException
     */
    public function getListGroup(string $listId, string $groupId, int $offset = 0, int $limit = 10): object
    {
        $endpoint = sprintf('lists/%s/interest-categories/%s/interests', $listId, $groupId);

        $response = $this->get($endpoint, ['offset' => $offset, 'limit' => $limit]);

        if (null === $response) {
            throw new ApiException('Could not connect to API. Check your credentials.');
        }

        if (200 !== $response->getStatusCode()) {
            throw new ApiException('Could not fetch interest group from API.');
        }

        return json_decode((string) $response->getBody(), false, 512, \JSON_THROW_ON_ERROR);
    }

    /**
     * @throws ApiException
     * @throws \JsonException
     */
    public function getMemberTags(string $listId, string $email, array $fields = [], array $excludeFields = [], int $count = 10, int $offset = 0): object
    {
        $endpoint = sprintf('lists/%s/members/%s/tags', $listId, $this->getSubscriberHash($email));

        $response = $this->get($endpoint, [
            'fields' => implode(',', $fields),
            'exclude_fields' => implode(',', $excludeFields),
            'count' => $count,
            'offset' => $offset,
        ]);

        if (null === $response) {
            throw new ApiException('Could not connect to API. Check your credentials.');
        }

        if (200 !== $response->getStatusCode()) {
            throw new ApiException('Could not fetch member tags from API.');
        }

        return json_decode((string) $response->getBody(), false, 512, \JSON_THROW_ON_ERROR);
    }

    /**
     * @throws ApiException
     */
    public function addMemberTags(string $listId, string $email, array $tags = [], bool $isSyncing = false): bool
    {
        $activeTags = [];

        foreach ($tags as $tag) {
            $activeTags[] = [
                'name' => $tag,
                'status' => 'active',
            ];
        }

        return $this->addOrRemoveMemberTags($listId, $email, $activeTags, $isSyncing);
    }

    /**
     * @throws ApiException
     */
    public function removeMemberTags(string $listId, string $email, array $tags = [], bool $isSyncing = false): bool
    {
        $inactiveTags = [];

        foreach ($tags as $tag) {
            $inactiveTags[] = [
                'name' => $tag,
                'status' => 'inactive',
            ];
        }

        return $this->addOrRemoveMemberTags($listId, $email, $inactiveTags, $isSyncing);
    }

    /**
     * @throws ApiException
     */
    public function addOrRemoveMemberTags(string $listId, string $email, array $tags = [], bool $isSyncing = false): bool
    {
        $endpoint = sprintf('lists/%s/members/%s/tags', $listId, $this->getSubscriberHash($email));

        $response = $this->post($endpoint, [
            'tags' => $tags,
            'is_syncing' => $isSyncing,
        ]);

        if (null === $response) {
            throw new ApiException('Could not connect to API. Check your credentials.');
        }

        return 204 === $response->getStatusCode();
    }

    public function getSubscriberHash($email): string
    {
        return md5(strtolower($email));
    }

    public function getLastError(): ?object
    {
        return $this->lastError;
    }
}
