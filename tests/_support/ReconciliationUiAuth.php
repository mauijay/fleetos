<?php

namespace Tests\Support;

use CodeIgniter\Shield\Auth;
use CodeIgniter\Shield\Entities\User;
use Config\Auth as AuthConfig;

/** Synthetic display identity only; route filter authorization is tested separately. */
final class ReconciliationUiAuth extends Auth
{
    public function __construct()
    {
        parent::__construct(new AuthConfig());
    }

    public function setAuthenticator(?string $alias = null): self
    {
        return $this;
    }

    public function loggedIn(): bool
    {
        return false;
    }

    public function user(): ?User
    {
        return null;
    }
}
