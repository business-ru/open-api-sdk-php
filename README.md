# open-api-sdk-php

## О проекте

Данная библиотека предназначена для работы с Open API.

## Требования

* PHP 8.1 и выше
* PHP extension cURL

## Установка

```
composer require business-ru/open-api-sdk-php
```

Документация: https://app.swaggerhub.com/apis/Business.Ru/check.business.ru/

## Принцип работы

### Создание файла для работы с Open Api

```php
<?php
# Текущее местоположение проекта
$projectDIR = dirname(__DIR__);

# Подключение автозагрузки
require_once $projectDIR . '/vendor/autoload.php';
# Подключение библиотеки Open Api Client
require_once $projectDIR . '/vendor/business-ru/open-api-sdk-php/src/OpenClient.php';

use Open\Api\OpenClient;


# Для ФФД /v1/ - 1.05
$accountUrl = 'https://check.business.ru/open-api/v1/';

# Для ФФД /v2/ - 1.2
# $accountUrl = 'https://check.business.ru/open-api/v2/';

# Данные клиента
$appID = '';
$secretKey = '';

# Создание экземпляра класса
$openApiClient = new OpenClient($accountUrl, $appID, $secretKey);
```

### Примеры использования

#### Информация о состоянии системы

```php
<?php
$openApiClient->getStateSystem();
```

#### Открытие смены

```php
<?php
$openApiClient->openShift();
```

#### Закрытие смены

```php
<?php
$openApiClient->closeShift();
```

#### Печать чека прихода

```php
<?php
$command = [
    "author" => "Тестовый кассир",
    "smsEmail54FZ" => "test@test.ru",
    "c_num" => "1111222333",
    "payed_cashless" => 1000,
    "goods" => [
        [
            "count" => 2,
            "price" => 500,
            "sum" => 1000,
            "name" => "Товар 1",
            "nds_value" => 20,
            "nds_not_apply" => false,
            "payment_mode" => 1,
            "item_type" => 1
        ]
    ]
];
$openApiClient->printCheck($command);
```

#### Печать чека возврата прихода

```php
<?php
$command = [
    "author" => "Тестовый кассир",
    "smsEmail54FZ" => "test@test.ru",
    "c_num" => "1111222333",
    "payed_cashless" => 1000,
    "goods" => [
        [
            "count" => 2,
            "price" => 500,
            "sum" => 1000,
            "name" => "Товар 1",
            "nds_value" => 20,
            "nds_not_apply" => false,
            "payment_mode" => 1,
            "item_type" => 1
        ]
    ]
];
$openApiClient->printPurchaseReturn($command);
```

#### Вернёт информацию о команде ФР

```php
<?php
$commandID = "command_id"
$openApiClient->dataCommandID($commandID);
```
