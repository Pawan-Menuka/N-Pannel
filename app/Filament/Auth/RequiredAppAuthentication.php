<?php

namespace App\Filament\Auth;

use Filament\Actions\Action;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Facades\Filament;

class RequiredAppAuthentication extends AppAuthentication
{
    /** @return array<Action> */
    public function getActions(): array
    {
        return array_values(array_filter(
            parent::getActions(),
            fn (Action $action): bool => $action->getName() !== 'disableAppAuthentication'
                || Filament::getCurrentOrDefaultPanel()?->isMultiFactorAuthenticationRequired() === false,
        ));
    }
}
