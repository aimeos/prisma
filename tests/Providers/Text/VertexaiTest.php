<?php

namespace Tests\Providers\Text;

use Aimeos\Prisma\Exceptions\PrismaException;
use PHPUnit\Framework\TestCase;
use Tests\MakesPrismaRequests;


class VertexaiTest extends TestCase
{
    use MakesPrismaRequests;


    public function testWrite() : void
    {
        $response = $this->prisma( 'text', 'vertexai', ['access_token' => 'tok', 'project_id' => 'proj', 'region' => 'us-central1'] )
            ->response( [
                'candidates' => [['content' => ['parts' => [['text' => 'Hello']]], 'finishReason' => 'STOP']],
                'usageMetadata' => ['totalTokenCount' => 5],
            ] )
            ->ensure( 'write' )
            ->write( 'Say hi' );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals(
                'https://us-central1-aiplatform.googleapis.com/v1/projects/proj/locations/us-central1/publishers/google/models/gemini-3.5-flash:generateContent',
                (string) $request->getUri()
            );
            $this->assertEquals( 'Bearer tok', $request->getHeaderLine( 'authorization' ) );

            // Vertex rejects a content turn without an explicit role; the user turn must carry one
            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( 'user', $body['contents'][0]['role'] );
        } );

        $this->assertEquals( 'Hello', $response->text() );
    }


    public function testWriteGlobalRegion() : void
    {
        $this->prisma( 'text', 'vertexai', ['access_token' => 'tok', 'project_id' => 'proj'] )
            ->response( [
                'candidates' => [['content' => ['parts' => [['text' => 'hi']]], 'finishReason' => 'STOP']],
                'usageMetadata' => ['totalTokenCount' => 2],
            ] )
            ->ensure( 'write' )
            ->write( 'hi' );

        $this->assertPrismaRequest( function( $request, $options ) {
            // no region falls back to the global host and "global" location
            $this->assertStringStartsWith(
                'https://aiplatform.googleapis.com/v1/projects/proj/locations/global/',
                (string) $request->getUri()
            );
        } );
    }


    public function testNoAccessToken() : void
    {
        $this->expectException( PrismaException::class );

        $this->prisma( 'text', 'vertexai', ['project_id' => 'proj'] );
    }


    public function testNoProjectId() : void
    {
        $this->expectException( PrismaException::class );

        $this->prisma( 'text', 'vertexai', ['access_token' => 'tok'] );
    }
}
