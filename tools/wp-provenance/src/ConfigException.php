<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Martien de Jong
// Source-Id: stm.tools.provenance.config-exception

declare(strict_types=1);

namespace WpProvenance;

/** A per-plugin config that cannot be used. Carries every problem found, not only the first. */
final class ConfigException extends \RuntimeException
{
    /** @var list<string> */
    public $problems;

    /** @param list<string> $problems */
    public function __construct(array $problems)
    {
        $this->problems = $problems;
        parent::__construct("The provenance config is not usable:\n- " . implode("\n- ", $problems));
    }
}
