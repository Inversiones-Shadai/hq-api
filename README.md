# Shadai Headquarters API

API independiente para las instalaciones Headquarters y la entrega de datos al POS. Usa MySQL `hq_api` y recibe únicamente peticiones internas firmadas desde apishadai. El registro con aprobación por email se solicita en apishadai; esta API no ofrece registro público.

Ver [el contrato de la API](docs/headquarters-api.md) y [las garantías de sincronización](docs/headquarters-sync.md).

```shell
php artisan migrate --no-interaction
php artisan serve --host=127.0.0.1 --port=8011 --tries=1
php artisan schedule:work
php artisan hq:status
php artisan test --compact tests/Feature/HeadquartersSystemTest.php tests/Feature/HeadquartersStockScopeTest.php tests/Feature/HeadquartersDependencyOrderTest.php tests/Feature/HeadquartersCommitOrderTest.php
```

Configurar APISHADAI_KEY_ID, APISHADAI_SIGNING_SECRET y APISHADAI_ALLOWED_IPS en `.env`. El secreto de firma es exclusivo de los servidores, independiente de APP_KEY y de cada credencial de cliente. En producción se exige HTTPS.
