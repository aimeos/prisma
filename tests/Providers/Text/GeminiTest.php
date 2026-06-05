<?php

namespace Tests\Providers\Text;

use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Schema\Schema;
use PHPUnit\Framework\TestCase;
use Tests\MakesPrismaRequests;


class GeminiTest extends TestCase
{
    use MakesPrismaRequests;


    public function testWrite() : void
    {
        $response = $this->prisma( 'text', 'gemini', ['api_key' => 'test'] )
            ->response( json_encode( [
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'text' => 'Hello world'
                        ]]
                    ]
                ]]
            ] ) )
            ->ensure( 'write' )
            ->write( 'Say hello' );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals( 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash:generateContent', (string) $request->getUri() );
            $this->assertEquals( 'POST', $request->getMethod() );
            $this->assertEquals( 'test', $request->getHeaderLine( 'x-goog-api-key' ) );

            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( 'Say hello', $body['contents'][0]['parts'][0]['text'] );
            $this->assertEquals( ['TEXT'], $body['generationConfig']['responseModalities'] );
            $this->assertArrayNotHasKey( 'systemInstruction', $body );
        } );

        $this->assertEquals( 'Hello world', $response->text() );
        $this->assertEquals( ['Hello world'], $response->texts() );
    }


    public function testWriteWithFiles() : void
    {
        $response = $this->prisma( 'text', 'gemini', ['api_key' => 'test'] )
            ->response( json_encode( [
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'text' => 'An image of a cat'
                        ]]
                    ]
                ]]
            ] ) )
            ->ensure( 'write' )
            ->write( 'Describe this image', [Image::fromBinary( 'PNG', 'image/png' )] );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertCount( 2, $body['contents'][0]['parts'] );
            $this->assertArrayHasKey( 'inlineData', $body['contents'][0]['parts'][0] );
            $this->assertEquals( 'Describe this image', $body['contents'][0]['parts'][1]['text'] );
        } );

        $this->assertEquals( 'An image of a cat', $response->text() );
    }


    public function testWriteWithSystemPrompt() : void
    {
        $response = $this->prisma( 'text', 'gemini', ['api_key' => 'test'] )
            ->response( json_encode( [
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'text' => 'Bonjour'
                        ]]
                    ]
                ]]
            ] ) );

        $response->withSystemPrompt( 'Always respond in French' )
            ->write( 'Say hello' );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( 'Always respond in French', $body['systemInstruction']['parts'][0]['text'] );
        } );
    }


    public function testWriteWithOptions() : void
    {
        $response = $this->prisma( 'text', 'gemini', ['api_key' => 'test'] )
            ->response( json_encode( [
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'text' => 'result'
                        ]]
                    ]
                ]]
            ] ) )
            ->ensure( 'write' )
            ->withMaxTokens( 100 )
            ->write( 'prompt', [], ['temperature' => 0.5, 'unknown' => 'ignored'] );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( 0.5, $body['generationConfig']['temperature'] );
            $this->assertEquals( 100, $body['generationConfig']['maxOutputTokens'] );
            $this->assertArrayNotHasKey( 'unknown', $body['generationConfig'] );
        } );
    }


    public function testWriteWithCitations() : void
    {
        $response = $this->prisma( 'text', 'gemini', ['api_key' => 'test'] )
            ->response( json_encode( [
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'text' => 'Paris is the capital of France.'
                        ]]
                    ],
                    'groundingMetadata' => [
                        'groundingChunks' => [[
                            'web' => [
                                'uri' => 'https://example.com/france',
                                'title' => 'France Facts'
                            ]
                        ], [
                            'web' => [
                                'uri' => 'https://example.com/paris',
                                'title' => 'Paris Guide'
                            ]
                        ]],
                        'groundingSupports' => [[
                            'segment' => [
                                'startIndex' => 0,
                                'endIndex' => 30
                            ],
                            'groundingChunkIndices' => [0, 1]
                        ]]
                    ]
                ]]
            ] ) )
            ->write( 'What is the capital of France?' );

        $citations = $response->citations();
        $this->assertCount( 2, $citations );
        $this->assertEquals( 'France Facts', $citations[0]->title() );
        $this->assertEquals( 'https://example.com/france', $citations[0]->url() );
        $this->assertEquals( 'Paris is the capital of France', $citations[0]->text() );
        $this->assertNull( $citations[0]->source() );
        $this->assertEquals( 'Paris Guide', $citations[1]->title() );
        $this->assertEquals( 'https://example.com/paris', $citations[1]->url() );
        $this->assertEquals( 'Paris is the capital of France', $citations[1]->text() );
    }


    public function testWriteWithoutCitations() : void
    {
        $response = $this->prisma( 'text', 'gemini', ['api_key' => 'test'] )
            ->response( json_encode( [
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'text' => 'Hello'
                        ]]
                    ]
                ]]
            ] ) )
            ->write( 'Say hello' );

        $this->assertEmpty( $response->citations() );
    }


    public function testStructured() : void
    {
        $schema = Schema::for( 'person', [
            'name' => Schema::string(),
            'age' => Schema::integer(),
            'note' => Schema::string()->nullable(),
        ] );

        $response = $this->prisma( 'text', 'gemini', ['api_key' => 'test'] )
            ->response( json_encode( [
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'text' => '{"name":"John","age":30}'
                        ]]
                    ],
                    'finishReason' => 'STOP'
                ]],
                'usageMetadata' => ['totalTokenCount' => 15]
            ] ) )
            ->ensure( 'structure' )
            ->structure( 'Extract person info', $schema );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertStringContainsString( 'gemini-3.5-flash:generateContent', (string) $request->getUri() );
            $this->assertEquals( 'application/json', $body['generationConfig']['responseMimeType'] );
            $this->assertArrayHasKey( 'responseSchema', $body['generationConfig'] );
            $this->assertArrayNotHasKey( 'additionalProperties', $body['generationConfig']['responseSchema'] );
            // nullable expressed the OpenAPI 3.0 way: scalar type + "nullable": true (not a type array)
            $note = $body['generationConfig']['responseSchema']['properties']['note'];
            $this->assertEquals( 'string', $note['type'] );
            $this->assertTrue( $note['nullable'] );
        } );

        $this->assertEquals( ['name' => 'John', 'age' => 30], $response->structured() );
        $this->assertEquals( '{"name":"John","age":30}', $response->text() );
        $this->assertEquals( 15, $response->usage()['used'] );
    }


    public function testStructuredNullableEnum() : void
    {
        $schema = Schema::for( 'block', [
            'align' => Schema::string()->enum( ['start', 'center', 'end'] )->nullable(),
        ] );

        $this->prisma( 'text', 'gemini', ['api_key' => 'test'] )
            ->response( json_encode( [
                'candidates' => [[
                    'content' => ['parts' => [['text' => '{"align":"start"}']]],
                    'finishReason' => 'STOP'
                ]],
                'usageMetadata' => ['totalTokenCount' => 2]
            ] ) )
            ->ensure( 'structure' )
            ->structure( 'Pick alignment', $schema );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $align = $body['generationConfig']['responseSchema']['properties']['align'];

            // OpenAPI 3.0 way: scalar type + "nullable": true, with no null in the enum.
            $this->assertEquals( 'string', $align['type'] );
            $this->assertTrue( $align['nullable'] );
            $this->assertEquals( ['start', 'center', 'end'], $align['enum'] );
        } );
    }


    public function testStructuredStripsEmptyEnumValue() : void
    {
        $schema = Schema::for( 'block', [
            'header' => Schema::string()->enum( ['', 'h1', 'h2', 'h3'] )->nullable(),
        ] );

        $this->prisma( 'text', 'gemini', ['api_key' => 'test'] )
            ->response( json_encode( [
                'candidates' => [[
                    'content' => ['parts' => [['text' => '{"header":"h1"}']]],
                    'finishReason' => 'STOP'
                ]],
                'usageMetadata' => ['totalTokenCount' => 2]
            ] ) )
            ->ensure( 'structure' )
            ->structure( 'Pick header', $schema );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $header = $body['generationConfig']['responseSchema']['properties']['header'];

            // Gemini's OpenAPI subset rejects empty-string enum members ("enum[0]:
            // cannot be empty"), so the empty value is dropped from the enum.
            $this->assertEquals( ['h1', 'h2', 'h3'], $header['enum'] );
            $this->assertTrue( $header['nullable'] );
        } );
    }


    public function testStructuredWithAnyOf() : void
    {
        $schema = Schema::for( 'result', [
            'value' => Schema::anyOf( [
                Schema::string(),
                Schema::object( ['code' => Schema::integer()] ),
            ] ),
        ] );

        $this->prisma( 'text', 'gemini', ['api_key' => 'test'] )
            ->response( json_encode( [
                'candidates' => [[
                    'content' => ['parts' => [['text' => '{"value":"ok"}']]],
                    'finishReason' => 'STOP'
                ]],
                'usageMetadata' => ['totalTokenCount' => 5]
            ] ) )
            ->ensure( 'structure' )
            ->structure( 'Extract', $schema );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $value = $body['generationConfig']['responseSchema']['properties']['value'];
            // anyOf is kept; branches are reduced to the OpenAPI subset (no additionalProperties)
            $this->assertArrayHasKey( 'anyOf', $value );
            $this->assertEquals( 'string', $value['anyOf'][0]['type'] );
            $this->assertEquals( 'object', $value['anyOf'][1]['type'] );
            $this->assertArrayNotHasKey( 'additionalProperties', $value['anyOf'][1] );
        } );
    }


    public function testStructuredWithRefAndDefs() : void
    {
        $schema = Schema::for( 'person', [
            'address' => Schema::ref( 'Address' )->required(),
        ] )->def( 'Address', Schema::object( [
            'city' => Schema::string()->required(),
        ] ) );

        $this->prisma( 'text', 'gemini', ['api_key' => 'test'] )
            ->response( json_encode( [
                'candidates' => [[
                    'content' => ['parts' => [['text' => '{"address":{"city":"x"}}']]],
                    'finishReason' => 'STOP'
                ]],
                'usageMetadata' => ['totalTokenCount' => 5]
            ] ) )
            ->ensure( 'structure' )
            ->structure( 'Extract', $schema );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $json = $body['generationConfig']['responseSchema'];
            // $ref and $defs survive the OpenAPI-subset key filter
            $this->assertEquals( '#/$defs/Address', $json['properties']['address']['$ref'] );
            $this->assertArrayHasKey( 'Address', $json['$defs'] );
            $this->assertEquals( 'object', $json['$defs']['Address']['type'] );
        } );
    }


    public function testStructuredWithOptions() : void
    {
        $schema = Schema::for( 'person', [
            'name' => Schema::string(),
        ] );

        $response = $this->prisma( 'text', 'gemini', ['api_key' => 'test'] )
            ->response( json_encode( [
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'text' => '{"name":"Jane"}'
                        ]]
                    ]
                ]]
            ] ) )
            ->structure( 'Extract', $schema, [], ['temperature' => 0.2, 'unknown' => 'ignored'] );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( 0.2, $body['generationConfig']['temperature'] );
            $this->assertArrayNotHasKey( 'unknown', $body['generationConfig'] );
        } );

        $this->assertEquals( ['name' => 'Jane'], $response->structured() );
    }


    public function testStructuredWithFiles() : void
    {
        $schema = Schema::for( 'description', [
            'text' => Schema::string(),
        ] );

        $response = $this->prisma( 'text', 'gemini', ['api_key' => 'test'] )
            ->response( json_encode( [
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'text' => '{"text":"a cat"}'
                        ]]
                    ]
                ]]
            ] ) )
            ->structure( 'Describe', $schema, [Image::fromBinary( 'PNG', 'image/png' )] );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertCount( 2, $body['contents'][0]['parts'] );
            $this->assertArrayHasKey( 'inlineData', $body['contents'][0]['parts'][0] );
        } );

        $this->assertEquals( ['text' => 'a cat'], $response->structured() );
    }


    public function testWriteError() : void
    {
        $this->expectException( PrismaException::class );

        $this->prisma( 'text', 'gemini', ['api_key' => 'test'] )
            ->response( json_encode( ['error' => ['message' => 'Bad request']] ), status: 400, reason: 'Bad Request' )
            ->ensure( 'write' )
            ->write( 'prompt' );
    }




    public function testWriteToolLoopEmptyArgsIsObject() : void
    {
        $tool = \Aimeos\Prisma\Tools::make(
            'ping',
            'Returns pong',
            Schema::for( 'ping', [] ),
            fn() => 'pong'
        );

        $this->prisma( 'text', 'gemini', ['api_key' => 'test'] );

        // First response: model calls the tool without arguments ("args": {})
        $this->response( json_encode( [
            'candidates' => [[
                'content' => [
                    'role' => 'model',
                    'parts' => [[
                        'functionCall' => ['name' => 'ping', 'args' => (object) []]
                    ]]
                ]
            ]]
        ] ) );

        // Second response: model finishes after receiving the tool result
        $response = $this->response( json_encode( [
            'candidates' => [[
                'content' => [
                    'role' => 'model',
                    'parts' => [['text' => 'Done']]
                ]
            ]]
        ] ) );

        $response->withTools( [$tool] )
            ->withMaxSteps( 5 )
            ->ensure( 'write' )
            ->write( 'Ping the tool' );

        $requests = $this->requests();
        $this->assertCount( 2, $requests );

        // The resent model turn must carry an object args, not a JSON array
        $body = $requests[1]->getBody()->getContents();
        $this->assertStringContainsString( '"args":{}', $body );

        $decoded = json_decode( $body, true );
        $this->assertSame( [], $decoded['contents'][1]['parts'][0]['functionCall']['args'] );
    }


    public function testToolWithoutParametersOmitsParametersField() : void
    {
        $noArgs = \Aimeos\Prisma\Tools::make( 'ping', 'Returns pong', Schema::for( 'ping', [] ), fn() => 'pong' );
        $withArgs = \Aimeos\Prisma\Tools::make( 'greet', 'Greets', Schema::for( 'greet', ['name' => Schema::string()] ), fn() => 'hi' );

        $response = $this->prisma( 'text', 'gemini', ['api_key' => 'test'] )
            ->response( json_encode( [
                'candidates' => [[
                    'content' => ['role' => 'model', 'parts' => [['text' => 'Done']]]
                ]]
            ] ) );

        $response->withTools( [$noArgs, $withArgs] )
            ->ensure( 'write' )
            ->write( 'hello' );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $decls = $body['tools'][0]['functionDeclarations'];

            // Zero-argument tool: parameters omitted entirely (Gemini rejects empty object schemas)
            $this->assertEquals( 'ping', $decls[0]['name'] );
            $this->assertArrayNotHasKey( 'parameters', $decls[0] );

            // Tool with arguments: parameters included
            $this->assertEquals( 'greet', $decls[1]['name'] );
            $this->assertEquals( 'object', $decls[1]['parameters']['type'] );
            $this->assertArrayHasKey( 'name', $decls[1]['parameters']['properties'] );
        } );
    }


    public function testToolCallIdUsesNameWithNumberForRepeats() : void
    {
        $tool = \Aimeos\Prisma\Tools::make( 'ping', 'Returns pong', Schema::for( 'ping', [] ), fn() => 'pong' );

        $this->prisma( 'text', 'gemini', ['api_key' => 'test'] );

        // Model calls the same tool twice in one response
        $this->response( json_encode( [
            'candidates' => [[
                'content' => ['role' => 'model', 'parts' => [
                    ['functionCall' => ['name' => 'ping', 'args' => (object) []]],
                    ['functionCall' => ['name' => 'ping', 'args' => (object) []]]
                ]]
            ]]
        ] ) );

        $response = $this->response( json_encode( [
            'candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => 'Done']]]]]
        ] ) );

        $result = $response->withTools( [$tool] )
            ->withMaxSteps( 5 )
            ->ensure( 'write' )
            ->write( 'Ping twice' );

        // Gemini has no call id: the first call uses the name, repeats get a numbered suffix
        $steps = $result->steps();
        $this->assertCount( 2, $steps );
        $this->assertEquals( 'ping-1', $steps[0]->id() );
        $this->assertEquals( 'ping-2', $steps[1]->id() );
    }


    public function testToolResultListWrappedAsObject() : void
    {
        // Tool returns a JSON list, which Gemini's functionResponse.response (a Struct) rejects
        $tool = \Aimeos\Prisma\Tools::make(
            'list', 'Returns a list', Schema::for( 'list', [] ),
            fn() => json_encode( ['a', 'b'] )
        );

        $this->prisma( 'text', 'gemini', ['api_key' => 'test'] );

        $this->response( json_encode( [
            'candidates' => [[
                'content' => ['role' => 'model', 'parts' => [
                    ['functionCall' => ['name' => 'list', 'args' => (object) []]]
                ]]
            ]]
        ] ) );

        $response = $this->response( json_encode( [
            'candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => 'Done']]]]]
        ] ) );

        $response->withTools( [$tool] )
            ->withMaxSteps( 5 )
            ->ensure( 'write' )
            ->write( 'List things' );

        $requests = $this->requests();
        $this->assertCount( 2, $requests );

        // The resent tool result must be an object ({"result":[...]}), never a bare JSON array
        $body = json_decode( $requests[1]->getBody()->getContents(), true );
        $sent = $body['contents'][2]['parts'][0]['functionResponse']['response'];
        $this->assertSame( ['result' => ['a', 'b']], $sent );
    }


    public function testToolResultObjectWrappedAsObject() : void
    {
        $tool = \Aimeos\Prisma\Tools::make(
            'lookup', 'Returns an object', Schema::for( 'lookup', [] ),
            fn() => json_encode( ['city' => 'Paris'] )
        );

        $this->prisma( 'text', 'gemini', ['api_key' => 'test'] );

        $this->response( json_encode( [
            'candidates' => [[
                'content' => ['role' => 'model', 'parts' => [
                    ['functionCall' => ['name' => 'lookup', 'args' => (object) []]]
                ]]
            ]]
        ] ) );

        $response = $this->response( json_encode( [
            'candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => 'Done']]]]]
        ] ) );

        $response->withTools( [$tool] )
            ->withMaxSteps( 5 )
            ->ensure( 'write' )
            ->write( 'Look up' );

        $requests = $this->requests();
        $body = json_decode( $requests[1]->getBody()->getContents(), true );
        $sent = $body['contents'][2]['parts'][0]['functionResponse']['response'];
        $this->assertSame( ['result' => ['city' => 'Paris']], $sent );
    }


    public function testToolResultPlainStringWrappedAsObject() : void
    {
        $tool = \Aimeos\Prisma\Tools::make(
            'ping', 'Returns pong', Schema::for( 'ping', [] ),
            fn() => 'pong'
        );

        $this->prisma( 'text', 'gemini', ['api_key' => 'test'] );

        $this->response( json_encode( [
            'candidates' => [[
                'content' => ['role' => 'model', 'parts' => [
                    ['functionCall' => ['name' => 'ping', 'args' => (object) []]]
                ]]
            ]]
        ] ) );

        $response = $this->response( json_encode( [
            'candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => 'Done']]]]]
        ] ) );

        $response->withTools( [$tool] )
            ->withMaxSteps( 5 )
            ->ensure( 'write' )
            ->write( 'Ping' );

        $requests = $this->requests();
        $body = json_decode( $requests[1]->getBody()->getContents(), true );
        $sent = $body['contents'][2]['parts'][0]['functionResponse']['response'];
        $this->assertSame( ['result' => 'pong'], $sent );
    }


    public function testNoApiKey() : void
    {
        $this->expectException( PrismaException::class );

        $this->prisma( 'text', 'gemini', [] );
    }
}
