<?php

namespace Tests\Providers;

use Aimeos\Prisma\Providers\Base;
use Aimeos\Prisma\Tools;
use Aimeos\Prisma\Schema\Schema;
use Aimeos\Prisma\Exceptions\NotImplementedException;
use PHPUnit\Framework\TestCase;


class BaseTest extends TestCase
{
    public function testCallThrowsNotImplementedException() : void
    {
        $provider = $this->provider();
        $this->expectException( NotImplementedException::class );
        $provider->nonExistentMethod();
    }


    public function testEnsureThrowsForUnimplemented() : void
    {
        $provider = $this->provider();
        $this->expectException( NotImplementedException::class );
        $provider->ensure( 'nonExistent' );
    }


    public function testHasReturnsFalseForUnimplemented() : void
    {
        $provider = $this->provider();
        $this->assertFalse( $provider->has( 'nonExistent' ) );
    }


    public function testModel() : void
    {
        $provider = $this->provider();
        $result = $provider->model( 'gpt-4' );

        $this->assertSame( $provider, $result );
        $this->assertEquals( 'gpt-4', $provider->getModelName() );
    }


    public function testModelNameDefault() : void
    {
        $provider = $this->provider();
        $this->assertEquals( 'default-model', $provider->getModelName( 'default-model' ) );
    }


    public function testModelNameOverridesDefault() : void
    {
        $provider = $this->provider();
        $provider->model( 'custom-model' );
        $this->assertEquals( 'custom-model', $provider->getModelName( 'default-model' ) );
    }


    public function testModelNull() : void
    {
        $provider = $this->provider();
        $provider->model( null );

        $this->assertNull( $provider->getModelName() );
    }


    public function testWithClientHandler() : void
    {
        $provider = $this->provider();
        $stack = \GuzzleHttp\HandlerStack::create();
        $result = $provider->withClientHandler( $stack );
        $this->assertSame( $provider, $result );
    }


    public function testWithClientOptions() : void
    {
        $provider = $this->provider();
        $result = $provider->withClientOptions( ['timeout' => 30] );
        $this->assertSame( $provider, $result );
    }


    public function testWithClientRetry() : void
    {
        $provider = $this->provider();
        $result = $provider->withClientRetry( 3, 200 );
        $this->assertSame( $provider, $result );
    }


    public function testWithClientRetryClosure() : void
    {
        $provider = $this->provider();
        $result = $provider->withClientRetry( 3, fn( $attempt, $response ) => $attempt * 100 );
        $this->assertSame( $provider, $result );
    }


    public function testWithClientRetryWhen() : void
    {
        $provider = $this->provider();
        $result = $provider->withClientRetry( 3, 100, fn( $response, $attempt ) => true );
        $this->assertSame( $provider, $result );
    }


    public function testWithSystemPrompt() : void
    {
        $provider = $this->provider();
        $result = $provider->withSystemPrompt( 'You are helpful' );

        $this->assertSame( $provider, $result );
        $this->assertEquals( 'You are helpful', $provider->getSystemPrompt() );
    }


    public function testWithSystemPromptNull() : void
    {
        $provider = $this->provider();
        $provider->withSystemPrompt( 'test' );
        $provider->withSystemPrompt( null );

        $this->assertNull( $provider->getSystemPrompt() );
    }


    public function testWithTools() : void
    {
        $provider = $this->provider();
        $tool1 = Tools::make( 'tool1', 'First tool', Schema::fromArray( 'tool1', ['type' => 'object'] ), fn() => '' );
        $tool2 = Tools::make( 'tool2', 'Second tool', Schema::fromArray( 'tool2', ['type' => 'object'] ), fn() => '' );

        $result = $provider->withTools( [$tool1, $tool2] );

        $this->assertSame( $provider, $result );
        $tools = $provider->getTools();
        $this->assertCount( 2, $tools );
        $this->assertSame( $tool1, $tools[0] );
        $this->assertSame( $tool2, $tools[1] );
    }


    public function testWithToolsEmpty() : void
    {
        $provider = $this->provider();
        $provider->withTools( [] );

        $this->assertEmpty( $provider->getTools() );
    }


    public function testWithToolsReplacesExisting() : void
    {
        $provider = $this->provider();
        $tool1 = Tools::make( 'tool1', 'First', Schema::fromArray( 'tool1', ['type' => 'object'] ), fn() => '' );
        $tool2 = Tools::make( 'tool2', 'Second', Schema::fromArray( 'tool2', ['type' => 'object'] ), fn() => '' );

        $provider->withTools( [$tool1, $tool2] );
        $provider->withTools( [$tool1] );

        $this->assertCount( 1, $provider->getTools() );
    }


    public function testHasProviderTool() : void
    {
        $provider = $this->provider();
        $tool = Tools::make( 'web_search', 'Search', Schema::fromArray( 'web_search', ['type' => 'object'] ), fn() => '' );

        $this->assertFalse( $provider->hasProviderToolNamed( 'web_search' ) );

        $provider->withTools( [$tool] );
        $this->assertFalse( $provider->hasProviderToolNamed( 'web_search' ) );

        $provider->withTools( [Tools::provider( 'web_search' )] );
        $this->assertTrue( $provider->hasProviderToolNamed( 'web_search' ) );
        $this->assertFalse( $provider->hasProviderToolNamed( 'code_execution' ) );
    }


    private function provider() : Base
    {
        return new class( [] ) extends Base {
            public function __construct( array $config ) {}

            public function getSystemPrompt() : ?string { return $this->systemPrompt(); }
            public function getTools() : array { return $this->tools(); }
            public function getModelName( ?string $default = null ) : ?string { return $this->modelName( $default ); }
            public function hasProviderToolNamed( string $name ) : bool { return $this->hasProviderTool( $name ); }
        };
    }
}
