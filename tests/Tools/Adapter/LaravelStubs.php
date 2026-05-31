<?php

/**
 * Stubs for the optional Laravel AI/MCP tool base types.
 *
 * The Laravel adapter validates tools via is_a() against these classes, but the
 * laravel/* packages are not installed as (dev) dependencies. These lightweight
 * stubs let the unit tests exercise the validation without pulling in Laravel.
 * When the real packages are present, class_exists()/interface_exists() detect
 * them and the stubs are skipped.
 */

namespace Laravel\Mcp\Server {
    if( !class_exists( Tool::class ) ) {
        abstract class Tool {}
    }
}

namespace Laravel\Ai\Contracts {
    if( !interface_exists( Tool::class ) ) {
        interface Tool {}
    }
}
