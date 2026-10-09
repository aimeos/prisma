<?php

namespace Aimeos\Prisma\Providers\Text;

use Aimeos\Prisma\Contracts\Text\Decide;
use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Providers\Base;
use Aimeos\Prisma\Responses\DecisionResponse;


class Typesafe extends Base implements Decide
{
    public function __construct( array $config )
    {
        if( !isset( $config['api_key'] ) ) {
            throw new PrismaException( 'No API key' );
        }

        $this->header( 'Content-Type', 'application/json' );
        $this->header( 'Authorization', 'Bearer ' . $this->config( $config, 'api_key' ) );
        $this->baseUrl( $this->config( $config, 'url', 'https://api.typesafe.ai' ) );
    }


    public function decide( string|array $state, array $questions, array $options = [] ) : DecisionResponse
    {
        $payload = [
            'state' => $state,
            'model' => $this->modelName( 'jev-latest' ),
            'questions' => (object) $questions, // keep numeric IDs as JSON object keys
        ];

        $response = $this->client()->post( '/v1/systemone', ['json' => $payload] );

        $this->validate( $response );

        $data = $this->fromJson( $response );
        /** @var array<string, mixed> $usage */
        $usage = is_array( $data['usage'] ?? null ) ? $data['usage'] : [];

        return DecisionResponse::fromAnswers( $this->answers( $data['answers'] ?? null ) )
            ->withUsage(
                ( is_numeric( $usage['input_tokens'] ?? null ) ? (float) $usage['input_tokens'] : 0 )
                + ( is_numeric( $usage['output_tokens'] ?? null ) ? (float) $usage['output_tokens'] : 0 ),
                $usage,
            )
            ->withMeta( $this->allowed( $data, ['model'] ) );
    }


    /**
     * Adds the answer value stored under the key named like the answer type as "value".
     *
     * @param mixed $answers Answers from the API response
     * @return array<int|string, array<string, mixed>> Map of question ID to answer data
     */
    private function answers( mixed $answers ) : array
    {
        $result = [];

        foreach( is_array( $answers ) ? $answers : [] as $id => $answer )
        {
            if( is_array( $answer ) )
            {
                $type = is_string( $answer['type'] ?? null ) ? $answer['type'] : '';
                $result[$id] = ['value' => $answer[$type] ?? null] + $answer;
            }
        }

        return $result;
    }
}
