<?php

declare(strict_types=1);
/**
 * Copyright 2026 Kai Randles
 * SPDX-License-Identifier: Apache-2.0.
 */

namespace Box\Mod\Enhance\Api;

use FOSSBilling\InformationException;

/**
 * Hosting usage for the logged in client.
 */
class Client extends \FOSSBilling\Api\AbstractApi
{
    /**
     * Live usage for one hosting order.
     *
     * Returns an `error` key instead of throwing: the hosting manage template calls this from Twig,
     * and a panel that is briefly unreachable must not take the password and domain forms down with it.
     *
     * @return array usage as reported by the server manager, or `['error' => message]`
     */
    public function usage($data): array
    {
        if (empty($data['order_id'])) {
            return ['error' => 'Order ID is required'];
        }

        try {
            return $this->getService()->usageForOrder((int) $data['order_id'], $this->clientId());
        } catch (\Throwable $e) {
            $this->getDi()['logger']->warning('Enhance usage for order {order_id} is unavailable: {message}', ['order_id' => $data['order_id'], 'message' => $e->getMessage()]);

            return ['error' => $e instanceof InformationException ? $e->getMessage() : 'Usage is unavailable right now'];
        }
    }

    /**
     * The client's hosting orders, each with its usage or the reason it is unavailable.
     */
    public function accounts($data): array
    {
        return $this->getService()->accountsForClient($this->clientId());
    }

    private function clientId(): int
    {
        return $this->getService()->clientId($this->getIdentity());
    }
}
