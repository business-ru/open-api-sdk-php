<?php

namespace Open\Api;

use JsonException;
use Open\Api\Adapter\IlluminateOpenApi\Log\Logger;
use Open\Api\Exception\SimpleFileCacheException;
use Psr\SimpleCache\CacheInterface;
use Psr\SimpleCache\InvalidArgumentException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Class OpenClient - SDK Open API
 * @package Open\Api
 */
final class OpenClient
{
    /**
     * Предоставляет гибкие методы для синхронного или асинхронного запроса ресурсов HTTP.
     * @var HttpClientInterface|null
     */
    private ?HttpClientInterface $client;

    /**
     * Url аккаунта Open API
     * @var string
     */
    private string $account;

    /**
     * Токен аккаунта
     * @var string
     */
    private string $token;

    /**
     * app_id интеграции
     * @var mixed $appID
     */
    private $appID;

    /**
     * Secret key интеграции
     * @var false|string $secret
     */
    private $secret;

    /**
     * Является уникальным идентификатором команды
     * @var false|string $nonce
     */
    private $nonce;

    /**
     * @var CacheInterface|null
     */
    private ?CacheInterface $cache;

    /**
     * SymfonyHttpClient constructor.
     * @param string $account - url аккаунта
     * @param string $appID - app_id интеграции
     * @param string $secret - Secret key интеграции
     * @param HttpClientInterface|null $client - Symfony http клиент
     * @param CacheInterface|null $cacheInterface - Psr cache
     * @throws ClientExceptionInterface|DecodingExceptionInterface|ServerExceptionInterface
     * @throws TransportExceptionInterface|RedirectionExceptionInterface
     * @throws SimpleFileCacheException|InvalidArgumentException
     * @throws JsonException
     */
    public function __construct(
        string $account,
        string $appID,
        string $secret,
        HttpClientInterface $client = null,
        CacheInterface $cacheInterface = null
    ) {
        $this->appID = $appID;
        $this->secret = $secret;
        $this->nonce = "nonce_" . str_replace(".", "", microtime(true));
        # Получаем ссылку от аккаунта
        $this->account = $account;
        # HttpClient - выбирает транспорт cURL если расширение PHP cURL включено,
        # и возвращается к потокам PHP в противном случае
        # Добавляем в header токен из cache
        $this->client = $client ?? HttpClient::create(
                [
                    'http_version' => '2.0',
                    'headers' => [
                        'Content-Type' => 'application/json'
                    ]
                ]
            );
        # Сохраняем токен в файловый кэш
        $this->cache = $cacheInterface ?? new SimpleFileCache();
        if ($this->cache->has('OpenApiToken ' . $this->appID)) {
            $this->token = $this->cache->get('OpenApiToken ' . $this->appID);
        } else {
            $this->token = $this->getNewToken();
            $this->cache->set('OpenApiToken ' . $this->appID, $this->token);
        }
    }

    /**
     * Метод позволяет выполнить запрос к Open API
     * @param string $method - Метод
     * @param string $model - Модель
     * @param array $params - Параметры
     * @return array - Ответ запроса Open API
     * @throws ClientExceptionInterface|DecodingExceptionInterface|ServerExceptionInterface
     * @throws TransportExceptionInterface|RedirectionExceptionInterface
     * @throws SimpleFileCacheException|InvalidArgumentException
     * @throws JsonException
     */
    public function request(string $method, string $model, array $params = []): array
    {
        $response = $this->sendRequest($method, $model, $params);
        # Получаем статус запроса
        $statusCode = $response->getStatusCode();
        switch ($statusCode) {
            case 200:
                #false - убрать throw Exception от Symfony.....
                return $response->toArray(false);
            case 400:
                return [
                    'response' => json_decode($response->getContent(false), true),
                    'code' => $statusCode
                ];
            case 401:
                # Токен просрочен
                $ffdError = $response->toArray(false);
                if (array_key_exists('result', $ffdError)) {
                    $this->log('error', $ffdError["message"], $ffdError);
                    throw new JsonException($ffdError["message"], $ffdError["result"]);
                }
                $this->token = $this->getNewToken();
                $this->cache->set('OpenApiToken ' . $this->appID, $this->token);
                return $this->sendRequest($method, $model, $params)->toArray(false);
            case 500:
                $this->log('critical', "SDK. 500 Internal Server Error", [$response->getContent(false)]);
                throw new JsonException("SDK. 500 Internal Server Error", 500);
            default:
                $this->log('error', "SDK. Ошибка Open: ", [$response->getContent(false)]);
                throw new JsonException("SDK. Ошибка Open: " . $response->getContent(false), $response->getStatusCode());
        }
    }

    /**
     * Отправить HTTP запрос - клиентом
     * @param string $method - Метод
     * @param string $model - Модель
     * @param array $params - Параметры
     * @return ResponseInterface
     * @throws TransportExceptionInterface
     */
    private function sendRequest(string $method, string $model, array $params = []): ResponseInterface
    {
        #Создаем ссылку
        $url = $this->account . $model;
        #Отправляем request запрос
        return $this->client->request(
            strtoupper($method),
            $url,
            [
                'headers' => [
                    'sign' => $this->getSign($params)
                ],
                'body' => json_encode($params)
            ]
        );
    }

