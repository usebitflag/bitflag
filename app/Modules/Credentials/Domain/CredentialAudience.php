<?php

declare(strict_types=1);

namespace App\Modules\Credentials\Domain;

/**
 * Who a management credential is for. Tokens made for the MCP server carry `mcp`, and its policy applies to them.
 */
enum CredentialAudience: string
{
    case Api = 'api';
    case Mcp = 'mcp';
}
