<?php

namespace Tests\Testing;

use PHPUnit\Framework\TestCase;
use Tests\Fixtures\PassphraseTools;


class PassphraseToolsTest extends TestCase
{
    public function testTools() : void
    {
        [$next, $ahead] = PassphraseTools::make();

        $this->assertSame( 'get_next_passphrase', $next->name() );
        $this->assertSame( 'get_passphrase_in_days', $ahead->name() );
        $this->assertSame( 'wobbly-marmalade-1987', $next( [] ) );
        $this->assertSame( 'crimson-otter-4521', $ahead( ['days' => 2] ) );
        $this->assertSame( 'crimson-otter-4521', $ahead( ['days' => '2'] ) );
        $this->assertSame( 'unknown', $ahead( ['days' => 1] ) );
        $this->assertSame( 'unknown', $ahead( [] ) );
        $this->assertSame( ['days'], $ahead->schema()->toArray()['required'] );
        $this->assertSame( 'integer', $ahead->schema()->toArray()['properties']['days']['type'] );

        foreach( PassphraseTools::make() as $index => $fresh ) {
            $original = [$next, $ahead][$index];
            $this->assertNotSame( $original, $fresh );
            $this->assertNotSame( $original->schema(), $fresh->schema() );
            $this->assertSame( $original->description(), $fresh->description() );
            $this->assertSame( $original->schema()->toArray(), $fresh->schema()->toArray() );
        }
    }
}
