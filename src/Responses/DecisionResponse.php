<?php

namespace Aimeos\Prisma\Responses;

use Aimeos\Prisma\Concerns\HasMeta;
use Aimeos\Prisma\Concerns\HasUsage;
use Aimeos\Prisma\Values\Answer;


/**
 * Decision response with one typed answer per question.
 *
 * @implements \IteratorAggregate<string, Answer>
 */
class DecisionResponse implements \IteratorAggregate, \JsonSerializable
{
    use HasMeta, HasUsage;


    /** @var array<string, Answer> */
    private array $answers = [];


    /**
     * Get the answer for the given question ID.
     *
     * @param string $id Question ID used in the request
     * @return Answer|null Answer or NULL if no answer was returned for the ID
     */
    public function answer( string $id ) : ?Answer
    {
        return $this->answers[$id] ?? null;
    }


    /**
     * Get all answers.
     *
     * @return array<string, Answer> Map of question ID to answer
     */
    public function answers() : array
    {
        return $this->answers;
    }


    /**
     * Create a decision response instance.
     *
     * @param array<int|string, array<string, mixed>> $answers Map of question ID to raw answer data
     * @return self DecisionResponse instance
     */
    public static function fromAnswers( array $answers ) : self
    {
        $instance = new self;

        foreach( $answers as $id => $answer ) {
            $instance->answers[(string) $id] = new Answer( $answer );
        }

        return $instance;
    }


    /**
     * Allows iterating over the answers.
     *
     * @return \ArrayIterator<string, Answer> Traversable map of question ID to answer
     */
    public function getIterator() : \Traversable
    {
        return new \ArrayIterator( $this->answers );
    }


    /**
     * Returns the response as a plain array for serialization.
     *
     * @return array<string, mixed> Response data
     */
    public function jsonSerialize() : array
    {
        return [
            'answers' => array_map( fn( Answer $answer ) => $answer->all(), $this->answers ),
            'usage' => $this->usage(),
            'meta' => $this->meta(),
        ];
    }


    final private function __construct()
    {
    }
}
