<?php

declare(strict_types=1);

namespace Yukazakiri\Lepton\Contracts;

/**
 * Agent wallet authentication.
 *
 * Circle Agent Wallets authenticate with an email one-time password. The
 * interactive flow needs a human at a terminal; the non-interactive flow is
 * split into an "init" step that returns a request ID and a "complete" step
 * that consumes the OTP, so a script or agent can drive it.
 *
 * Sessions last seven days and mainnet and testnet are authenticated
 * independently: a valid mainnet session does not authorise testnet
 * transfers.
 *
 * Implementations never store credentials themselves. Circle CLI owns the
 * session; this contract only reports and initiates it.
 */
interface AuthGateway
{
    /**
     * Current session state for every environment Circle tracks.
     *
     * @return array{mainnet: array{authenticated: bool, email: ?string, status: ?string, expires_in: ?string}, testnet: array{authenticated: bool, email: ?string, status: ?string, expires_in: ?string}, type: string}
     */
    public function authStatus(): array;

    /**
     * Begin a non-interactive login and return the request ID.
     *
     * The request ID expires after 10 minutes and is consumed on first use.
     * No session exists until completeLogin() is called with the OTP.
     */
    public function beginLogin(string $email): string;

    /**
     * Complete a login started by beginLogin().
     *
     * @param  string  $otp  Alphanumeric code from Circle's email, e.g. "B1X-123456".
     *
     * @return array{email: ?string, status: ?string}
     */
    public function completeLogin(string $requestId, string $otp): array;

    /**
     * Whether a usable session exists for the given chain.
     */
    public function isAuthenticatedFor(string $chainCode = 'ARC-TESTNET'): bool;
}
