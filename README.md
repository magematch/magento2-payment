# Rameera_Payment

Magento 2 module providing payment processing, retry logic, and GraphQL integration for payment methods including Adyen.

## Features

- Payment webhook handling and API integration
- Payment retry logic with configurable timeout and retry attempts
- Order cancellation cron jobs for pending orders
- GraphQL resolvers for cart reset, order payment details, and payment retry
- Adyen payment methods mapping
- Payment transaction processing plugins
- Request and state data plugins for payment flow

## Requirements

| Dependency | Version |
|---|---|
| PHP | `^8.1 \|\| ^8.2 \|\| ^8.3 \|\| ^8.4` |
| `magento/framework` | `^103.0` |
| `magento/module-payment` | `^103.0` |
| `magento/module-payment-graph-ql` | `^100.0` |
| `adyen/module-payment` | `*` |

## Installation

```bash
composer require arjundhi/magento2-payment
bin/magento module:enable Rameera_Payment
bin/magento setup:upgrade
bin/magento cache:flush
```

## License

MIT — see [LICENSE](LICENSE).
