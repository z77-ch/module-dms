<?php

namespace Z77\Module\Dms\Services;

use Z77\Shared\Auth\AccountRemovedListener;
use Z77\Shared\Auth\AuthUser;

/**
 * An account is gone, its ZIP bundles go with it (DMS-ZIP-001, owner 2026-11-02): a manifest holds
 * file names and possibly an address. By the removed identity's realm and id, so a member and a
 * backend user with the same id never meet, and never by the session of whoever removed it.
 */
final class ZipBundleAccountListener implements AccountRemovedListener
{
    public function accountRemoved(AuthUser $account): void
    {
        ZipBundleService::create()->deleteForOwner(ZipBundleService::ownerKey($account));
    }
}
