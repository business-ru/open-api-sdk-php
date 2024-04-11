<?php

namespace Open\Api;

use Open\Api\Adapter\Cache\SimpleFileCache;
use Open\Api\Adapter\Log\Logger;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\HttpClient\Exception\JsonException;
use Symfony\Component\HttpClient\Exception\ServerException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Class OpenClient - SDK Open API
 * @package Open\Api
 */
final class OpenClient
{
    /**
     * SymfonyHttpClient constructor.
     * @param string $account - url аккаунта
     * @param string $appID - app_id интеграции
     * @param string $secret - Secret key интеграции
     * @param HttpClientInterface|null $client - Symfony http клиент
     * @param CacheInterface|null $cache
     */
    public function __construct(
        private readonly string $account,
        private readonly string $appID,
        private readonly string $secret,
        private ?HttpClientInterface $client = null,
        private ?CacheInterface $cache = null
    ) {

        $this->client = $client ?? HttpClient::createForBaseUri($account, [
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json'
            ],
            'http_version' => '2.0'
        ]);

        $this->cache = $cache ?? new SimpleFileCache();
    }

    /**
     * Отправить HTTP запрос - клиентом
     * @param string $method - Метод
     * @param string $model - Модель
     * @param array $options - Параметры
     * @return ResponseInterface
     */
    private function sendRequest(string $method, string $model, array $options = []): ResponseInterface
    {
        $method = strtoupper($method);
        $url = $this->account . $model;

        return $this->client->request($method, $url, $options);
    }

    private function get(string $model, array $options): array
    {
        $options = [
            'headers' => [
                'sign' => $this->getSign($options)
            ],
            'query' => $options
        ];
        $response = $this->sendRequest('GET', $model, $options);

        $this->throwStatusCode($response);

        return $response->toArray(false);
    }

    private function post(string $model, array $options): array
    {
        $response = $this->postRequest($model, $options);

        $statusCode = $response->getStatusCode();

        $this->throwStatusCode($response);

        if ($statusCode === 401) {
            $response = $this->postRequest($model, $options);
        }

        return $response->toArray(false);
    }

    private function postRequest(string $model, array $options): ResponseInterface
    {
        $params = [
            'headers' => [
                'sign' => $this->getSign($options)
            ],
            'body' => json_encode($options, JSON_UNESCAPED_UNICODE)
        ];
        return $this->sendRequest('POST', $model, $params);
    }

    private function throwStatusCode(ResponseInterface $response): void
    {
        $statusCode = $response->getStatusCode();
        switch ($statusCode) {
            case 400:
            case 422:
            case 200:
                return;
            case 401:
                $result = $response->toArray(false);
                if (array_key_exists('Result', $result) && $result['Result'] === "1001") {
                    throw new JsonException(json_encode($result, JSON_UNESCAPED_UNICODE));
                }
                $this->log('debug', 'Токен просрочен.', [
                    'response' => $result,
                    'status_code' => $statusCode,
                ]);
                return;
            case 403:
                $result = $response->toArray(false);
                if (array_key_exists('Result', $result) && $result['Result'] === "1001") {
                    throw new JsonException(json_encode($result, JSON_UNESCAPED_UNICODE));
                }
                return;
            case 500:
                $this->log('critical', "SDK. Ошибка Open Api. 500 Internal Server Error", $response->toArray(false));
                throw new ServerException($response);
            default:
                $this->log('error', "SDK. Ошибка Open Api: ", $response->toArray(false));
                throw new JsonException($response->getContent(false), $statusCode);
        }
    }

    /**
     * Метод выполняет запрос на получение информации о состоянии системы.
     * @return array - Возвращаем ответ о состоянии системы
     */
    public function getStateSystem(): array
    {
        return $this->get(
            "StateSystem",
            [
                "app_id" => $this->appID,
                "nonce" => $this->getNonce()
            ]
        );
    }

    /**
     * Метод отправляет запрос на открытие смены на ККТ.
     * @param string $commandName - Кастомное наименование для поля Command
     * @return array - Возвращает ответ открытия смены на ККТ
     */
    public function openShift(string $commandName = "name"): array
    {
        return $this->post(
            "Command",
            [
                "app_id" => $this->appID,
                "command" => [
                    "report_type" => "false",
                    "author" => $commandName
                ],
                "nonce" => $this->getNonce(),
                "type" => "openShift"
            ]

        );
    }

    /**
     * Метод отправляет запрос на закрытие смены на ККТ.
     * @param string $commandName - Кастомное наименование для поля command
     * @return array - Возвращает ответ закрытия смены на ККТ
     */
    public function closeShift(string $commandName = "name"): array
    {
        return $this->post(
            "Command",
            [
                "app_id" => $this->appID,
                "command" => [
                    "report_type" => "false",
                    "author" => $commandName
                ],
                "nonce" => $this->getNonce(),
                "type" => "closeShift"
            ]
        );
    }

    /**
     * Метод выполняет запрос на печать чека прихода на ККТ.
     * @param array $command - Массив параметров чека.
     * @return array - Возвращает command_id
     */
    public function printCheck(array $command): array
    {
        return $this->post(
            "Command",
            [
                "app_id" => $this->appID,
                "command" => $command,
                "nonce" => $this->getNonce(),
                "type" => "printCheck"
            ]

        );
    }

    /**
     * Метод выполняет запрос на печать чека возврата прихода на ККТ.
     * @param array $command - Массив параметров чека.
     * @return array - Возвращает command_id
     */
    public function printPurchaseReturn(array $command): array
    {
        return $this->post(
            "Command",
            [
                "app_id" => $this->appID,
                "command" => $command,
                "nonce" => $this->getNonce(),
                "type" => "printPurchaseReturn"
            ]
        );
    }

    /**
     * Вернёт информацию о команде ФР
     * @param string $commandID - command_id чека.
     * @return array - Возвращает данные по command_id
     */
    public function dataCommandID(string $commandID): array
    {
        return $this->get(
            "Command/" . $commandID,
            [
                "nonce" => $this->getNonce(),
                "app_id" => $this->appID
            ]
        );
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

    /**
     * Является уникальным идентификатором команды
     * @return string
     */
    private function getNonce(): string
    {
        return "nonce_" . str_replace(".", "", microtime(true));
    }

    private function log(string $level, string $message, array $context = []): void
    {
        $logger = new Logger();
        $logger->$level($message, $context);
    }
}
