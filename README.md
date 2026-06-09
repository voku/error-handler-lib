# ErrorHandlerLib

A small, portable PHP error-handler library with application integration hooks for logging, rendering, filtering, and object descriptions.

The package is designed to be installed through Composer and then adapted by the host application through `ErrorHandlerIntegrationInterface`.

## Installation

```bash
composer require voku/error-handler-lib
```

## Usage

```php
<?php

declare(strict_types=1);

use voku\ErrorHandlerLib\ErrorHandlerLib;

(new ErrorHandlerLib())->register();

ErrorHandlerLib::reportWarning('Deprecated path used.');
ErrorHandlerLib::reportError('Unexpected state detected.');
```

For application-specific behavior, provide an integration:

```php
<?php

declare(strict_types=1);

use voku\ErrorHandlerLib\ErrorHandlerLib;
use voku\ErrorHandlerLib\ErrorHandlerIntegrationInterface;

final class ApplicationErrorIntegration implements ErrorHandlerIntegrationInterface
{
    // Implement logging, sanitizing, rendering, and environment decisions here.
}

ErrorHandlerLib::useIntegration(new ApplicationErrorIntegration());
(new ErrorHandlerLib())->register();
```

## Development

```bash
composer install
composer test
composer phpstan
composer ci
```
