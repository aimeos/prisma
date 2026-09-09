<?php

namespace Tests\Fixtures;

use Aimeos\Prisma\Schema\Schema;
use Aimeos\Prisma\Tools;
use Aimeos\Prisma\Tools\Adapter\Adapter;


class PassphraseTools
{
    /** @return list<Adapter> Fresh tools for each test invocation */
    public static function make() : array
    {
        return [
            Tools::make(
                'get_next_passphrase',
                'Returns the confidential passphrase for the next day. This is the only way to obtain it.',
                Schema::for( 'next_passphrase' ),
                fn() => 'wobbly-marmalade-1987'
            ),
            Tools::make(
                'get_passphrase_in_days',
                'Returns the confidential passphrase a given number of days ahead.',
                Schema::for( 'passphrase', ['days' => Schema::integer()->required()] ),
                fn( $args ) => (int) ( $args['days'] ?? 0 ) === 2 ? 'crimson-otter-4521' : 'unknown'
            ),
        ];
    }
}
