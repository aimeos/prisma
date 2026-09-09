<?php

namespace Tests\Providers\Audio;

use Aimeos\Prisma\Exceptions\BadRequestException;
use Aimeos\Prisma\Files\Audio;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\MakesPrismaRequests;


class SharedTranscriptionTest extends TestCase
{
    use MakesPrismaRequests;


    #[DataProvider('descriptions')]
    public function testDescribe( string $provider, string $endpoint, string $model ) : void
    {
        $usage = ['total_tokens' => '12', 'prompt_tokens' => 4];
        $mock = $this->prisma( 'audio', $provider, ['api_key' => 'test'] );
        $mock->response( ['text' => 'Bonjour'] );
        $response = $mock->response( ['choices' => [['message' => ['content' => 'Greeting']], ['message' => ['content' => null]]], 'usage' => $usage] )
            ->describe( Audio::fromBinary( 'MP3', 'audio/mpeg' ), 'fr', ['temperature' => 0] );

        $requests = $this->requests();
        $this->assertCount( 2, $requests );
        $this->assertPrismaRequest( function( $request ) use ( $endpoint, $model ) {
            if( $request->getUri()->getPath() !== $endpoint ) {
                return false;
            }

            $this->assertSame( ['model' => $model, 'messages' => [[
                'role' => 'user',
                'content' => 'Summarize the text in a few words in plain text format in the language of ISO code "fr":' . "\nBonjour",
            ]]], json_decode( (string) $request->getBody(), true ) );
        } );

        $this->assertSame( ['Greeting', ''], $response->texts() );
        $this->assertSame( ['used' => 12.0] + $usage, $response->usage()->all() );
    }


    public static function descriptions() : array
    {
        return [
            'openai' => ['openai', '/v1/chat/completions', 'gpt-5.6-luna'],
            'groq' => ['groq', '/openai/v1/chat/completions', 'openai/gpt-oss-120b'],
            'mistral' => ['mistral', '/v1/chat/completions', 'mistral-large-latest'],
        ];
    }


    #[DataProvider('transcriptions')]
    public function testTranscribe( string $provider, string $format, array|string $body, ?string $text, array $segments, array $usage ) : void
    {
        $response = $this->prisma( 'audio', $provider, ['api_key' => 'test'] )->response( $body )
            ->transcribe( Audio::fromBinary( 'MP3', 'audio/mpeg' ), null, ['response_format' => $format] );

        $this->assertSame( $text, $response->text() );
        $this->assertSame( $segments, $response->structured() );
        $this->assertSame( $usage, $response->usage()->all() );
    }


    public static function transcriptions() : array
    {
        $segments = [['text' => 'Hello', 'start' => 0]];
        $cases = [
            'text' => ['text', 'Hello', 'Hello', [], []],
            'subtitles' => ['srt', "1\nHello", "1\nHello", [], []],
            'json' => ['json', ['text' => 'Hello'], 'Hello', [], ['used' => null]],
            'verbose' => ['verbose_json', ['text' => 'Hello', 'segments' => $segments, 'usage' => ['total_tokens' => '5']], 'Hello', $segments, ['used' => 5.0, 'total_tokens' => '5']],
            'missing' => ['json', [], null, [], ['used' => null]],
            'invalid values' => ['json', ['text' => 42, 'usage' => ['total_tokens' => 'unknown']], null, [], ['used' => null, 'total_tokens' => 'unknown']],
        ];
        $data = [];

        foreach( ['openai', 'groq'] as $provider ) {
            foreach( $cases as $name => $case ) {
                $data[$provider . ' ' . $name] = [$provider, ...$case];
            }
        }

        return $data;
    }


    #[DataProvider('providers')]
    public function testTranscriptionErrorStopsDescription( string $provider ) : void
    {
        $this->expectException( BadRequestException::class );
        $this->expectExceptionMessage( 'Invalid audio' );

        try {
            $this->prisma( 'audio', $provider, ['api_key' => 'test'] )
                ->response( ['message' => 'Invalid audio'], status: 400 )
                ->describe( Audio::fromBinary( 'MP3', 'audio/mpeg' ) );
        } finally {
            $this->assertCount( 1, $this->requests() );
        }
    }


    public static function providers() : array
    {
        return ['openai' => ['openai'], 'groq' => ['groq'], 'mistral' => ['mistral']];
    }
}