    /**
     * Метод выполняет запрос на получение информации о состоянии системы.
     * @return array - Возвращаем ответ о состоянии системы
     * @throws ClientExceptionInterface|DecodingExceptionInterface|ServerExceptionInterface
     * @throws TransportExceptionInterface|RedirectionExceptionInterface
     * @throws SimpleFileCacheException|InvalidArgumentException
     * @throws JsonException
     */
    public function getStateSystem(): array
    {
        return $this->request(
            "GET",
            "StateSystem",
            [
                "app_id" => $this->appID,
                "nonce" => $this->nonce,
                "token" => $this->token,
            ]
        );
    }

    /**
     * Метод отправляет запрос на открытие смены на ККТ.
     * @param string $commandName - Кастомное наименование для поля Command
     * @return array - Возвращает ответ открытия смены на ККТ
     * @throws ClientExceptionInterface|DecodingExceptionInterface|ServerExceptionInterface
     * @throws TransportExceptionInterface|RedirectionExceptionInterface
     * @throws SimpleFileCacheException|InvalidArgumentException
     * @throws JsonException
     */
    public function openShift(string $commandName = "name"): array
    {
        return $this->request(
            "POST",
            "Command",
            [
                "app_id" => $this->appID,
                "command" => [
                    "report_type" => "false",
                    "author" => $commandName
                ],
                "nonce" => $this->nonce,
                "token" => $this->token,
                "type" => "openShift"
            ]

        );
    }

    /**
     * Метод отправляет запрос на закрытие смены на ККТ.
     * @param string $commandName - Кастомное наименование для поля command
     * @return array - Возвращает ответ закрытия смены на ККТ
     * @throws ClientExceptionInterface|DecodingExceptionInterface|ServerExceptionInterface
     * @throws TransportExceptionInterface|RedirectionExceptionInterface
     * @throws SimpleFileCacheException|InvalidArgumentException
     * @throws JsonException
     */
    public function closeShift(string $commandName = "name"): array
    {
        return $this->request(
            "POST",
            "Command",
            [
                "app_id" => $this->appID,
                "command" => [
                    "report_type" => "false",
                    "author" => $commandName
                ],
                "nonce" => $this->nonce,
                "token" => $this->token,
                "type" => "closeShift"
            ]
        );
    }

    /**
     * Метод выполняет запрос на печать чека прихода на ККТ.
     * @param array $command - Массив параметров чека.
     * @return array - Возвращает command_id
     * @throws ClientExceptionInterface|DecodingExceptionInterface|ServerExceptionInterface
     * @throws TransportExceptionInterface|RedirectionExceptionInterface
     * @throws SimpleFileCacheException|InvalidArgumentException
     * @throws JsonException
     */
    public function printCheck(array $command): array
    {
        return $this->request(
            "POST",
            "Command",
            [
                "app_id" => $this->appID,
                "command" => $command,
                "nonce" => $this->nonce,
                "token" => $this->token,
                "type" => "printCheck"
            ]

        );
    }

    /**
     * Метод выполняет запрос на печать чека возврата прихода на ККТ.
     * @param array $command - Массив параметров чека.
     * @return array - Возвращает command_id
     * @throws ClientExceptionInterface|DecodingExceptionInterface|ServerExceptionInterface
     * @throws TransportExceptionInterface|RedirectionExceptionInterface
     * @throws SimpleFileCacheException|InvalidArgumentException
     * @throws JsonException
     */
    public function printPurchaseReturn(array $command): array
    {
        return $this->request(
            "POST",
            "Command",
            [
                "app_id" => $this->appID,
                "command" => $command,
                "nonce" => $this->nonce,
                "token" => $this->token,
                "type" => "printPurchaseReturn"
            ]
        );
    }

    /**
     * Вернёт информацию о команде ФР
     * @param string $commandID - command_id чека.
     * @return array - Возвращает данные по command_id
     * @throws ClientExceptionInterface|DecodingExceptionInterface|ServerExceptionInterface
     * @throws TransportExceptionInterface|RedirectionExceptionInterface
     * @throws SimpleFileCacheException|InvalidArgumentException
     * @throws JsonException
     */
    public function dataCommandID(string $commandID): array
    {
        return $this->request(
            "GET",
            "Command/".$commandID,
            [
                "nonce" => "nonce_" . str_replace(".", "", microtime(true)),
                "token" => $this->token,
                "app_id" => $this->appID
            ]
        );
    }

    /**
     * Получаем токен
     * @return string - Возвращаем токен
     * @throws ClientExceptionInterface|DecodingExceptionInterface|ServerExceptionInterface
     * @throws TransportExceptionInterface|RedirectionExceptionInterface
     * @throws SimpleFileCacheException|InvalidArgumentException
     * @throws JsonException
     */
    private function getNewToken(): string
    {
        #Получаем новый токен
        $this->token = $this->request(
            "GET",
            "Token",
            [
                "app_id" => $this->appID,
                "nonce" => $this->nonce
            ]
        )["token"];
        return $this->token;
    }

    /**
     * Метод генерирует подпись запроса и возвращает подпись.
     * @param array<array> $params - Параметры запроса для генерации на основе их подписи.
     * Не добавлять в json_encode - JSON_PRETTY_PRINT
     * @return string - Подпись запроса.
     */
    private function getSign(array $params): string
    {
        ksort($params);
        return md5(json_encode($params, JSON_UNESCAPED_UNICODE) . $this->secret);
    }

    private function log(string $level, string $message, array $context = []): void
    {
        $logger = new Logger();
        $logger->$level($message, $context);
    }
}
