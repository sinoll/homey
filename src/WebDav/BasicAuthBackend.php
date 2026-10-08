<?php

declare(strict_types=1);

namespace App\WebDav;

use App\Service\AuthenticationService;
use Sabre\DAV\Auth\Backend\AbstractBasic;

final class BasicAuthBackend extends AbstractBasic
{
    private string|null $authenticatedUserId = null;

    public function __construct(private readonly AuthenticationService $authentication)
    {
        $this->setRealm('Yii Cloud WebDAV');
    }

    public function authenticatedUserId(): string|null
    {
        return $this->authenticatedUserId;
    }

    protected function validateUserPass($username, $password): bool
    {
        $this->authenticatedUserId = $this->authentication->validateCredentials($username, $password);

        return $this->authenticatedUserId !== null;
    }
}
