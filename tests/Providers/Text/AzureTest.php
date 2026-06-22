<?php

namespace Tests\Providers\Text;

use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Schema\Schema;
use PHPUnit\Framework\TestCase;
use Tests\MakesPrismaRequests;


class AzureTest extends TestCase
{
    use MakesPrismaRequests;


    public function testWrite() : void
    {
        $response = $this->prisma( 'text', 'azure', ['api_key' => 'test', 'resource' => 'myres'] )
            ->response( ['choices' => [['message' => ['content' => 'Hello']]], 'usage' => ['total_tokens' => 5]] )
            ->ensure( 'write' )
            ->write( 'Say hi' );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals(
                'https://myres.openai.azure.com/openai/deployments/gpt-4o/chat/completions?api-version=2024-10-21',
                (string) $request->getUri()
            );
            $this->assertEquals( 'test', $request->getHeaderLine( 'api-key' ) );

            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( 'gpt-4o', $body['model'] );
        } );

        $this->assertEquals( 'Hello', $response->text() );
    }


    public function testWriteWithDeploymentAndVersion() : void
    {
        $this->prisma( 'text', 'azure', ['api_key' => 'test', 'url' => 'https://custom.example.com', 'api_version' => '2025-01-01'] )
            ->response( ['choices' => [['message' => ['content' => 'ok']]]] )
            ->model( 'my-deployment' )
            ->ensure( 'write' )
            ->write( 'hi' );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals(
                'https://custom.example.com/openai/deployments/my-deployment/chat/completions?api-version=2025-01-01',
                (string) $request->getUri()
            );
        } );
    }


    public function testStructured() : void
    {
        $schema = Schema::for( 'person', ['name' => Schema::string()] );

        $response = $this->prisma( 'text', 'azure', ['api_key' => 'test', 'resource' => 'r'] )
            ->response( [
                'choices' => [['finish_reason' => 'stop', 'message' => ['content' => '{"name":"Jane"}']]],
                'usage' => ['total_tokens' => 5],
            ] )
            ->ensure( 'structure' )
            ->structure( 'Extract', $schema );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( 'json_schema', $body['response_format']['type'] );
        } );

        $this->assertEquals( ['name' => 'Jane'], $response->structured() );
    }


    public function testNoApiKey() : void
    {
        $this->expectException( PrismaException::class );

        $this->prisma( 'text', 'azure', ['resource' => 'r'] );
    }


    public function testNoResource() : void
    {
        $this->expectException( PrismaException::class );

        $this->prisma( 'text', 'azure', ['api_key' => 'test'] );
    }
}
