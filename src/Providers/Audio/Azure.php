<?php

namespace Aimeos\Prisma\Providers\Audio;

use Aimeos\Prisma\Contracts\Audio\Speak;
use Aimeos\Prisma\Contracts\Audio\Transcribe;
use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\Audio;
use Aimeos\Prisma\Providers\Base;
use Aimeos\Prisma\Responses\FileResponse;
use Aimeos\Prisma\Responses\TextResponse;


class Azure extends Base implements Speak, Transcribe
{
    public function __construct( array $config )
    {
        if( !isset( $config['api_key'] ) ) {
            throw new PrismaException( 'No API key' );
        }

        $region = $this->config( $config, 'region', 'eastus' );
        $url = $this->config( $config, 'url', 'https://' . $region . '.api.cognitive.microsoft.com' );

        $this->header( 'Ocp-Apim-Subscription-Key', $this->config( $config, 'api_key' ) );
        $this->baseUrl( rtrim( $url, '/' ) . '/' );
    }


    public function speak( string $text, ?string $voice = null, array $options = [] ) : FileResponse
    {
        $selected = $voice ?: 'en-US-AvaMultilingualNeural';
        $format = $this->config( $options, 'format', 'audio-24khz-48kbitrate-mono-mp3' );

        $response = $this->client()->post( 'tts/cognitiveservices/v1', [
            'headers' => [
                'Content-Type' => 'application/ssml+xml',
                'X-Microsoft-OutputFormat' => $format,
            ],
            'body' => $this->ssml( $text, $selected ),
        ] );

        $this->validate( $response );

        $mimetype = $response->getHeaderLine( 'Content-Type' ) ?: 'audio/mpeg';
        return FileResponse::fromBinary( $response->getBody()->getContents(), $mimetype );
    }


    public function transcribe( Audio $audio, ?string $lang = null, array $options = [] ) : TextResponse
    {
        $definition = $this->allowed( $options, ['channels', 'diarization', 'profanityFilterMode'] );

        if( $lang ) {
            $definition['locales'] = [$lang];
        }

        $request = $this->payload( ['definition' => json_encode( $definition ?: new \stdClass() )], ['audio' => $audio] );
        $response = $this->client()->post( 'speechtotext/transcriptions:transcribe?api-version=2024-11-15', ['multipart' => $request] );

        $this->validate( $response );

        /** @var array<string, mixed> */
        $data = $this->fromJson( $response );

        /** @var array<int, array<string, mixed>> */
        $combined = $data['combinedPhrases'] ?? [];
        $texts = array_map( fn( $item ) => is_string( $item['text'] ?? null ) ? $item['text'] : '', $combined );

        /** @var array<int, array<string, mixed>> */
        $phrases = $data['phrases'] ?? [];

        return TextResponse::fromText( implode( "\n", $texts ) )
            ->withStructured( $phrases )
            ->withMeta( array_intersect_key( $data, ['durationMilliseconds' => true] ) );
    }


    /**
     * Builds the SSML document for speech synthesis.
     *
     * Azure voice names start with their locale (e.g. "en-US-AvaMultilingualNeural"),
     * which is also used as language of the SSML document.
     *
     * @param string $text Plain text to synthesize
     * @param string $voice Azure voice name
     * @return string SSML document
     */
    private function ssml( string $text, string $voice ) : string
    {
        $lang = implode( '-', array_slice( explode( '-', $voice ), 0, 2 ) );
        $esc = fn( string $value ) => htmlspecialchars( $value, ENT_XML1 | ENT_QUOTES, 'UTF-8' );

        return '<speak version="1.0" xmlns="http://www.w3.org/2001/10/synthesis" xml:lang="' . $esc( $lang ) . '">'
            . '<voice name="' . $esc( $voice ) . '">' . $esc( $text ) . '</voice></speak>';
    }
}
