<?php

declare(strict_types=1);

namespace Yukazakiri\Lepton\Contracts;

interface WalletManagementGateway
{
    /** @param array<string, mixed> $options @return list<array<string, mixed>> */
    public function wallets(array $options = []): array;

    /** @param array<string, mixed> $options @return array<string, mixed> */
    public function createWallet(array $options = []): array;

    /** @param array<string, mixed> $options @return array<string, mixed> */
    public function fundWallet(string $address, array $options = []): array;
}
