<?php

namespace Aimeos\Prisma\Contracts\Text;

use Aimeos\Prisma\Responses\DecisionResponse;


interface Decide
{
    /**
     * Answers typed questions about the given state with probabilities.
     *
     * Each question is defined in the format of the provider, e.g. a yes/no question, a choice
     * between options or a rating along levels. Answers are returned under the same keys as the
     * questions.
     *
     * WARNING: Answers are probabilistic decisions, not facts. Gate consequential actions on
     * Answer::confidence() or the probabilities instead of the top answer alone. The state is
     * sent to the provider, so don't pass data the provider must not see.
     *
     * @param string|array<int|string, mixed> $state Text or structured data to evaluate
     * @param array<int|string, array<string, mixed>> $questions Map of question ID to question definition
     * @param array<string, mixed> $options Provider specific options
     * @return DecisionResponse Response with one answer per question
     */
    public function decide( string|array $state, array $questions, array $options = [] ) : DecisionResponse;
}
