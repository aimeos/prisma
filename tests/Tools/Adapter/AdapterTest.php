<?php

namespace Tests\Tools\Adapter;

use Aimeos\Prisma\Schema\Schema;
use Aimeos\Prisma\Tools;
use Aimeos\Prisma\Tools\Adapter\Provider;
use PHPUnit\Framework\TestCase;


class AdapterTest extends TestCase
{
    public function testMake() : void
    {
        $tool = Tools::make( 'my-tool', 'My tool desc', Schema::fromArray( 'my-tool', [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'Search query'],
                'limit' => ['type' => 'integer'],
            ],
            'required' => ['query'],
        ] ), fn( $args ) => 'result' );

        $this->assertEquals( 'my-tool', $tool->name() );
        $this->assertEquals( 'My tool desc', $tool->description() );

        $arr = $tool->schema()->toArray();
        $this->assertEquals( 'object', $arr['type'] );
        $this->assertArrayHasKey( 'query', $arr['properties'] );
        $this->assertContains( 'query', $arr['required'] );
    }


    public function testMakeInvoke() : void
    {
        $tool = Tools::make( 'test', 'desc', Schema::fromArray( 'test', ['type' => 'object'] ), fn( $args ) => $args['x'] );

        $output = $tool( ['x' => 'hello'] );
        $this->assertEquals( 'hello', $output );
    }


    public function testMakeInvokeString() : void
    {
        $tool = Tools::make( 'test', 'desc', Schema::fromArray( 'test', ['type' => 'object'] ), fn( $args ) => 'plain text' );

        $output = $tool( [] );
        $this->assertEquals( 'plain text', $output );
    }


    public function testMax() : void
    {
        $tool = Tools::make( 'test', 'desc', Schema::fromArray( 'test', ['type' => 'object'] ), fn() => '' )->max( 3 );

        $this->assertTrue( $tool->can() );
        $tool( [] );
        $tool( [] );
        $tool( [] );
        $this->assertFalse( $tool->can() );
    }


    public function testMaxDefault() : void
    {
        $tool = Tools::make( 'test', 'desc', Schema::fromArray( 'test', ['type' => 'object'] ), fn() => '' );

        $tool( [] );
        $tool( [] );
        $this->assertTrue( $tool->can() );
    }


    public function testFailed() : void
    {
        $tool = Tools::make( 'test', 'desc', Schema::fromArray( 'test', ['type' => 'object'] ), fn() => throw new \RuntimeException( 'boom' ) );

        $output = $tool( [] );

        $this->assertEquals( 'Error: boom', $output );
    }


    public function testFailedCustomHandler() : void
    {
        $tool = Tools::make( 'test', 'desc', Schema::fromArray( 'test', ['type' => 'object'] ), fn() => throw new \RuntimeException( 'boom' ) )
            ->failed( fn( \Throwable $e, array $args ) => 'Custom: ' . $e->getMessage() );

        $output = $tool( [] );

        $this->assertEquals( 'Custom: boom', $output );
    }


    public function testFailedChaining() : void
    {
        $tool = Tools::make( 'test', 'desc', Schema::fromArray( 'test', ['type' => 'object'] ), fn() => 'ok' );
        $returned = $tool->failed( fn() => 'err' );

        $this->assertSame( $tool, $returned );
    }


    public function testConcurrentDefault() : void
    {
        $tool = Tools::make( 'test', 'desc', Schema::fromArray( 'test', ['type' => 'object'] ), fn() => '' );

        $this->assertFalse( $tool->isConcurrent() );
    }


    public function testConcurrentFlag() : void
    {
        $tool = Tools::make( 'test', 'desc', Schema::fromArray( 'test', ['type' => 'object'] ), fn() => '' )->concurrent();

        $this->assertTrue( $tool->isConcurrent() );
    }


    public function testConcurrentFalse() : void
    {
        $tool = Tools::make( 'test', 'desc', Schema::fromArray( 'test', ['type' => 'object'] ), fn() => '' )
            ->concurrent()
            ->concurrent( false );

        $this->assertFalse( $tool->isConcurrent() );
    }


    public function testProvider() : void
    {
        $tool = Tools::provider( 'web_search' );

        $this->assertInstanceOf( Provider::class, $tool );
        $this->assertEquals( 'web_search', $tool->name() );
        $this->assertEquals( [], $tool->options() );
    }


    public function testProviderWithOptions() : void
    {
        $tool = Tools::provider( 'web_search' )->with( ['search_context_size' => 'medium'] );

        $this->assertInstanceOf( Provider::class, $tool );
        $this->assertEquals( 'web_search', $tool->name() );
        $this->assertEquals( ['search_context_size' => 'medium'], $tool->options() );
    }


    public function testLaravel() : void
    {
        $laravelTool = new class {
            public function name() : string { return 'laravel-tool'; }
            public function description() : string { return 'A Laravel tool'; }
            public function toArray() : array {
                return [
                    'type' => 'object',
                    'properties' => ['input' => ['type' => 'string']],
                ];
            }
            public function __invoke( array $args ) : string { return 'invoked'; }
        };

        $tool = Tools::laravel( $laravelTool );
        $this->assertEquals( 'laravel-tool', $tool->name() );
        $this->assertEquals( 'A Laravel tool', $tool->description() );
        $this->assertEquals( 'invoked', $tool( [] ) );
    }


    public function testLaravelInvalid() : void
    {
        $this->expectException( \InvalidArgumentException::class );
        Tools::laravel( new \stdClass() );
    }


    public function testLaravelWithHandle() : void
    {
        $handleTool = new class {
            public function name() : string { return 'handle-tool'; }
            public function description() : string { return 'A tool with handle method'; }
            public function toArray() : array {
                return [
                    'type' => 'object',
                    'properties' => ['text' => ['type' => 'string']],
                ];
            }
            public function handle( array $args ) : string { return 'handled'; }
        };

        $tool = Tools::laravel( $handleTool );
        $this->assertEquals( 'handle-tool', $tool->name() );
        $this->assertEquals( 'A tool with handle method', $tool->description() );
        $this->assertEquals( 'handled', $tool( [] ) );
    }


    public function testSymfony() : void
    {
        $tool = Tools::symfony( SymfonyToolFixture::class );

        $this->assertEquals( 'symfony-tool', $tool->name() );
        $this->assertEquals( 'A Symfony tool', $tool->description() );

        $arr = $tool->schema()->toArray();
        $this->assertEquals( 'object', $arr['type'] );
        $this->assertArrayHasKey( 'query', $arr['properties'] );
        $this->assertEquals( 'string', $arr['properties']['query']['type'] );
        $this->assertContains( 'query', $arr['required'] );
    }


    public function testSymfonyInvoke() : void
    {
        $tool = Tools::symfony( new SymfonyToolFixture() );

        $this->assertEquals( 'search: hello, limit: 5', $tool( ['query' => 'hello', 'limit' => 5] ) );
    }


    public function testSymfonyNamed() : void
    {
        $tool = Tools::symfony( SymfonyMultiToolFixture::class, 'fetch' );

        $this->assertEquals( 'fetch', $tool->name() );
        $this->assertEquals( 'Fetch item', $tool->description() );
        $this->assertEquals( 'fetched: 42', $tool( ['id' => 42] ) );
    }


    public function testSymfonyNamedNotFound() : void
    {
        $this->expectException( \InvalidArgumentException::class );
        Tools::symfony( SymfonyToolFixture::class, 'nonexistent' );
    }


    public function testSymfonyNoAttribute() : void
    {
        $this->expectException( \InvalidArgumentException::class );
        Tools::symfony( new \stdClass() );
    }
}


#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
class AsTool
{
    public function __construct(
        public string $name = '',
        public string $description = '',
        public string $method = '__invoke',
    ) {}
}


#[AsTool( name: 'symfony-tool', description: 'A Symfony tool' )]
class SymfonyToolFixture
{
    /**
     * @param string $query The search query
     * @param int $limit Max results
     */
    public function __invoke( string $query, int $limit = 10 ) : string
    {
        return "search: $query, limit: $limit";
    }
}


#[AsTool( name: 'search', description: 'Search items' )]
#[AsTool( name: 'fetch', description: 'Fetch item', method: 'fetch' )]
class SymfonyMultiToolFixture
{
    /**
     * @param string $query The search query
     */
    public function __invoke( string $query ) : string
    {
        return "search: $query";
    }

    /**
     * @param int $id Item ID
     */
    public function fetch( int $id ) : string
    {
        return "fetched: $id";
    }
}
