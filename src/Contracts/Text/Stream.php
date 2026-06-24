<?php

namespace Aimeos\Prisma\Contracts\Text;

use Aimeos\Prisma\Responses\TextResponse;


interface Stream
{
    /**
     * Generates streamed text from the given prompt.
     *
     * Returns a TextResponse backed by a live stream: iterate TextResponse::stream() to
     * consume each chunk as it arrives - answer text deltas (string) and, for each executed
     * tool call, a \Aimeos\Prisma\Tools\Step twice (before execution with done() === false
     * and after with done() === true). A tool call that exhausted its configured limit is
     * not executed and is reported once (completed).
     *
     * The text accessors - text(), texts(), first() and iteration - drain the stream to
     * completion before returning the assembled answer. The metadata accessors - usage(),
     * steps(), citations(), reason() and meta() - return only what has been assembled so
     * far and do NOT drain, so consume the stream (iterate stream() or read a text accessor)
     * before reading them.
     *
     * Only answer text is streamed; reasoning/thinking is not yielded but remains available
     * on the response via meta().
     *
     * @param string $prompt Input prompt for text generation
     * @param array<int, \Aimeos\Prisma\Files\File> $files Files for multimodal input (images, audio, documents)
     * @param array<string, mixed> $options Provider specific options
     * @return TextResponse Streamed response text
     */
    public function stream( string $prompt, array $files = [], array $options = [] ) : TextResponse;
}
