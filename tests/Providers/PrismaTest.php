<?php

namespace Tests\Providers;

use Aimeos\Prisma\Exceptions\NotImplementedException;
use Aimeos\Prisma\Prisma;
use PHPUnit\Framework\TestCase;


class PrismaTest extends TestCase
{
    public function testUsingRejectsNamespaceEscapeInName() : void
    {
        // a backslash in the provider name must be rejected before it reaches the class name, so it
        // cannot escape the Providers\{Type} namespace or trigger the autoloader
        $this->expectException( NotImplementedException::class );

        Prisma::text()->using( 'Sub\\Evil' );
    }


    public function testUsingRejectsInvalidType() : void
    {
        // the media type is interpolated into the class name too, so it is validated as well
        $this->expectException( NotImplementedException::class );

        Prisma::type( 'text\\Evil' )->using( 'openai' );
    }
}
