<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminKnowledgeBasePanelProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\Filament\AppPanelProvider;
use App\Providers\Filament\KnowledgeBasePanelProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\TenancyServiceProvider;

return [
    AppServiceProvider::class,
    TenancyServiceProvider::class,
    AdminPanelProvider::class,
    AdminKnowledgeBasePanelProvider::class,
    AppPanelProvider::class,
    KnowledgeBasePanelProvider::class,
    HorizonServiceProvider::class,
];
