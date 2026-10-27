<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\Poppy\Typo3\Http;

use Psr\Http\Client\ClientExceptionInterface;

/** A fetch that was refused or failed, as PSR-18 wants it reported. */
final class PoppyClientException extends \RuntimeException implements ClientExceptionInterface
{
    public function __construct(\Throwable $previous)
    {
        parent::__construct($previous->getMessage(), 0, $previous);
    }
}
