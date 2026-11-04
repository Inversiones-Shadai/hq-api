<?php

use App\Providers\AppServiceProvider;
use App\Providers\HeadquartersServiceProvider;
use App\Providers\HttpSecurityServiceProvider;

return [
    AppServiceProvider::class,
    HeadquartersServiceProvider::class,
    HttpSecurityServiceProvider::class,
];
